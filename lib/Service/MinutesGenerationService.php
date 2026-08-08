<?php
/**
 * Decidesk Minutes Generation Service
 *
 * Service for generating initial minutes drafts from linked meeting data.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service that generates an initial Dutch minutes draft from linked meeting data.
 *
 * Fetches the linked Meeting's AgendaItems, Motions, VotingRounds, and Decisions via
 * OpenRegister ObjectService and renders them into a structured Dutch text template.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesGenerationService
{

    /**
     * Allowed lifecycle transitions: current state → next state.
     *
     * Only sequential single-step transitions are permitted to ensure
     * proper workflow enforcement (OWASP A04 — Insecure Design).
     *
     * @var array<string,string>
     */
    private const LIFECYCLE_TRANSITIONS = [
        'draft'    => 'review',
        'review'   => 'approved',
        'approved' => 'signed',
        'signed'   => 'published',
    ];

    /**
     * Constructor for MinutesGenerationService.
     *
     * @param ContainerInterface   $container The DI container (lazy-loads OpenRegister services)
     * @param LoggerInterface      $logger    The logger
     * @param MinutesDraftRenderer $renderer  Renders the gathered data into the Dutch template
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private MinutesDraftRenderer $renderer,
    ) {
    }//end __construct()

    /**
     * Generate an initial minutes draft for the given minutes record.
     *
     * Fetches the Minutes object, resolves its linked Meeting, and retrieves the
     * Meeting's AgendaItems (sorted by orderNumber), Motions, VotingRounds, and
     * Decisions. Renders these into a structured Dutch text template.
     *
     * @param string $minutesId UUID of the Minutes object
     *
     * @throws InvalidArgumentException When the Minutes object cannot be found
     * @throws RuntimeException         When OpenRegister is not available
     *
     * @return string The generated Dutch minutes text
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function generateDraft(string $minutesId): string
    {
        $objectService = $this->getObjectService();

        // Fetch the Minutes object.
        // setRegister/setSchema are called first so that OpenRegister's session-based
        // ACL is applied — any caller without read access gets null (same as MeetingService).
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            throw new InvalidArgumentException(
                sprintf('Minutes object with id "%s" not found.', $minutesId)
            );
        }

        $minutes = $minutesEntity->getObject();

        // Resolve the linked Meeting.
        $meeting = $this->resolveMeeting(minutes: $minutes, objectService: $objectService);

        if ($meeting === null) {
            throw new MissingRelationException(
                message: sprintf(
                    'No linked Meeting found for Minutes "%s". '
                    .'Please link a Meeting before generating a draft.',
                    $minutesId
                )
            );
        }

        // Fetch related entities for the Meeting.
        $meetingId    = $meeting['id'] ?? '';
        $agendaItems  = $this->fetchRelatedObjects(objectService: $objectService, schema: 'agenda-item', meetingId: $meetingId);
        // ADR-005: motions are `decision` objects selected by the decisionType
        // discriminator; the `motion` schema no longer exists.
        $motions      = $this->fetchRelatedObjects(
            objectService: $objectService,
            schema: DecisionSchema::SLUG,
            meetingId: $meetingId,
            extraFilters: DecisionSchema::filters(decisionType: DecisionSchema::MOTION)
        );
        $votingRounds = $this->fetchRelatedObjects(objectService: $objectService, schema: 'voting-round', meetingId: $meetingId);
        $decisions    = $this->fetchRelatedObjects(objectService: $objectService, schema: 'decision', meetingId: $meetingId);

        // Sort agenda items by orderNumber.
        usort(
            $agendaItems,
            static function (array $a, array $b): int {
                return ($a['orderNumber'] ?? 0) <=> ($b['orderNumber'] ?? 0);
            }
        );

        return $this->renderer->render(
            minutes: $minutes,
            meeting: $meeting,
            agendaItems: $agendaItems,
            motions: $motions,
            votingRounds: $votingRounds,
            decisions: $decisions
        );

    }//end generateDraft()

    /**
     * Transition a Minutes object to the next lifecycle state (server-side enforcement).
     *
     * Validates that the requested transition follows the allowed sequence
     * (draft → review → approved → signed → published). Populates server-side
     * fields: approvedAt for the "approved" transition and signedBy (with the
     * authenticated user's display name) for the "approved" and "signed" transitions.
     *
     * @param string $minutesId    UUID of the Minutes object
     * @param string $newLifecycle The target lifecycle state
     * @param string $displayName  Display name of the authenticated user (from server session)
     *
     * @throws InvalidArgumentException When the Minutes object is not found or the transition is invalid
     * @throws RuntimeException         When OpenRegister is not available
     *
     * @return array<string,mixed> The updated Minutes object data
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function transition(string $minutesId, string $newLifecycle, string $displayName): array
    {
        $objectService = $this->getObjectService();

        // SetRegister/setSchema are called first so that OpenRegister's session-based
        // ACL is applied — callers without access to this object get null (OWASP A01).
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            throw new MissingObjectException(
                message: sprintf('Minutes object "%s" not found.', $minutesId)
            );
        }

        $minutes = $minutesEntity->getObject();

        $currentLifecycle = $minutes['lifecycle'] ?? 'draft';

        if ((self::LIFECYCLE_TRANSITIONS[$currentLifecycle] ?? null) !== $newLifecycle) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid lifecycle transition: "%s" → "%s". Expected next state: "%s".',
                    $currentLifecycle,
                    $newLifecycle,
                    self::LIFECYCLE_TRANSITIONS[$currentLifecycle] ?? 'none'
                ),
                422
            );
        }

        $updated = array_merge($minutes, ['lifecycle' => $newLifecycle]);

        if ($newLifecycle === 'approved') {
            $updated['approvedAt'] = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
        }

        if (in_array($newLifecycle, ['approved', 'signed'], true) === true) {
            $updated['signedBy'] = $this->withSigner(minutes: $minutes, displayName: $displayName);
        }

        // Named arguments: the positional form ($object, 'decidesk', 'minutes', $id)
        // silently bound the register string to ObjectService::saveObject()'s second
        // parameter ($extend) — a latent pre-existing defect fixed here.
        $saved = $objectService->saveObject(object: $updated, register: 'decidesk', schema: 'minutes', uuid: $minutesId);

        return $this->normaliseSaved(saved: $saved, fallback: $updated);

    }//end transition()

    /**
     * Append the signing user to the Minutes signedBy list, without duplicates.
     *
     * @param array<string,mixed> $minutes     The current Minutes object data
     * @param string              $displayName Display name of the authenticated user
     *
     * @return array<int,string> The signedBy list including the signer
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function withSigner(array $minutes, string $displayName): array
    {
        $signers = [];
        if (is_array($minutes['signedBy'] ?? null) === true) {
            $signers = $minutes['signedBy'];
        }

        if (in_array($displayName, $signers, true) === false) {
            $signers[] = $displayName;
        }

        return $signers;

    }//end withSigner()

    /**
     * Normalise whatever OpenRegister returned from saveObject() to an array.
     *
     * @param mixed               $saved    The saveObject() return value
     * @param array<string,mixed> $fallback The locally-updated payload to fall back on
     *
     * @return array<string,mixed> The persisted object data
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function normaliseSaved(mixed $saved, array $fallback): array
    {
        if ($saved instanceof \stdClass === true || is_array($saved) === true) {
            return (array) $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return $fallback;

    }//end normaliseSaved()

    /**
     * Reject Minutes in review back to draft with a mandatory comment.
     *
     * The forward LIFECYCLE_TRANSITIONS map stays intact (signing/eIDAS flows
     * depend on it); rejection is the single guarded backward step the spec's
     * review loop requires: only `review` → `draft` is possible, the comment is
     * mandatory, and the rejection is recorded in `reviewComments` with
     * server-side author attribution so client-side forgery is impossible.
     *
     * @param string $minutesId UUID of the Minutes object
     * @param string $comment   The mandatory rejection comment
     * @param string $userId    Nextcloud UID of the rejecting user (from server session)
     *
     * @throws MissingObjectException    When the Minutes object is not found
     * @throws InvalidArgumentException When the comment is empty or lifecycle is not review
     * @throws RuntimeException         When OpenRegister is not available
     *
     * @return array<string,mixed> The updated Minutes object data
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     */
    public function reject(string $minutesId, string $comment, string $userId): array
    {
        if (trim($comment) === '') {
            throw new InvalidArgumentException('A rejection comment is required.', 422);
        }

        $objectService = $this->getObjectService();

        // SetRegister/setSchema first so OpenRegister's session-based ACL applies (OWASP A01).
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            throw new MissingObjectException(
                message: sprintf('Minutes object "%s" not found.', $minutesId)
            );
        }

        $minutes = $minutesEntity->getObject();

        if (($minutes['lifecycle'] ?? 'draft') !== 'review') {
            throw new InvalidArgumentException(
                'Only minutes in the "review" state can be rejected.',
                422
            );
        }

        $reviewComments = [];
        if (is_array($minutes['reviewComments'] ?? null) === true) {
            $reviewComments = $minutes['reviewComments'];
        }

        $reviewComments[] = [
            'action'    => 'rejected',
            'comment'   => trim($comment),
            'author'    => $userId,
            'createdAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        ];

        $updated = array_merge(
            $minutes,
            [
                'lifecycle'      => 'draft',
                'reviewComments' => $reviewComments,
            ]
        );

        $saved = $objectService->saveObject(object: $updated, register: 'decidesk', schema: 'minutes', uuid: $minutesId);

        return $this->normaliseSaved(saved: $saved, fallback: $updated);

    }//end reject()

    /**
     * Resolve the linked Meeting from the Minutes object.
     *
     * @param array<string,mixed> $minutes       The Minutes object data
     * @param object              $objectService The OpenRegister ObjectService instance
     *
     * @return array<string,mixed>|null The Meeting data array or null if not found
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function resolveMeeting(array $minutes, object $objectService): ?array
    {
        // Meeting may be embedded in relations or stored as a UUID reference.
        $meetingRelation = $minutes['relations']['meeting'] ?? $minutes['meeting'] ?? null;

        if ($meetingRelation === null) {
            return null;
        }

        // If it's already a fully-hydrated array with an id, return it directly.
        if (is_array($meetingRelation) === true) {
            if (isset($meetingRelation['id']) === true && $meetingRelation['id'] !== '') {
                // Already a hydrated meeting object — return it directly.
                return $meetingRelation;
            }

            // Array without a usable id — cannot resolve.
            return null;
        }

        // It's a UUID string reference — fetch the meeting.
        $meetingId = (string) $meetingRelation;

        if ($meetingId === '') {
            return null;
        }

        try {
            // Named arguments: the positional form ($id, null, 'decidesk', 'meeting')
            // silently bound the register string to ObjectService::find()'s third
            // parameter ($files) — a latent pre-existing defect fixed here.
            $meetingEntity = $objectService->find(
                id: $meetingId,
                register: 'decidesk',
                schema: 'meeting'
            );
            if ($meetingEntity === null) {
                return null;
            }

            return $meetingEntity->getObject();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Decidesk: Failed to fetch linked Meeting for minutes draft generation',
                ['exception' => $e->getMessage(), 'meetingId' => $meetingId]
            );
            // Re-throw as RuntimeException (503) so the caller distinguishes a transient
            // OpenRegister outage from a genuinely missing relation (null return above).
            throw new RuntimeException(
                'OpenRegister service is temporarily unavailable. Please try again later.',
                503,
                $e
            );
        }//end try

    }//end resolveMeeting()

    /**
     * Fetch ALL objects of a given type linked to a meeting using offset-based pagination.
     *
     * Iterates pages of 100 items until an empty page is returned, preventing silent
     * truncation for governance bodies with more than 100 items per meeting.
     *
     * @param object              $objectService The OpenRegister ObjectService instance
     * @param string              $schema        The schema slug
     * @param string              $meetingId     The meeting UUID for filtering
     * @param array<string,mixed> $extraFilters  Additional filters, e.g. the ADR-005 decisionType discriminator
     *
     * @return array<int,array<string,mixed>> Array of object data arrays
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function fetchRelatedObjects(object $objectService, string $schema, string $meetingId, array $extraFilters=[]): array
    {
        if ($meetingId === '') {
            return [];
        }

        $pageSize = 100;
        $offset   = 0;
        $result   = [];

        $objectService->setRegister('decidesk');
        $objectService->setSchema($schema);

        do {
            try {
                $entities = $objectService->findAll(
                        [
                            'filters' => array_merge(
                                $extraFilters,
                                [
                                    'register'           => 'decidesk',
                                    'schema'             => $schema,
                                    '_relations.meeting' => $meetingId,
                                ]
                            ),
                            'limit'   => $pageSize,
                            'offset'  => $offset,
                        ]
                        );
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Decidesk: Failed to fetch related objects for minutes draft',
                    ['schema' => $schema, 'meetingId' => $meetingId, 'offset' => $offset, 'exception' => $e->getMessage()]
                );
                break;
            }

            $page = [];
            foreach ($entities as $entity) {
                if (method_exists($entity, 'getObject') === true) {
                    $page[] = $entity->getObject();
                } else if (is_array($entity) === true) {
                    $page[] = $entity;
                }
            }

            $pageCount = count($page);
            $result    = array_merge($result, $page);
            $offset   += $pageSize;
        } while ($pageCount === $pageSize);

        return $result;

    }//end fetchRelatedObjects()

    /**
     * Lazy-load the OpenRegister ObjectService from the container.
     *
     * @throws RuntimeException When OpenRegister is not installed
     *
     * @return object The OpenRegister ObjectService instance
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'OpenRegister ObjectService is not available. '
                .'Please ensure the OpenRegister app is installed and enabled.',
                0,
                $e
            );
        }

    }//end getObjectService()
}//end class
