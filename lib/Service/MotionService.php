<?php

/**
 * Decidesk Motion Service
 *
 * Business logic for motion lifecycle management, co-signature collection,
 * amendment conflict detection, and budget impact storage.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service implementing motion governance business rules.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions for Motion objects.
     *
     * @var array<string, string[]>
     */
    private const MOTION_TRANSITIONS = [
        'submitted' => ['debating', 'withdrawn'],
        'debating'  => ['voting', 'withdrawn'],
        'voting'    => ['adopted', 'rejected'],
        'adopted'   => [],
        'rejected'  => [],
        'withdrawn' => [],
    ];

    /**
     * Allowed lifecycle transitions for Amendment objects.
     *
     * @var array<string, string[]>
     */
    private const AMENDMENT_TRANSITIONS = [
        'submitted' => ['debating'],
        'debating'  => ['voting'],
        'voting'    => ['adopted', 'rejected'],
        'adopted'   => [],
        'rejected'  => [],
    ];

    /**
     * Constructor for MotionService.
     *
     * @param ContainerInterface $container The DI container (to resolve OpenRegister services lazily)
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the OpenRegister ObjectService from the container.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Resolve the OpenRegister NotificationService from the container.
     *
     * @return object
     */
    private function notificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');

    }//end notificationService()

    /**
     * Transition the lifecycle of a Motion or Amendment object.
     *
     * Validates that the transition is allowed, then saves the updated status
     * via ObjectService. The ActivityService audit trail is automatic via OpenRegister.
     *
     * @param string $objectId   The OpenRegister object UUID
     * @param string $objectType Either "motion" or "amendment"
     * @param string $newState   The target lifecycle state
     * @param string $actorId    Nextcloud UID of the actor performing the transition
     *
     * @return void
     *
     * @throws \InvalidArgumentException When transition is not allowed
     * @throws \RuntimeException         When the object cannot be found
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function transitionLifecycle(string $objectId, string $objectType, string $newState, string $actorId): void
    {
        $objectService = $this->objectService();
        $object        = $objectService->getObject(register: 'decidesk', schema: $objectType, uuid: $objectId);

        if ($object === null) {
            throw new \RuntimeException("Object {$objectType}:{$objectId} not found");
        }

        $currentState = ($object['lifecycle'] ?? 'submitted');

        if ($objectType === 'amendment') {
            $allowedMap = self::AMENDMENT_TRANSITIONS;
        } else {
            $allowedMap = self::MOTION_TRANSITIONS;
        }

        $allowed = ($allowedMap[$currentState] ?? []);

        if (in_array($newState, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '{$currentState}' to '{$newState}' is not allowed for {$objectType}"
            );
        }

        $object['lifecycle'] = $newState;
        $object['status']    = $newState;

        $objectService->saveObject(register: 'decidesk', schema: $objectType, object: $object);

        $this->logger->info(
            'Decidesk: lifecycle transitioned',
            ['objectId' => $objectId, 'type' => $objectType, 'from' => $currentState, 'to' => $newState, 'actor' => $actorId]
        );

    }//end transitionLifecycle()

    /**
     * Request co-signature from one or more Participants.
     *
     * Sends a Nextcloud notification to each participant via NotificationService.
     *
     * @param string   $motionId       The motion UUID
     * @param string[] $participantIds Nextcloud UIDs of the invited co-signers
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->objectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $notificationService = $this->notificationService();

        foreach ($participantIds as $uid) {
            try {
                $notificationService->createNotification(
                    userId: $uid,
                    app: 'decidesk',
                    subject: 'co_sign_request',
                    subjectParameters: [
                        'motionId'    => $motionId,
                        'motionTitle' => ($motion['title'] ?? ''),
                    ],
                    object: 'motion',
                    objectId: $motionId
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: failed to send co-sign notification',
                    ['uid' => $uid, 'motionId' => $motionId, 'error' => $e->getMessage()]
                );
            }
        }

    }//end requestCoSignature()

    /**
     * Confirm co-signature: append participant display name to the motion's coSigners array.
     *
     * Idempotent — if the name is already present, no duplicate is added.
     *
     * @param string $motionId               The motion UUID
     * @param string $participantDisplayName The display name of the co-signer
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): void
    {
        $objectService = $this->objectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $coSigners = ($motion['coSigners'] ?? []);

        if (in_array($participantDisplayName, $coSigners, true) === false) {
            $coSigners[]         = $participantDisplayName;
            $motion['coSigners'] = $coSigners;
            $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);
        }

    }//end addCoSigner()

    /**
     * Store budget impact details as a structured note on the Motion.
     *
     * Creates or updates a note with title "Budget impact" containing a JSON body
     * with budgetLine, amountDelta, and rationale.
     *
     * @param string $motionId    The motion UUID
     * @param string $budgetLine  The budget line reference
     * @param float  $amountDelta The amount change (positive = increase, negative = decrease)
     * @param string $rationale   Policy rationale for the budget change
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void
    {
        $objectService = $this->objectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $notes      = ($motion['notes'] ?? []);
        $budgetNote = [
            'title' => 'Budget impact',
            'body'  => json_encode(
                    [
                        'budgetLine'  => $budgetLine,
                        'amountDelta' => $amountDelta,
                        'rationale'   => $rationale,
                    ]
                    ),
        ];

        // Update existing budget impact note or append new one.
        $found = false;
        foreach ($notes as $idx => $note) {
            if (($note['title'] ?? '') === 'Budget impact') {
                $notes[$idx] = $budgetNote;
                $found       = true;
                break;
            }
        }

        if ($found === false) {
            $notes[] = $budgetNote;
        }

        $motion['notes'] = $notes;
        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

    }//end saveBudgetImpact()

    /**
     * Detect text overlaps between a new amendment and existing amendments on the same motion.
     *
     * Uses naive word-overlap check. Notifies secretary-role users via NotificationService
     * when a conflict is detected.
     *
     * @param string $motionId       The motion UUID the amendment targets
     * @param string $newAmendmentId The UUID of the newly submitted amendment
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService = $this->objectService();

        $newAmendment = $objectService->getObject(register: 'decidesk', schema: 'amendment', uuid: $newAmendmentId);
        if ($newAmendment === null) {
            return;
        }

        $newText  = strtolower($newAmendment['text'] ?? '');
        $newWords = array_filter(explode(' ', preg_replace('/[^a-z0-9\s]/u', '', $newText) ?? ''));

        // Find all submitted/debating amendments for the same motion.
        $existing = $objectService->findObjects(
            register: 'decidesk',
            schema: 'amendment',
            filters: ['relations.motion' => $motionId]
        );

        $conflictFound = false;
        foreach (($existing['results'] ?? []) as $amendment) {
            if (($amendment['id'] ?? $amendment['uuid'] ?? '') === $newAmendmentId) {
                continue;
            }

            $lifecycle = ($amendment['lifecycle'] ?? '');
            if (in_array($lifecycle, ['adopted', 'rejected'], true) === true) {
                continue;
            }

            $existingText  = strtolower($amendment['text'] ?? '');
            $existingWords = array_filter(explode(' ', preg_replace('/[^a-z0-9\s]/u', '', $existingText) ?? ''));

            $overlap = array_intersect($newWords, $existingWords);
            // Consider conflict if more than 5 words overlap.
            if (count($overlap) > 5) {
                $conflictFound = true;
                break;
            }
        }

        if ($conflictFound === true) {
            // Store conflict note on the new amendment.
            $notificationService = $this->notificationService();
            try {
                $notificationService->createNotification(
                    userId: 'secretary',
                    app: 'decidesk',
                    subject: 'amendment_conflict',
                    subjectParameters: [
                        'motionId'    => $motionId,
                        'amendmentId' => $newAmendmentId,
                    ],
                    object: 'amendment',
                    objectId: $newAmendmentId
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: failed to send conflict notification',
                    ['amendmentId' => $newAmendmentId, 'error' => $e->getMessage()]
                );
            }

            // Add conflict note to the amendment object.
            $conflictNote          = [
                'title' => 'Conflict: mogelijk overlappend amendement',
                'body'  => 'Tekstoverlap gedetecteerd met een bestaand amendement op dezelfde motie.',
            ];
            $newAmendment['notes'] = array_merge(($newAmendment['notes'] ?? []), [$conflictNote]);
            $objectService->saveObject(register: 'decidesk', schema: 'amendment', object: $newAmendment);
        }//end if

    }//end detectConflicts()

    /**
     * Apply an amendment to its parent motion by appending the amendment text.
     *
     * @param string $motionId    The motion UUID
     * @param string $amendmentId The amendment UUID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $objectService = $this->objectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);
        $amendment     = $objectService->getObject(register: 'decidesk', schema: 'amendment', uuid: $amendmentId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        if ($amendment === null) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }

        $amendmentText  = ($amendment['text'] ?? '');
        $motion['text'] = ($motion['text'] ?? '')."\n\n[Amendement {$amendment['title']}]: ".$amendmentText;

        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

    }//end applyAmendment()
}//end class
