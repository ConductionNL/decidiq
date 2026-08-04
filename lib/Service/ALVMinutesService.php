<?php

/**
 * Decidesk ALV Minutes Service
 *
 * Service for generating ALV (Algemene Ledenvergadering) minutes templates
 * and distributing them to members.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Exception;
use OCA\Decidesk\Exception\MissingObjectException;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that generates ALV-specific Dutch minutes templates.
 *
 * Generates minutes for Algemene Ledenvergadering (general assemblies) with
 * quorum statements, member rolls, and formal resolution language. Handles
 * distribution of approved minutes to active members via notifications.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 */
class ALVMinutesService
{
    /**
     * ALV Dutch minutes template.
     *
     * @var string
     */
    private const ALV_TEMPLATE = <<<'TEMPLATE'
{title}

Datum: {date}
Locatie: {location}

Aanwezigen:
{presentCount} van de {totalCount} leden
Status quorum: {quorumStatus}

Agendapunten:
{agendaItems}

Resoluties en Stemming:
{resolutions}

Rondvraag en Afsluiting:
{aob}

Notulen opgesteld door: {secretary}
Notulen goedgekeurd door: {chair}
TEMPLATE;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate an ALV draft based on the Minutes and linked Meeting.
     *
     * Fetches the Minutes and linked Meeting, validates that meetingType
     * contains 'alv', fetches AgendaItems and active Participants of the
     * linked GovernanceBody, and renders the ALV Dutch template.
     *
     * @param string $minutesId The Minutes ID
     *
     * @return array{
     *   content: string,
     *   recipientCount: int
     * } Generated content and recipient count
     *
     * @throws MissingObjectException If Minutes or Meeting not found
     * @throws Exception If meeting is not ALV type
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    public function generateALVDraft(string $minutesId): array
    {
        try {
            return $this->buildALVDraft(minutesId: $minutesId);
        } catch (Exception $e) {
            $this->logger->error("ALVMinutesService::generateALVDraft failed: ".$e->getMessage());
            throw $e;
        }

    }//end generateALVDraft()

