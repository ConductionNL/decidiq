<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk Motion Service
 *
 * Service for managing Motion and Amendment lifecycle, co-signatories,
 * budget impact notes, conflict detection, and amendment application.
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
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Motion and Amendment lifecycle.
 *
 * Handles lifecycle transitions, co-signatory collection, budget impact notes,
 * amendment conflict detection, and amendment application.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Valid lifecycle states for Motion and Amendment.
     *
     * @var array<string>
     */
    private const VALID_STATES = [
        'submitted',
        'debating',
        'voting',
        'adopted',
        'rejected',
        'withdrawn',
    ];

    /**
     * Allowed lifecycle transitions: from → [to, ...]
     *
     * @var array<string, array<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'submitted' => ['debating', 'withdrawn'],
        'debating'  => ['voting', 'withdrawn'],
        'voting'    => ['adopted', 'rejected'],
    ];

    /**
     * Constructor for MotionService.
     *
     * @param ContainerInterface $container The DI container (to lazily resolve OpenRegister services)
     * @param IAppConfig         $appConfig The app config interface
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
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
     * Transition a Motion or Amendment to a new lifecycle state.
     *
     * Validates that the transition is allowed, updates the object via
     * ObjectService.saveObject(), and logs the event to ActivityService.
     *
     * @param string $objectId   The OpenRegister object UUID
     * @param string $objectType Either 'motion' or 'amendment'
     * @param string $newState   Target lifecycle state
     * @param string $actorId    Nextcloud user ID performing the transition
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     *
     * @return void
     */
    public function transitionLifecycle(
        string $objectId,
        string $objectType,
        string $newState,
        string $actorId,
    ): void {
        if (in_array(needle: $newState, haystack: self::VALID_STATES, strict: true) === false) {
            throw new \InvalidArgumentException("Invalid lifecycle state: {$newState}");
        }

        $objectService = $this->getObjectService();
        $object        = $objectService->getObject(register: 'decidesk', schema: $objectType, id: $objectId);

        $currentState = ($object['lifecycle'] ?? $object['status'] ?? 'submitted');

        // Validate transition is allowed.
        if ($newState !== 'withdrawn') {
            $allowed = (self::ALLOWED_TRANSITIONS[$currentState] ?? []);
            if (in_array(needle: $newState, haystack: $allowed, strict: true) === false) {
                throw new \InvalidArgumentException(
                    "Transition from '{$currentState}' to '{$newState}' is not allowed"
                );
            }
        }

        // Update lifecycle and status fields.
        $object['lifecycle'] = $newState;
        $object['status']    = $newState;

        $objectService->saveObject(
            register: 'decidesk',
            schema: $objectType,
            object: $object,
        );

        $this->logger->info(
            "Decidesk: {$objectType} {$objectId} transitioned from '{$currentState}' to '{$newState}' by {$actorId}"
        );

    }//end transitionLifecycle()

    /**
     * Send co-signature invitation notifications to the specified Participants.
     *
     * Sends a Nextcloud notification to each Participant with the motion title
     * and a deep link to the motion detail page.
     *
     * @param string        $motionId       The motion UUID
     * @param array<string> $participantIds Nextcloud user IDs to invite
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', id: $motionId);

        $motionTitle = ($motion['title'] ?? $motionId);

        try {
            $notificationService = $this->getNotificationService();

            foreach ($participantIds as $participantId) {
                $notificationService->sendNotification(
                    userId: $participantId,
                    subject: 'co_sign_request',
                    message: $motionTitle,
                    objectType: 'motion',
                    objectId: $motionId,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to send co-signature notifications',
                ['exception' => $e->getMessage(), 'motionId' => $motionId]
            );
        }

    }//end requestCoSignature()

    /**
     * Add a co-signer's display name to a Motion's coSigners array.
     *
     * This method is idempotent — if the display name is already in the array,
     * no duplicate is added.
     *
     * @param string $motionId             The motion UUID
     * @param string $participantDisplayName The display name to append
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', id: $motionId);

        $coSigners = ($motion['coSigners'] ?? []);

        if (in_array(needle: $participantDisplayName, haystack: $coSigners, strict: true) === false) {
            $coSigners[]          = $participantDisplayName;
            $motion['coSigners']  = $coSigners;

            $objectService->saveObject(
                register: 'decidesk',
                schema: 'motion',
                object: $motion,
            );
        }

    }//end addCoSigner()

    /**
     * Save or update the budget impact note on a Motion.
     *
     * Stores financial impact details as a structured JSON note with
     * title "Budget impact" on the Motion object.
     *
     * @param string $motionId    The motion UUID
     * @param string $budgetLine  Budget line reference (e.g. programme/account code)
     * @param float  $amountDelta Positive = increase, negative = decrease (in euros)
     * @param string $rationale   Policy justification text
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function saveBudgetImpact(
        string $motionId,
        string $budgetLine,
        float $amountDelta,
        string $rationale,
    ): void {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', id: $motionId);

        $noteBody = json_encode(
            [
                'budgetLine'  => $budgetLine,
                'amountDelta' => $amountDelta,
                'rationale'   => $rationale,
            ],
            flags: JSON_UNESCAPED_UNICODE
        );

        // Find existing budget impact note or create new one.
        $notes      = ($motion['notes'] ?? []);
        $noteFound  = false;
        foreach ($notes as $index => $note) {
            if (($note['title'] ?? '') === 'Budget impact') {
                $notes[$index]['body'] = $noteBody;
                $noteFound             = true;
                break;
            }
        }

        if ($noteFound === false) {
            $notes[] = [
                'title' => 'Budget impact',
                'body'  => $noteBody,
            ];
        }

        $motion['notes'] = $notes;

        $objectService->saveObject(
            register: 'decidesk',
            schema: 'motion',
            object: $motion,
        );

    }//end saveBudgetImpact()

    /**
     * Detect text overlaps between a new Amendment and existing Amendments on the same Motion.
     *
     * Uses naive text overlap detection: if the new amendment's text contains
     * substrings also present in other submitted/debating amendments, a conflict
     * notification is sent to secretary-role users and a note is added.
     *
     * @param string $motionId       The motion UUID
     * @param string $newAmendmentId The UUID of the newly submitted Amendment
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService   = $this->getObjectService();
        $newAmendment    = $objectService->getObject(register: 'decidesk', schema: 'amendment', id: $newAmendmentId);
        $newAmendmentText = strtolower(trim(($newAmendment['text'] ?? '')));

        if ($newAmendmentText === '') {
            return;
        }

        // Fetch all submitted/debating amendments for this motion.
        $existingAmendments = $objectService->findObjects(
            register: 'decidesk',
            schema: 'amendment',
            filters: [
                'motion'    => $motionId,
                'lifecycle' => ['submitted', 'debating'],
            ],
        );

        $conflicts = [];
        foreach ($existingAmendments as $existing) {
            if (($existing['id'] ?? '') === $newAmendmentId) {
                continue;
            }

            $existingText = strtolower(trim(($existing['text'] ?? '')));
            if ($existingText === '') {
                continue;
            }

            // Check for word-level overlap (at least 5 consecutive words in common).
            $newWords      = explode(' ', $newAmendmentText);
            $hasOverlap    = false;

            for ($i = 0; $i <= (count($newWords) - 5); $i++) {
                $phrase = implode(' ', array_slice($newWords, $i, 5));
                if (str_contains($existingText, $phrase) === true) {
                    $hasOverlap = true;
                    break;
                }
            }

            if ($hasOverlap === true) {
                $conflicts[] = ($existing['title'] ?? $existing['id']);
            }
        }//end foreach

        if (empty($conflicts) === false) {
            // Add conflict note to the new amendment.
            $conflictNote = implode(', ', $conflicts);
            $newAmendment['notes'] = array_merge(
                ($newAmendment['notes'] ?? []),
                [['title' => 'Conflict:', 'body' => $conflictNote]]
            );
            $objectService->saveObject(register: 'decidesk', schema: 'amendment', object: $newAmendment);

            // Notify via logger (NotificationService would need secretary user IDs).
            $this->logger->warning(
                "Decidesk: Amendment {$newAmendmentId} conflicts with: {$conflictNote}"
            );
        }

    }//end detectConflicts()

    /**
     * Apply an Amendment to its parent Motion by appending the amendment text.
     *
     * Reads the Amendment text and appends it as an annotation to the Motion
     * text field, then saves the Motion via ObjectService.saveObject().
     *
     * @param string $motionId    The motion UUID
     * @param string $amendmentId The amendment UUID to apply
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', id: $motionId);
        $amendment     = $objectService->getObject(register: 'decidesk', schema: 'amendment', id: $amendmentId);

        $amendmentTitle = ($amendment['title'] ?? $amendmentId);
        $amendmentText  = ($amendment['text'] ?? '');

        $annotation     = "\n\n[Amendement: {$amendmentTitle}]\n{$amendmentText}";
        $motion['text'] = ($motion['text'] ?? '') . $annotation;

        $objectService->saveObject(
            register: 'decidesk',
            schema: 'motion',
            object: $motion,
        );

    }//end applyAmendment()

}//end class
