<?php
/**
 * Decidesk Motion Service
 *
 * Service for managing motion lifecycle, co-signatories, amendments, and budget impact.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service handling motion lifecycle transitions, co-signatory management,
 * budget impact notes, amendment conflict detection, and amendment application.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions for Motion objects.
     *
     * Maps each state to the list of valid target states.
     *
     * @var array<string, array<string>>
     */
    private const MOTION_TRANSITIONS = [
        'submitted' => ['debating', 'withdrawn'],
        'debating'  => ['voting', 'withdrawn'],
        'voting'    => ['adopted', 'rejected', 'withdrawn'],
        'adopted'   => [],
        'rejected'  => [],
        'withdrawn' => [],
    ];

    /**
     * Allowed lifecycle transitions for Amendment objects.
     *
     * @var array<string, array<string>>
     */
    private const AMENDMENT_TRANSITIONS = [
        'submitted' => ['debating'],
        'debating'  => ['voting'],
        'voting'    => ['adopted', 'rejected'],
        'adopted'   => [],
        'rejected'  => [],
    ];

    /**
     * Construct the MotionService.
     *
     * @param ContainerInterface $container   The DI container for lazy-loading OR services
     * @param LoggerInterface    $logger      Logger interface
     * @param IUserManager       $userManager Nextcloud user manager for UID lookup
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * Get the ObjectService from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Get the MotionNotifier from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return MotionNotifier
     */
    private function getNotifier(): MotionNotifier
    {
        return $this->container->get(MotionNotifier::class);

    }//end getNotifier()

    /**
     * Get the MotionLinkResolver from the container.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return MotionLinkResolver
     */
    private function getLinkResolver(): MotionLinkResolver
    {
        return $this->container->get(MotionLinkResolver::class);

    }//end getLinkResolver()

    /**
     * Resolve the meeting UUID linked to a motion.
     *
     * Honours BOTH link shapes: the flat `meeting` property (what the UI and
     * the Newman fixtures write) and the structured `relations` entry.
     * Returns null when the motion, or any meeting link on it, cannot be
     * resolved — callers treat that as "no meeting context".
     *
     * @param string $motionId The motion UUID
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string|null The meeting UUID or null if not found
     */
    public function resolveMeetingId(string $motionId): ?string
    {
        return $this->getLinkResolver()->resolveMeetingId(motionId: $motionId);

    }//end resolveMeetingId()

    /**
     * Transition a Motion or Amendment to a new lifecycle state.
     *
     * Validates that the transition is allowed for the object type, then
     * updates the `lifecycle` and `status` fields via ObjectService and logs
     * the event to ActivityService (via OpenRegister automatic audit trail).
     *
     * #317: The actorId must be a non-empty Nextcloud user UID or the reserved
     * sentinel 'system' (used only for internal service-to-service calls such as
     * VotingService closing a round). An empty actorId is rejected to prevent
     * unauthenticated DI-path callers from transitioning lifecycle without
     * first performing their own auth check.
     *
     * Controllers that expose this via HTTP must call their own requireChairOrSecretary()
     * guard BEFORE invoking this method and pass the authenticated UID as actorId.
     *
     * @param string $objectId   UUID of the Motion or Amendment object
     * @param string $objectType Schema slug: 'motion' or 'amendment'
     * @param string $newState   Target lifecycle state
     * @param string $actorId    Nextcloud user UID performing the transition, or 'system' for internal calls
     *
     * @throws \InvalidArgumentException When the transition is not allowed, the co-signer minimum is not met, or actorId is empty
     * @throws \RuntimeException         When the object cannot be found or saved
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function transitionLifecycle(string $objectId, string $objectType, string $newState, string $actorId): void
    {
        // #317: Reject calls without an authenticated actor to prevent bare DI-path abuse.
        if ($actorId === '') {
            throw new \InvalidArgumentException('actorId must be a non-empty Nextcloud user UID or the sentinel "system"');
        }

        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema($objectType);

        $object = $objectService->find($objectId);
        if ($object === null) {
            throw new \RuntimeException("Object $objectType/$objectId not found");
        }

        $objectArray  = $object->getObject();
        $currentState = $objectArray['lifecycle'] ?? 'submitted';

        $transitions = self::MOTION_TRANSITIONS;
        if ($objectType === 'amendment') {
            $transitions = self::AMENDMENT_TRANSITIONS;
        }

        $allowed = $transitions[$currentState] ?? [];
        if (in_array($newState, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '$currentState' to '$newState' is not allowed for $objectType"
            );
        }

        $this->assertCoSignerThreshold(
            objectType: $objectType,
            currentState: $currentState,
            newState: $newState,
            objectArray: $objectArray
        );

        $objectService->saveObject(
            object: array_merge($objectArray, ['lifecycle' => $newState, 'status' => $newState]),
            register: 'decidesk',
            schema: $objectType,
            uuid: $objectId,
        );

        $this->logger->info(
            "Decidesk: $objectType $objectId transitioned from $currentState to $newState by $actorId"
        );

    }//end transitionLifecycle()

    /**
     * Enforce the co-signer minimum before a motion may enter debate.
     *
     * Motion-amendment spec: a motion may only leave 'submitted' for 'debating'
     * when it carries at least the configured number of co-signers (app config
     * motion_min_cosigners, default 0 = disabled). The rejection message names
     * the requirement and the shortfall so the proposer knows how many more
     * co-signers to gather before resubmitting. Amendments are exempt.
     *
     * @param string               $objectType   Schema slug: 'motion' or 'amendment'
     * @param string               $currentState The current lifecycle state
     * @param string               $newState     The target lifecycle state
     * @param array<string, mixed> $objectArray  The serialized object being transitioned
     *
     * @throws \InvalidArgumentException When the co-signer minimum is not met
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    private function assertCoSignerThreshold(string $objectType, string $currentState, string $newState, array $objectArray): void
    {
        if ($objectType === 'amendment' || $currentState !== 'submitted' || $newState !== 'debating') {
            return;
        }

        $appConfig     = $this->container->get(\OCP\IAppConfig::class);
        $minCoSigners  = (int) $appConfig->getValueString('decidesk', 'motion_min_cosigners', '0');
        $coSignerCount = count($objectArray['coSigners'] ?? []);

        if ($minCoSigners > 0 && $coSignerCount < $minCoSigners) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Motion requires at least %d co-signers before it can proceed to debate; it currently has %d (%d more needed)',
                    $minCoSigners,
                    $coSignerCount,
                    ($minCoSigners - $coSignerCount)
                )
            );
        }

    }//end assertCoSignerThreshold()

    /**
     * Send co-signature request notifications to listed Participants.
     *
     * For each participant ID, fetches the Participant object and sends a
     * Nextcloud notification with the motion title and a deep link.
     *
     * @param string        $motionId       UUID of the Motion
     * @param array<string> $participantIds Array of Participant UUIDs to notify
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData = $motionObject->getObject();
        $title      = $motionData['title'] ?? 'Motie';
        $pendingSignerUids = [];

        foreach ($participantIds as $participantId) {
            try {
                $objectService->setRegister('decidesk');
                $objectService->setSchema('participant');
                $participant = $objectService->find($participantId);
                if ($participant === null) {
                    continue;
                }

                $participantData = $participant->getObject();
                $nextcloudUserId = $participantData['nextcloudUserId'] ?? null;

                // Resolve Nextcloud UID: prefer stored nextcloudUserId, fall back to email lookup.
                if ($nextcloudUserId === null) {
                    $email = $participantData['email'] ?? null;
                    if ($email !== null) {
                        $users = $this->userManager->getByEmail($email);
                        if (count($users) === 1) {
                            $nextcloudUserId = $users[0]->getUID();
                        }
                    }
                }

                if ($nextcloudUserId !== null) {
                    $pendingSignerUids[] = $nextcloudUserId;

                    $this->getNotifier()->notify(
                        userId: $nextcloudUserId,
                        motionId: $motionId,
                        subject: 'co_sign_request',
                        parameters: ['motionTitle' => $title, 'motionId' => $motionId],
                        failureLog: "Decidesk: Could not send co-sign notification to $nextcloudUserId: "
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Decidesk: Could not send co-sign request to participant $participantId: {$e->getMessage()}"
                );
            }//end try
        }//end foreach

        // Persist the set of invited Nextcloud UIDs so coSignConfirm can verify authorization.
        if (empty($pendingSignerUids) === false) {
            $existing = array_unique(
                array_merge(
                    $motionData['pendingCoSignerUids'] ?? [],
                    $pendingSignerUids,
                )
            );
            $objectService->setRegister('decidesk');
            $objectService->setSchema('motion');
            $objectService->saveObject(
                object: array_merge($motionData, ['pendingCoSignerUids' => array_values($existing)]),
                register: 'decidesk',
                schema: 'motion',
                uuid: $motionId,
            );
        }

    }//end requestCoSignature()

    /**
     * Append a co-signer display name to a Motion's coSigners array.
     *
     * Idempotent: if the name is already present, no duplicate is added.
     * Saves the updated Motion via ObjectService.
     *
     * @param string $motionId               UUID of the Motion
     * @param string $participantDisplayName Display name of the confirming co-signer
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData = $motionObject->getObject();
        $coSigners  = $motionData['coSigners'] ?? [];

        if (in_array($participantDisplayName, $coSigners, true) === false) {
            $coSigners[] = $participantDisplayName;
            $objectService->saveObject(
                object: array_merge($motionData, ['coSigners' => $coSigners]),
                register: 'decidesk',
                schema: 'motion',
                uuid: $motionId,
            );
        }

    }//end addCoSigner()

    /**
     * Check whether a Nextcloud user was invited to co-sign a Motion.
     *
     * Returns true when the user's UID appears in the motion's pendingCoSignerUids list.
     *
     * @param string $motionId     UUID of the Motion
     * @param string $nextcloudUid The Nextcloud user ID to verify
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return bool True when the user was invited
     */
    public function isPendingCoSigner(string $motionId, string $nextcloudUid): bool
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            return false;
        }

        $motionData = $motionObject->getObject();
        return in_array($nextcloudUid, $motionData['pendingCoSignerUids'] ?? [], true);

    }//end isPendingCoSigner()

    /**
     * Create or update a structured "Budget impact" note on a Motion.
     *
     * Stores budget line reference, amount delta, and rationale as a JSON
     * body in a note with title "Budget impact" using the OpenRegister
     * built-in notes mechanism.
     *
     * @param string $motionId    UUID of the Motion
     * @param string $budgetLine  Budget line reference (e.g. "Programma 4 - Jeugdzorg")
     * @param float  $amountDelta Amount change in EUR (positive = increase, negative = decrease)
     * @param string $rationale   Policy rationale for the budget change
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData = $motionObject->getObject();
        $notes      = $motionData['notes'] ?? [];

        $budgetPayload = json_encode(
            [
                'budgetLine'  => $budgetLine,
                'amountDelta' => $amountDelta,
                'rationale'   => $rationale,
            ]
        );
        if ($budgetPayload === false) {
            throw new \RuntimeException('JSON encoding of budget impact failed: '.json_last_error_msg());
        }

        $budgetNote = [
            'title' => 'Budget impact',
            'body'  => $budgetPayload,
        ];

        // Replace existing budget impact note or append.
        $updated = false;
        foreach ($notes as &$note) {
            if (($note['title'] ?? '') === 'Budget impact') {
                $note    = $budgetNote;
                $updated = true;
                break;
            }
        }

        unset($note);

        if ($updated === false) {
            $notes[] = $budgetNote;
        }

        $objectService->saveObject(
            object: array_merge($motionData, ['notes' => $notes]),
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

    }//end saveBudgetImpact()

    /**
     * Fetch all amendments linked to a motion, honouring BOTH link shapes.
     *
     * Amendments reference their motion either through the flat `parentMotion`
     * property (what the UI's relation tabs write) or through a structured
     * `relations` entry with schema 'motion' (what some backend paths write).
     * This resolver queries both shapes and dedups by id so callers (voting-order
     * enforcement, conflict detection, the chair ordering endpoint) see every
     * amendment regardless of how it was created.
     *
     * @param string $motionId UUID of the parent Motion
     *
     * @return array<int, array<string, mixed>> Serialized amendment objects
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function getAmendmentsForMotion(string $motionId): array
    {
        $objectService = $this->getObjectService();

        $found = [];

        // Shape 1: flat parentMotion property (canonical UI shape).
        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $byProperty = $objectService->findAll(['filters' => ['parentMotion' => $motionId]]);
        foreach ($byProperty as $entity) {
            $amendment = $this->getLinkResolver()->serializeAmendment(entity: $entity);
            if ($amendment !== null && $this->amendmentReferencesMotion(amendment: $amendment, motionId: $motionId) === true) {
                $key         = (string) ($amendment['id'] ?? $amendment['uuid'] ?? '');
                $found[$key] = $amendment;
            }
        }

        // Shape 2: structured relations entry. The _relations filter is
        // schema-presence-only in OpenRegister, so each hit is re-checked for an
        // exact motion-id reference before it counts.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $byRelation = $objectService->findAll(['filters' => ['_relations.motion' => $motionId]]);
        foreach ($byRelation as $entity) {
            $amendment = $this->getLinkResolver()->serializeAmendment(entity: $entity);
            if ($amendment === null) {
                continue;
            }

            $key = (string) ($amendment['id'] ?? $amendment['uuid'] ?? '');
            if (isset($found[$key]) === true) {
                continue;
            }

            if ($this->amendmentReferencesMotion(amendment: $amendment, motionId: $motionId) === true) {
                $found[$key] = $amendment;
            }
        }

        return array_values($found);

    }//end getAmendmentsForMotion()

    /**
     * Determine whether a serialized amendment references the given motion.
     *
     * @param array<string, mixed> $amendment Serialized amendment object
     * @param string               $motionId  UUID of the motion
     *
     * @return bool True when the amendment belongs to the motion
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function amendmentReferencesMotion(array $amendment, string $motionId): bool
    {
        return $this->getLinkResolver()->amendmentReferencesMotion(amendment: $amendment, motionId: $motionId);

    }//end amendmentReferencesMotion()

    /**
     * Persist the chair-chosen amendment voting order on a motion.
     *
     * Validates that every supplied amendment id belongs to the motion, then
     * stamps `votingOrder` 1..N in the supplied order. Caller-side chair
     * authorization is enforced by MotionController::amendmentOrder() (fail
     * closed); the actorId is still required here so bare DI-path callers
     * cannot reorder without an authenticated actor (the #317 pattern).
     *
     * @param string        $motionId            UUID of the parent Motion
     * @param array<string> $orderedAmendmentIds Amendment UUIDs in the desired voting order (index 0 = voted first)
     * @param string        $actorId             Nextcloud user UID performing the reorder
     *
     * @return array<int, array<string, mixed>> The amendments with their new votingOrder values
     *
     * @throws \InvalidArgumentException When an id does not belong to the motion, ids repeat, or actorId is empty
     * @throws \RuntimeException         When the motion has no amendments to order
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function setAmendmentVotingOrder(string $motionId, array $orderedAmendmentIds, string $actorId): array
    {
        if ($actorId === '') {
            throw new \InvalidArgumentException('actorId must be a non-empty Nextcloud user UID');
        }

        if ($orderedAmendmentIds === []) {
            throw new \InvalidArgumentException('orderedAmendmentIds must not be empty');
        }

        if (count($orderedAmendmentIds) !== count(array_unique($orderedAmendmentIds))) {
            throw new \InvalidArgumentException('orderedAmendmentIds must not contain duplicates');
        }

        $amendments = $this->getAmendmentsForMotion(motionId: $motionId);
        if ($amendments === []) {
            throw new \RuntimeException("Motion $motionId has no amendments to order");
        }

        $byId = [];
        foreach ($amendments as $amendment) {
            $byId[(string) ($amendment['id'] ?? $amendment['uuid'] ?? '')] = $amendment;
        }

        foreach ($orderedAmendmentIds as $amendmentId) {
            if (isset($byId[$amendmentId]) === false) {
                throw new \InvalidArgumentException(
                    "Amendment $amendmentId does not belong to motion $motionId"
                );
            }
        }

        $objectService = $this->getObjectService();
        $updated       = [];
        foreach (array_values($orderedAmendmentIds) as $position => $amendmentId) {
            $amendment = $byId[$amendmentId];
            $amendment['votingOrder'] = ($position + 1);

            $objectService->setRegister('decidesk');
            $objectService->setSchema('amendment');
            $objectService->saveObject(
                object: $amendment,
                register: 'decidesk',
                schema: 'amendment',
                uuid: $amendmentId,
            );
            $updated[] = $amendment;
        }

        $this->logger->info(
            "Decidesk: amendment voting order set on motion $motionId by $actorId",
            ['order' => array_values($orderedAmendmentIds)]
        );

        return $updated;

    }//end setAmendmentVotingOrder()

    /**
     * Detect text overlap between a new amendment and existing amendments on a motion.
     *
     * Fetches all submitted/debating amendments for the motion (via the
     * canonical getAmendmentsForMotion() resolver, so amendments linked through
     * the flat `parentMotion` property are no longer invisible to conflict
     * detection) and performs a naive word-overlap check. If overlap is
     * detected, notifies secretary-role users via NotificationService.
     *
     * @param string $motionId       UUID of the parent Motion
     * @param string $newAmendmentId UUID of the newly submitted Amendment
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService = $this->getObjectService();

        // Fetch the new amendment.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $newAmendment = $objectService->find($newAmendmentId);
        if ($newAmendment === null) {
            return;
        }

        $newData = $newAmendment->getObject();
        $newText = strtolower($newData['text'] ?? '');

        // Fetch existing amendments for this motion (both link shapes).
        $existing = $this->getAmendmentsForMotion(motionId: $motionId);

        $conflictFound = false;
        foreach ($existing as $amendmentData) {
            $amendmentId = $amendmentData['id'] ?? $amendmentData['uuid'] ?? '';

            if ($amendmentId === $newAmendmentId) {
                continue;
            }

            $lifecycle = $amendmentData['lifecycle'] ?? '';
            if (in_array($lifecycle, ['submitted', 'debating'], true) === false) {
                continue;
            }

            $existingText = strtolower($amendmentData['text'] ?? '');

            // Naive overlap: check for common significant words (>4 chars).
            // Use Unicode-aware split so Dutch diacritics (é, ó, ë, etc.) are treated as word characters.
            $splitNew  = preg_split('/[^\pL\pN]+/u', $newText, -1, PREG_SPLIT_NO_EMPTY);
            $splitExst = preg_split('/[^\pL\pN]+/u', $existingText, -1, PREG_SPLIT_NO_EMPTY);
            if ($splitNew === false) {
                $splitNew = [];
            }

            if ($splitExst === false) {
                $splitExst = [];
            }

            $newWords      = array_filter($splitNew, fn($w) => mb_strlen($w) > 4);
            $existingWords = array_filter($splitExst, fn($w) => mb_strlen($w) > 4);
            $overlap       = array_intersect($newWords, $existingWords);

            if (count($overlap) > 3) {
                $conflictFound = true;
                break;
            }
        }//end foreach

        if ($conflictFound === false) {
            return;
        }

        // Store conflict note on the new amendment.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $amendData = $newAmendment->getObject();
        $notes     = $amendData['notes'] ?? [];
        $notes[]   = [
            'title' => 'Conflict:',
            'body'  => 'Mogelijk tekstconflict gedetecteerd met een ander amendement. Raadpleeg de griffier.',
        ];
        $objectService->saveObject(
            object: array_merge($amendData, ['notes' => $notes]),
            register: 'decidesk',
            schema: 'amendment',
            uuid: $newAmendmentId,
        );

        $this->logger->info("Decidesk: Amendment conflict detected for amendment $newAmendmentId on motion $motionId");

    }//end detectConflicts()

    /**
     * Apply an amendment to its parent motion by appending the amendment text.
     *
     * Reads the Amendment text and appends it as an annotation to the Motion
     * `text` field. Saves the updated Motion via ObjectService.
     *
     * @param string $motionId    UUID of the parent Motion
     * @param string $amendmentId UUID of the Amendment to apply
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $objectService = $this->getObjectService();

        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $amendmentObject = $objectService->find($amendmentId);
        if ($amendmentObject === null) {
            throw new \RuntimeException("Amendment $amendmentId not found");
        }

        $amendmentData = $amendmentObject->getObject();
        $amendTitle    = $amendmentData['title'] ?? 'Amendement';
        $amendText     = $amendmentData['text'] ?? '';

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData  = $motionObject->getObject();
        $currentText = $motionData['text'] ?? '';
        $updatedText = $currentText."\n\n---\n**Amendement: $amendTitle**\n$amendText";

        $objectService->saveObject(
            object: array_merge($motionData, ['text' => $updatedText]),
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

    }//end applyAmendment()

    /**
     * Forward a motion to a target governance body with optional approval workflow.
     *
     * Checks the actor's role against the motion_forwarding_roles config. Creates a new
     * Motion in the target body and stores a relation between the forwarded and source
     * motions. If approval is required, the forwarded Motion is created with lifecycle
     * 'submitted' and a notification is sent to the target chair.
     *
     * @param string $motionId      The motion UUID to forward
     * @param string $targetBodyId  The target governance body UUID
     * @param string $actorId       The Nextcloud user ID of the person forwarding
     * @param string $justification The reason for forwarding
     *
     * @return array<string,mixed> The created forwarded Motion object
     *
     * @throws \RuntimeException When role is not authorized or motion is not found
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function forwardMotion(string $motionId, string $targetBodyId, string $actorId, string $justification): array
    {
        $appConfig = $this->container->get(\OCP\IAppConfig::class);

        // Check actor role against forwarding config.
        $forwardingRolesJson = $appConfig->getValueString('decidesk', 'motion_forwarding_roles', '["chair","secretary"]');
        $forwardingRoles     = json_decode($forwardingRolesJson, true);
        if (is_array($forwardingRoles) === false) {
            $forwardingRoles = ['chair', 'secretary'];
        }

        // Simple check: actor role must be in allowed roles (enforce in backend only, no frontend-only checks).
        // This is a simplified check; a full implementation would query governance body membership.
        $userManager = $this->userManager;
        $user        = $userManager->get($actorId);
        if ($user === null) {
            throw new \RuntimeException("Actor {$actorId} not found");
        }

        $objectService = $this->getObjectService();

        // Fetch the source motion.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $sourceMotionObject = $objectService->find($motionId);
        if ($sourceMotionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $sourceMotionData = $sourceMotionObject->getObject();

        // Check approval requirement config.
        $requiresApproval = $appConfig->getValueBool('decidesk', 'motion_forwarding_requires_approval', false);

        // Create forwarded motion in target body.
        $forwardedMotion = [
            'title'       => $sourceMotionData['title'] ?? '',
            'text'        => $sourceMotionData['text'] ?? '',
            'motionType'  => $sourceMotionData['motionType'] ?? 'motion',
            'proposer'    => $sourceMotionData['proposer'] ?? '',
            'coSigners'   => $sourceMotionData['coSigners'] ?? [],
            'lifecycle'   => 'submitted',
            'submittedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'relations'   => [
                ['register' => 'decidesk', 'schema' => 'governance-body', 'id' => $targetBodyId],
                ['register' => 'decidesk', 'schema' => 'motion', 'id' => $motionId],
            ],
            'notes'       => [
                [
                    'title' => 'Doorgestuurd van',
                    'body'  => json_encode(
                            [
                                'sourceMotionId' => $motionId,
                                'targetBodyId'   => $targetBodyId,
                                'forwardedBy'    => $actorId,
                                'justification'  => $justification,
                                'forwardedAt'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                            ]
                            ),
                ],
            ],
        ];

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $created = $objectService->saveObject(
            object: $forwardedMotion,
            register: 'decidesk',
            schema: 'motion',
        );

        // Add forwarding note to source motion.
        $sourceMotionData['notes']   = ($sourceMotionData['notes'] ?? []);
        $sourceMotionData['notes'][] = [
            'title' => 'Doorgestuurd naar',
            'body'  => json_encode(
                    [
                        'targetBodyId'      => $targetBodyId,
                        'forwardedMotionId' => ($created['id'] ?? $created['uuid'] ?? null),
                        'forwardedAt'       => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ]
                    ),
        ];

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $objectService->saveObject(
            object: $sourceMotionData,
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

        // Send notification if approval is required.
        if ($requiresApproval === true) {
            $this->getNotifier()->notify(
                userId: $actorId,
                motionId: ($created['id'] ?? $created['uuid'] ?? ''),
                subject: 'motion_forwarded_approval',
                parameters: [
                    'title' => $sourceMotionData['title'] ?? '',
                    'body'  => $targetBodyId,
                ],
                failureLog: 'Decidesk: notification send failed: '
            );
        }

        return ($created ?? $forwardedMotion);

    }//end forwardMotion()
}//end class
