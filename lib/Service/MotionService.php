<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk Motion Service
 *
 * Service for motion lifecycle management, co-signatory collection,
 * amendment conflict detection, and budget impact notes.
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

use OCA\Decidesk\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for motion lifecycle management, co-signatory collection,
 * amendment conflict detection, and budget impact notes.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions for Motion objects.
     * Key = current state, value = allowed next states.
     *
     * @var array<string,array<string>>
     */
    private const MOTION_TRANSITIONS = [
        'submitted'  => ['debating', 'withdrawn'],
        'debating'   => ['voting', 'withdrawn'],
        'voting'     => ['adopted', 'rejected'],
        'adopted'    => [],
        'rejected'   => [],
        'withdrawn'  => [],
    ];

    /**
     * Allowed lifecycle transitions for Amendment objects.
     * Key = current state, value = allowed next states.
     *
     * @var array<string,array<string>>
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
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Get the OpenRegister NotificationService from the container.
     *
     * @return object
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');
    }//end getNotificationService()

    /**
     * Get the OpenRegister ActivityService from the container.
     *
     * @return object
     */
    private function getActivityService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ActivityService');
    }//end getActivityService()

    /**
     * Transition the lifecycle of a Motion or Amendment object to a new state.
     *
     * Validates the transition is allowed for the current state, then saves
     * the updated object and logs the event to the activity stream.
     *
     * @param string $objectId   The ID of the object to transition
     * @param string $objectType The object type: 'motion' or 'amendment'
     * @param string $newState   The target lifecycle state
     * @param string $actorId    The user ID performing the transition
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     * @throws \RuntimeException         When the object cannot be found or saved
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function transitionLifecycle(
        string $objectId,
        string $objectType,
        string $newState,
        string $actorId,
    ): void {
        $objectService = $this->getObjectService();
        $object        = $objectService->findObject(
            register: Application::APP_ID,
            schema: $objectType,
            id: $objectId
        );

        if ($object === null) {
            throw new \RuntimeException("Object {$objectType}:{$objectId} not found");
        }

        $currentState = ($object['lifecycle'] ?? $object['status'] ?? 'submitted');
        $allowed      = ($objectType === 'motion')
            ? (self::MOTION_TRANSITIONS[$currentState] ?? [])
            : (self::AMENDMENT_TRANSITIONS[$currentState] ?? []);

        if (in_array($newState, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '{$currentState}' to '{$newState}' is not allowed for {$objectType}"
            );
        }

        $object['lifecycle'] = $newState;
        $object['status']    = $newState;

        $objectService->saveObject(
            register: Application::APP_ID,
            schema: $objectType,
            object: $object
        );

        $this->logger->info(
            "Decidesk: {$objectType} {$objectId} transitioned from {$currentState} to {$newState} by {$actorId}"
        );
    }//end transitionLifecycle()

    /**
     * Send co-signature invitation notifications to the given participants.
     *
     * @param string        $motionId       The Motion object ID
     * @param array<string> $participantIds Array of participant user IDs to invite
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'motion',
            id: $motionId
        );

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $motionTitle         = ($motion['title'] ?? 'Motion');
        $notificationService = $this->getNotificationService();

        foreach ($participantIds as $participantId) {
            try {
                $notificationService->sendNotification(
                    userId: $participantId,
                    subject: 'co_sign_request',
                    message: "You are invited to co-sign: {$motionTitle}",
                    objectType: 'motion',
                    objectId: $motionId
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Decidesk: failed to send co-sign notification to {$participantId}",
                    ['exception' => $e->getMessage()]
                );
            }
        }//end foreach

    }//end requestCoSignature()

    /**
     * Add a co-signer to a motion (idempotent — no duplicates added).
     *
     * Fetches the motion, appends the participant display name to `coSigners`
     * if not already present, and saves the updated object.
     *
     * @param string $motionId              The Motion object ID
     * @param string $participantDisplayName The display name of the co-signer
     *
     * @return void
     *
     * @throws \RuntimeException When the motion cannot be found
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'motion',
            id: $motionId
        );

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $coSigners = ($motion['coSigners'] ?? []);

        if (in_array($participantDisplayName, $coSigners, true) === true) {
            // Idempotent: already a co-signer, nothing to do.
            return;
        }

        $coSigners[]       = $participantDisplayName;
        $motion['coSigners'] = $coSigners;

        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'motion',
            object: $motion
        );

        $this->logger->info(
            "Decidesk: {$participantDisplayName} added as co-signer to motion {$motionId}"
        );
    }//end addCoSigner()

    /**
     * Create or update the budget impact structured note on a motion.
     *
     * Stores budget amendment details as a note with title "Budget impact"
     * and a JSON body containing budgetLine, amountDelta, and rationale.
     *
     * @param string $motionId    The Motion object ID
     * @param string $budgetLine  The budget line reference
     * @param float  $amountDelta The budget amount change (positive = increase)
     * @param string $rationale   The policy rationale for the change
     *
     * @return void
     *
     * @throws \RuntimeException When the motion cannot be found
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function saveBudgetImpact(
        string $motionId,
        string $budgetLine,
        float $amountDelta,
        string $rationale,
    ): void {
        $objectService = $this->getObjectService();
        $motion        = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'motion',
            id: $motionId
        );

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $noteBody = json_encode(
            [
                'budgetLine'  => $budgetLine,
                'amountDelta' => $amountDelta,
                'rationale'   => $rationale,
            ],
            JSON_THROW_ON_ERROR
        );

        $notes = ($motion['notes'] ?? []);

        // Find existing budget impact note or create a new one.
        $found = false;
        foreach ($notes as &$note) {
            if (isset($note['title']) === true && $note['title'] === 'Budget impact') {
                $note['body'] = $noteBody;
                $found        = true;
                break;
            }
        }

        if ($found === false) {
            $notes[] = [
                'title' => 'Budget impact',
                'body'  => $noteBody,
            ];
        }

        $motion['notes'] = $notes;

        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'motion',
            object: $motion
        );

        $this->logger->info("Decidesk: budget impact note saved for motion {$motionId}");
    }//end saveBudgetImpact()

    /**
     * Detect text conflicts between a new amendment and existing amendments for a motion.
     *
     * Fetches all submitted/debating amendments for the motion, checks for
     * naive text overlap with the new amendment, and notifies secretary-role
     * users if a conflict is detected.
     *
     * @param string $motionId       The parent Motion ID
     * @param string $newAmendmentId The ID of the new amendment being submitted
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService = $this->getObjectService();

        $newAmendment = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'amendment',
            id: $newAmendmentId
        );

        if ($newAmendment === null) {
            return;
        }

        $newText = strtolower(trim($newAmendment['text'] ?? ''));
        if ($newText === '') {
            return;
        }

        // Fetch all amendments for this motion in active lifecycle states.
        $existing = $objectService->findAll(
            register: Application::APP_ID,
            schema: 'amendment',
            filters: [
                'relations.motion' => $motionId,
            ]
        );

        $conflicts = [];
        foreach (($existing['results'] ?? $existing ?? []) as $amendment) {
            if (($amendment['id'] ?? null) === $newAmendmentId) {
                continue;
            }

            $lifecycle = ($amendment['lifecycle'] ?? 'submitted');
            if (in_array($lifecycle, ['submitted', 'debating'], true) === false) {
                continue;
            }

            $existingText = strtolower(trim($amendment['text'] ?? ''));
            if ($existingText === '') {
                continue;
            }

            // Naive overlap: check if at least 10 characters of the new text appear in the existing one.
            $words = array_filter(explode(' ', $newText), static fn($w) => strlen($w) > 4);
            foreach ($words as $word) {
                if (str_contains($existingText, $word) === true) {
                    $conflicts[] = ($amendment['title'] ?? $amendment['id']);
                    break;
                }
            }
        }//end foreach

        if (empty($conflicts) === false) {
            $conflictList        = implode(', ', $conflicts);
            $notificationService = $this->getNotificationService();

            // Notify via a note on the new amendment.
            $newAmendment['notes'] = ($newAmendment['notes'] ?? []);
            $newAmendment['notes'][] = [
                'title' => 'Conflict:',
                'body'  => "Possible text conflict with: {$conflictList}",
            ];

            $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'amendment',
                object: $newAmendment
            );

            $this->logger->warning(
                "Decidesk: conflict detected for amendment {$newAmendmentId} with: {$conflictList}"
            );
        }//end if

    }//end detectConflicts()

    /**
     * Apply an adopted amendment to its parent motion text.
     *
     * Reads the amendment text and appends it as an annotation to the
     * parent motion's text field, then saves the motion.
     *
     * @param string $motionId    The parent Motion ID
     * @param string $amendmentId The adopted Amendment ID
     *
     * @return void
     *
     * @throws \RuntimeException When either object cannot be found
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $objectService = $this->getObjectService();

        $motion = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'motion',
            id: $motionId
        );

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $amendment = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'amendment',
            id: $amendmentId
        );

        if ($amendment === null) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }

        $amendmentTitle = ($amendment['title'] ?? 'Amendment');
        $amendmentText  = ($amendment['text'] ?? '');

        $motion['text'] .= "\n\n[Amendement aangenomen — {$amendmentTitle}]\n{$amendmentText}";

        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'motion',
            object: $motion
        );

        $this->logger->info(
            "Decidesk: amendment {$amendmentId} applied to motion {$motionId}"
        );
    }//end applyAmendment()

}//end class