    /**
     * Distribute approved minutes to all active members.
     *
     * Fetches the Minutes (must be in approved or signed state), fetches
     * active Participants of the linked GovernanceBody, and sends Nextcloud
     * notifications to each with minutes title and deep link.
     *
     * @param string $minutesId The Minutes ID
     *
     * @return int The count of notifications sent
     *
     * @throws MissingObjectException If Minutes not found
     * @throws Exception If lifecycle is not approved or signed
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    public function distribute(string $minutesId): int
    {
        try {
            return $this->distributeApproved(minutesId: $minutesId);
        } catch (Exception $e) {
            $this->logger->error("ALVMinutesService::distribute failed: ".$e->getMessage());
            throw $e;
        }

    }//end distribute()

    /**
     * Assemble the ALV draft (the body of generateALVDraft).
     *
     * @param string $minutesId The Minutes ID
     *
     * @return array{content: string, recipientCount: int} Generated content and recipient count
     *
     * @throws MissingObjectException If Minutes or Meeting not found
     * @throws Exception If no Meeting is linked or the meeting is not ALV type
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function buildALVDraft(string $minutesId): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $minutes = $this->loadObject(objectService: $objectService, objectId: $minutesId, schema: 'minutes');
        if ($minutes === null) {
            throw new MissingObjectException(message: "Minutes not found: $minutesId");
        }

        $meetingId = $this->relatedId(object: $minutes, relationKey: 'Meeting');
        if ($meetingId === null) {
            throw new Exception('No Meeting linked to Minutes');
        }

        $meeting = $this->loadObject(objectService: $objectService, objectId: $meetingId, schema: 'meeting');
        if ($meeting === null) {
            throw new MissingObjectException(message: "Meeting not found: $meetingId");
        }

        $this->assertAlvMeeting(meeting: $meeting);

        $participants = $this->activeParticipants(
            objectService: $objectService,
            bodyId: $this->relatedId(object: $meeting, relationKey: 'GovernanceBody')
        );

        // Count active members.
        $memberCount  = count($participants);
        $presentCount = $memberCount;
        if (empty($meeting['quorumRequired']) === false) {
            $presentCount = min($memberCount, $meeting['quorumRequired']);
        }

        $content = $this->renderAlvTemplate(
            minutes: $minutes,
            meeting: $meeting,
            agendaText: $this->agendaText(objectService: $objectService, meetingId: $meetingId),
            presentCount: $presentCount,
            memberCount: $memberCount
        );

        $this->logger->info("ALV draft generated for minutes $minutesId");

        return [
            'content'        => $content,
            'recipientCount' => $memberCount,
        ];

    }//end buildALVDraft()

    /**
     * Distribute the minutes (the body of distribute()).
     *
     * @param string $minutesId The Minutes ID
     *
     * @return int The count of notifications sent
     *
     * @throws MissingObjectException If Minutes not found
     * @throws Exception If lifecycle is not approved or signed
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function distributeApproved(string $minutesId): int
    {
        $objectService       = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $notificationService = $this->container->get('OpenRegisterNotificationService');

        $minutes = $this->loadObject(objectService: $objectService, objectId: $minutesId, schema: 'minutes');
        if ($minutes === null) {
            throw new MissingObjectException(message: "Minutes not found: $minutesId");
        }

        // Validate lifecycle.
        $lifecycle = ($minutes['lifecycle'] ?? null);
        if ($lifecycle !== 'approved' && $lifecycle !== 'signed') {
            throw new Exception("Minutes must be approved or signed before distribution (current: $lifecycle)", 403);
        }

        $participants = $this->activeParticipants(
            objectService: $objectService,
            bodyId: $this->governanceBodyIdForMinutes(objectService: $objectService, minutes: $minutes)
        );

        // Resolve Nextcloud UID for each participant and send notifications.
        $userManager = $this->container->get(IUserManager::class);
        $sentCount   = 0;
        foreach ($participants as $participant) {
            $ncUid = $this->resolveParticipantUid(userManager: $userManager, participant: $participant);
            if ($ncUid === null) {
                $this->logger->warning(
                    'ALVMinutesService: cannot resolve Nextcloud UID for participant',
                    ['participant' => ($participant['displayName'] ?? '?')]
                );
                continue;
            }

            $sentCount += $this->notifyParticipant(
                notificationService: $notificationService,
                ncUid: $ncUid,
                minutes: $minutes,
                minutesId: $minutesId
            );
        }//end foreach

        $this->logger->info("ALV minutes distributed to $sentCount participants");

        return $sentCount;

    }//end distributeApproved()

    /**
     * Assert the meeting is an Algemene Ledenvergadering.
     *
     * @param array<string,mixed> $meeting The Meeting object
     *
     * @return void
     *
     * @throws Exception When the meeting is not an ALV.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function assertAlvMeeting(array $meeting): void
    {
        $meetingType = strtolower($meeting['meetingType'] ?? '');
        if (strpos($meetingType, 'alv') === false && strpos($meetingType, 'algemene-ledenvergadering') === false) {
            throw new Exception("Meeting is not an ALV (type: $meetingType)", 422);
        }

    }//end assertAlvMeeting()

    /**
     * Render the ALV Dutch template.
     *
     * @param array<string,mixed> $minutes      The Minutes object
     * @param array<string,mixed> $meeting      The Meeting object
     * @param string              $agendaText   The formatted agenda item list
     * @param int                 $presentCount The number of members counted present
     * @param int                 $memberCount  The total number of active members
     *
     * @return string The rendered minutes content.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function renderAlvTemplate(
        array $minutes,
        array $meeting,
        string $agendaText,
        int $presentCount,
        int $memberCount,
    ): string {
        // Determine quorum status.
        $quorumStatus = "Quorum niet bereikt ($presentCount/$memberCount leden)";
        if ($presentCount >= ($meeting['quorumRequired'] ?? 0)) {
            $quorumStatus = "Quorum bereikt ($presentCount/$memberCount leden)";
        }

        $searchKeys = [
            '{title}',
            '{date}',
            '{location}',
            '{presentCount}',
            '{totalCount}',
            '{quorumStatus}',
            '{agendaItems}',
            '{resolutions}',
            '{secretary}',
            '{chair}',
            '{aob}',
        ];

        return str_replace(
            $searchKeys,
            [
                $minutes['title'] ?? 'Algemene Ledenvergadering',
                $meeting['scheduledDate'] ?? date('d-m-Y'),
                $meeting['location'] ?? '',
                $presentCount,
                $memberCount,
                $quorumStatus,
                trim($agendaText),
                '[Resoluties met stemming in aparte tabel]',
                '[Secretaris naam]',
                '[Voorzitter naam]',
                '[Rondvraag: geen bijzonderheden / gesloten om ...]',
            ],
            self::ALV_TEMPLATE
        );

    }//end renderAlvTemplate()

    /**
     * Fetch and format the meeting's agenda items.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $meetingId     The Meeting ID
     *
     * @return string The formatted agenda item list.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function agendaText(object $objectService, string $meetingId): string
    {
        $objectService->setRegister('decidesk');
        $objectService->setSchema('agenda-item');
        $agendaItemEntities = $objectService->findAll(
            [
                'filters' => [
                    '_relations.meeting' => $meetingId,
                    '_limit'             => 999,
                    '_order'             => 'orderNumber:ASC',
                ],
            ]
        );

        $agendaText = '';
        foreach ($agendaItemEntities as $entity) {
            $item        = $entity->jsonSerialize();
            $agendaText .= sprintf(
                "- %s: %s\n",
                $item['orderNumber'] ?? '',
                $item['title'] ?? 'Untitled'
            );
        }

        return $agendaText;

    }//end agendaText()

    /**
     * Fetch the active participants of a governance body.
     *
     * @param object      $objectService The OpenRegister ObjectService
     * @param string|null $bodyId        The GovernanceBody ID, or null
     *
     * @return array<int, array<string,mixed>> The active participants (empty when no body).
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function activeParticipants(object $objectService, ?string $bodyId): array
    {
        if ($bodyId === null) {
            return [];
        }

        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $participantEntities = $objectService->findAll(
            [
                'filters' => [
                    'leftAt'                     => null,
                    '_limit'                     => 999,
                    '_relations.governance-body' => $bodyId,
                ],
            ]
        );

        return array_map(static fn($e) => $e->jsonSerialize(), $participantEntities);

    }//end activeParticipants()

    /**
     * Resolve the GovernanceBody behind a Minutes object, via its Meeting.
     *
     * @param object              $objectService The OpenRegister ObjectService
     * @param array<string,mixed> $minutes       The Minutes object
     *
     * @return string|null The GovernanceBody ID, or null when it cannot be resolved.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function governanceBodyIdForMinutes(object $objectService, array $minutes): ?string
    {
        $meetingId = $this->relatedId(object: $minutes, relationKey: 'Meeting');
        if ($meetingId === null) {
            return null;
        }

        $meeting = $this->loadObject(objectService: $objectService, objectId: $meetingId, schema: 'meeting');
        if ($meeting === null) {
            return null;
        }

        return $this->relatedId(object: $meeting, relationKey: 'GovernanceBody');

    }//end governanceBodyIdForMinutes()

    /**
     * Resolve the Nextcloud UID of one participant.
     *
     * Prefers the stored nextcloudUserId and falls back to an email lookup.
     *
     * @param object              $userManager The Nextcloud user manager
     * @param array<string,mixed> $participant The Participant object
     *
     * @return string|null The Nextcloud UID, or null when it cannot be resolved.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function resolveParticipantUid(object $userManager, array $participant): ?string
    {
        $ncUid = ($participant['nextcloudUserId'] ?? null);
        if (empty($ncUid) === false) {
            return (string) $ncUid;
        }

        $email = ($participant['email'] ?? null);
        if (empty($email) === true) {
            return null;
        }

        $users = $userManager->getByEmail(email: $email);
        if (empty($users) === true) {
            return null;
        }

        return (string) array_values($users)[0]->getUID();

    }//end resolveParticipantUid()

    /**
     * Send the publication notification to one member (fail-soft).
     *
     * @param object              $notificationService The OpenRegister notification service
     * @param string              $ncUid               The recipient's Nextcloud UID
     * @param array<string,mixed> $minutes             The Minutes object
     * @param string              $minutesId           The Minutes ID
     *
     * @return int 1 when the notification was sent, 0 when it failed.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function notifyParticipant(
        object $notificationService,
        string $ncUid,
        array $minutes,
        string $minutesId,
    ): int {
        try {
            $notificationService->sendNotification(
                userId: $ncUid,
                title: 'Notulen gepubliceerd: '.($minutes['title'] ?? 'Untitled'),
                message: 'De notulen zijn nu beschikbaar.',
                deepLink: "/minutes/$minutesId"
            );

            return 1;
        } catch (Exception $e) {
            $this->logger->warning("Failed to send notification to $ncUid: ".$e->getMessage());

            return 0;
        }

    }//end notifyParticipant()

    /**
     * Pick a single related object id out of a relations map.
     *
     * Handles both the scalar and the list shape OpenRegister returns.
     *
     * @param array<string,mixed> $object      The object carrying the relations
     * @param string              $relationKey The relation key, e.g. 'Meeting'
     *
     * @return string|null The related id, or null when absent.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function relatedId(array $object, string $relationKey): ?string
    {
        $related = ($object['relations'][$relationKey] ?? null);
        if (is_array($related) === true) {
            $related = ($related[0] ?? null);
        }

        if (empty($related) === true) {
            return null;
        }

        return (string) $related;

    }//end relatedId()

    /**
     * Load a decidesk object as an array.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $objectId      The object id
     * @param string $schema        The schema slug
     *
     * @return array<string,mixed>|null The object, or null when absent.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    private function loadObject(object $objectService, string $objectId, string $schema): ?array
    {
        $entity = $objectService->find(id: $objectId, register: 'decidesk', schema: $schema);
        if ($entity === null) {
            return null;
        }

        return $entity->jsonSerialize();

    }//end loadObject()
}//end class
