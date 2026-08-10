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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidesk\Lifecycle\MotionLifecycleTransitioner;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service handling motion lifecycle transitions, co-signatory management,
 * budget impact notes, amendment conflict detection, and amendment application.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * The amendment side of a motion.
     *
     * @var MotionAmendmentService
     */
    private readonly MotionAmendmentService $amendments;

    /**
     * The forward-to-another-body path.
     *
     * @var MotionForwardingService
     */
    private readonly MotionForwardingService $forwarding;

    /**
     * Construct the MotionService.
     *
     * The amendment collaborator is built in the constructor body rather than
     * injected, so the DI signature stays the three services below.
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
        $this->amendments = new MotionAmendmentService(container: $container, logger: $logger);
        $this->forwarding = new MotionForwardingService(container: $container, userManager: $userManager);

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
     * Transition a motion- or amendment-typed Decision to a new lifecycle state.
     *
     * Delegates to MotionLifecycleTransitioner, which owns the transition
     * tables, the co-signer gate, the ADR-005 outcome axis and the
     * terminal-completeness check. The seam stays here because every caller and
     * every test addresses the state machine through MotionService; only the
     * implementation moved.
     *
     * Controllers that expose this via HTTP must call their own
     * requireChairOrSecretary() guard BEFORE invoking this method and pass the
     * authenticated UID as actorId.
     *
     * ADR-005: `$outcome` carries the vote result — `adopted` or `rejected` —
     * which is a value of `Decision.outcome`, NOT a lifecycle state. It is
     * required when, and only when, the transition enters a terminal outcome
     * state (`decided | enacted | archived`); passing it on an in-flight
     * transition is a caller error and is refused, because an in-flight motion
     * has no result yet and recording one would be a lie about what happened.
     *
     * @param string      $objectId   UUID of the motion- or amendment-typed Decision
     * @param string      $objectType ADR-005 decisionType discriminator: 'motion' or 'amendment'
     * @param string      $newState   Target lifecycle state (Decision.lifecycle vocabulary)
     * @param string      $actorId    Nextcloud user UID performing the transition, or 'system' for internal calls
     * @param string|null $outcome    Vote result ('adopted'|'rejected'), terminal states only
     *
     * @throws InvalidArgumentException When the transition, actor, co-signer count or outcome is refused
     * @throws RuntimeException         When the object cannot be found or saved
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function transitionLifecycle(
        string $objectId,
        string $objectType,
        string $newState,
        string $actorId,
        ?string $outcome=null,
    ): void {
        $this->container->get(MotionLifecycleTransitioner::class)->transition(
            objectId: $objectId,
            objectType: $objectType,
            newState: $newState,
            actorId: $actorId,
            outcome: $outcome,
        );

    }//end transitionLifecycle()

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
        $objectService->setSchema('decision');

        $motionData = $this->findMotionData(objectService: $objectService, motionId: $motionId);
        $title      = $motionData['title'] ?? 'Motie';
        $pendingSignerUids = [];

        foreach ($participantIds as $participantId) {
            try {
                $nextcloudUserId = $this->coSignerUid(
                    objectService: $objectService,
                    participantId: $participantId
                );
                if ($nextcloudUserId === null) {
                    continue;
                }

                $pendingSignerUids[] = $nextcloudUserId;

                $this->getNotifier()->notify(
                    userId: $nextcloudUserId,
                    motionId: $motionId,
                    subject: 'co_sign_request',
                    parameters: ['motionTitle' => $title, 'motionId' => $motionId],
                    failureLog: "Decidesk: Could not send co-sign notification to $nextcloudUserId: "
                );
            } catch (Throwable $e) {
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
            $objectService->setSchema('decision');
            $objectService->saveObject(
                object: array_merge($motionData, ['pendingCoSignerUids' => array_values($existing)]),
                register: 'decidesk',
                schema: 'decision',
                uuid: $motionId,
            );
        }

    }//end requestCoSignature()

    /**
     * Resolve the Nextcloud UID of a participant invited to co-sign.
     *
     * Prefers the stored nextcloudUserId and falls back to an email lookup,
     * which only resolves when it matches exactly one account.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $participantId UUID of the Participant
     *
     * @return string|null The Nextcloud UID, or null when it cannot be resolved.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    private function coSignerUid(object $objectService, string $participantId): ?string
    {
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $participant = $objectService->find($participantId);
        if ($participant === null) {
            return null;
        }

        $participantData = $participant->getObject();
        $nextcloudUserId = ($participantData['nextcloudUserId'] ?? null);
        if ($nextcloudUserId !== null) {
            return (string) $nextcloudUserId;
        }

        $email = ($participantData['email'] ?? null);
        if ($email === null) {
            return null;
        }

        $users = $this->userManager->getByEmail($email);
        if (count($users) !== 1) {
            return null;
        }

        return (string) $users[0]->getUID();

    }//end coSignerUid()

    /**
     * Append a co-signer display name to a Motion's coSigners array.
     *
     * Idempotent: if the name is already present, no duplicate is added.
     * Saves the updated Motion via ObjectService.
     *
     * @param string $motionId     UUID of the Motion
     * @param string $coSignerName Display name of the confirming co-signer
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function addCoSigner(string $motionId, string $coSignerName): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');

        $motionData = $this->findMotionData(objectService: $objectService, motionId: $motionId);
        $coSigners  = $motionData['coSigners'] ?? [];

        if (in_array($coSignerName, $coSigners, true) === false) {
            $coSigners[] = $coSignerName;
            $objectService->saveObject(
                object: array_merge($motionData, ['coSigners' => $coSigners]),
                register: 'decidesk',
                schema: 'decision',
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
        $objectService->setSchema('decision');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            return false;
        }

        $motionData = $motionObject->getObject();
        if (($motionData['decisionType'] ?? null) !== 'motion') {
            return false;
        }

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
        $objectService->setSchema('decision');

        $motionData = $this->findMotionData(objectService: $objectService, motionId: $motionId);
        $notes      = $motionData['notes'] ?? [];

        $budgetPayload = json_encode(
            [
                'budgetLine'  => $budgetLine,
                'amountDelta' => $amountDelta,
                'rationale'   => $rationale,
            ]
        );
        if ($budgetPayload === false) {
            throw new RuntimeException('JSON encoding of budget impact failed: '.json_last_error_msg());
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
            schema: 'decision',
            uuid: $motionId,
        );

    }//end saveBudgetImpact()

    /**
     * Load a motion decision by id, refusing anything that is not one.
     *
     * ADR-005 folded the `motion` schema into `decision`, so a lookup by id no
     * longer proves the object is a motion — the `decisionType` discriminator
     * does. A miss raises the same RuntimeException the schema-scoped lookup
     * used to raise, which the controllers map to 404.
     *
     * @param object $objectService The OpenRegister ObjectService, already scoped to the decision schema
     * @param string $motionId      UUID of the motion decision
     *
     * @throws RuntimeException When no motion decision carries that id
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return array<string, mixed> The serialized motion decision
     */
    private function findMotionData(object $objectService, string $motionId): array
    {
        $motionObject = $objectService->find($motionId);
        $motionData   = [];
        if ($motionObject !== null) {
            $motionData = $motionObject->getObject();
        }

        if ($motionObject === null
            || ($motionData['decisionType'] ?? null) !== 'motion'
        ) {
            throw new RuntimeException("Motion $motionId not found");
        }

        return $motionData;

    }//end findMotionData()

    /**
     * Resolve every Amendment that belongs to a Motion.
     *
     * Delegates to MotionAmendmentService, which honours BOTH link shapes (the
     * flat `amends` property and a structured `relations` entry) and
     * dedups by id.
     *
     * @param string $motionId UUID of the parent Motion
     *
     * @return array<int, array<string, mixed>> Serialized amendment objects
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function getAmendmentsForMotion(string $motionId): array
    {
        return $this->amendments->getAmendmentsForMotion(motionId: $motionId);

    }//end getAmendmentsForMotion()

    /**
     * Stamp the parliamentary voting order on a motion's amendments.
     *
     * @param string        $motionId            UUID of the parent Motion
     * @param array<string> $orderedAmendmentIds The amendment UUIDs in voting order
     * @param string        $actorId             The Nextcloud user ID of the chair
     *
     * @return array<int, array<string, mixed>> The reordered amendments
     *
     * @throws InvalidArgumentException When an id does not belong to the motion
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function setAmendmentVotingOrder(string $motionId, array $orderedAmendmentIds, string $actorId): array
    {
        return $this->amendments->setAmendmentVotingOrder(
            motionId: $motionId,
            orderedAmendmentIds: $orderedAmendmentIds,
            actorId: $actorId
        );

    }//end setAmendmentVotingOrder()

    /**
     * Flag amendments that overlap with a newly submitted one.
     *
     * @param string $motionId       UUID of the parent Motion
     * @param string $newAmendmentId UUID of the newly submitted Amendment
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $this->amendments->detectConflicts(motionId: $motionId, newAmendmentId: $newAmendmentId);

    }//end detectConflicts()

    /**
     * Merge an adopted amendment into its parent motion's text.
     *
     * @param string $motionId    UUID of the parent Motion
     * @param string $amendmentId UUID of the adopted Amendment
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $this->amendments->applyAmendment(motionId: $motionId, amendmentId: $amendmentId);

    }//end applyAmendment()

    /**
     * Forward a motion to a target governance body with optional approval workflow.
     *
     * Checks the actor's role against the motion_forwarding_roles config. Creates a new
     * Motion in the target body and stores a relation between the forwarded and source
     * motions. If approval is required, the forwarded Motion is created with lifecycle
     * 'proposed' (ADR-005 Decision vocabulary) and a notification is sent to the target chair.
     *
     * @param string $motionId      The motion UUID to forward
     * @param string $targetBodyId  The target governance body UUID
     * @param string $actorId       The Nextcloud user ID of the person forwarding
     * @param string $justification The reason for forwarding
     *
     * @return array<string,mixed> The created forwarded Motion object
     *
     * @throws RuntimeException When role is not authorized or motion is not found
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function forwardMotion(string $motionId, string $targetBodyId, string $actorId, string $justification): array
    {
        return $this->forwarding->forward(
            motionId: $motionId,
            targetBodyId: $targetBodyId,
            actorId: $actorId,
            justification: $justification
        );

    }//end forwardMotion()
}//end class
