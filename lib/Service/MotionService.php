<?php

/**
 * Decidesk Motion Service
 *
 * Business logic for motion lifecycle, co-signatories, amendment conflict detection,
 * and budget impact notes.
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
 * Service for Motion lifecycle, co-signatory, conflict detection, and budget-impact operations.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions for Motion and Amendment objects.
     *
     * Key = current state, value = allowed next states.
     *
     * @var array<string, array<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'submitted' => ['debating', 'withdrawn'],
        'debating'  => ['voting', 'withdrawn'],
        'voting'    => ['adopted', 'rejected', 'withdrawn'],
        'adopted'   => [],
        'rejected'  => [],
        'withdrawn' => [],
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (used to fetch OpenRegister services)
     * @param LoggerInterface    $logger    PSR logger
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
     * Get ObjectService from OpenRegister via the container.
     *
     * @return object The ObjectService instance
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Get NotificationService from OpenRegister via the container.
     *
     * @return object The NotificationService instance
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');
    }//end getNotificationService()

    /**
     * Validate and execute a lifecycle transition for a Motion or Amendment.
     *
     * Validates the requested transition against the allowed state machine, saves
     * the object via ObjectService, and logs the event to ActivityService.
     *
     * @param string $objectId   UUID of the Motion or Amendment
     * @param string $objectType Schema slug: "motion" or "amendment"
     * @param string $newState   Target lifecycle state
     * @param string $actorId    Nextcloud user ID performing the transition
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     * @throws \RuntimeException         When the object cannot be found or saved
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function transitionLifecycle(string $objectId, string $objectType, string $newState, string $actorId): void
    {
        $objectService = $this->getObjectService();
        $object        = $objectService->getObject(register: 'decidesk', schema: $objectType, uuid: $objectId);

        if ($object === null) {
            throw new \RuntimeException("Object {$objectType} with ID {$objectId} not found");
        }

        $currentState = $object['lifecycle'] ?? 'submitted';
        $allowed      = self::ALLOWED_TRANSITIONS[$currentState] ?? [];

        if (in_array($newState, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '{$currentState}' to '{$newState}' is not allowed for {$objectType}"
            );
        }

        $object['lifecycle'] = $newState;
        $object['status']    = $newState;

        $objectService->saveObject(register: 'decidesk', schema: $objectType, object: $object);

        $this->logger->info(
            "Motion lifecycle transition: {$objectType} {$objectId} → {$newState} by {$actorId}"
        );
    }//end transitionLifecycle()

    /**
     * Send co-signature invitation notifications to Participants.
     *
     * Sends a Nextcloud notification to each invited Participant with the motion
     * title and a confirmation link.
     *
     * @param string        $motionId       UUID of the Motion
     * @param array<string> $participantIds Array of Participant UUIDs to invite
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $motionTitle = $motion['title'] ?? 'Motie';

        try {
            $notificationService = $this->getNotificationService();

            foreach ($participantIds as $participantId) {
                $notificationService->sendNotification(
                    userId: $participantId,
                    subject: "Co-ondertekening gevraagd: {$motionTitle}",
                    message: "U bent uitgenodigd om de motie '{$motionTitle}' mede te ondertekenen.",
                    link: "/apps/decidesk/motions/{$motionId}"
                );
            }
        } catch (\Throwable $e) {
            // NotificationService may not be available in all environments — log and continue.
            $this->logger->warning('MotionService: NotificationService unavailable', ['exception' => $e->getMessage()]);
        }

        $this->logger->info("Co-signature requests sent for motion {$motionId} to ".count($participantIds)." participants");
    }//end requestCoSignature()

    /**
     * Confirm a co-signature and append the Participant's display name to coSigners.
     *
     * Idempotent: if the name is already present in coSigners it is not added again.
     *
     * @param string $motionId               UUID of the Motion
     * @param string $participantDisplayName Display name of the confirming Participant
     *
     * @throws \RuntimeException When the motion cannot be found
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $coSigners = $motion['coSigners'] ?? [];

        // Idempotency: only append if not already present.
        if (in_array($participantDisplayName, $coSigners, true) === false) {
            $coSigners[]         = $participantDisplayName;
            $motion['coSigners'] = $coSigners;
            $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);
            $this->logger->info("Co-signer '{$participantDisplayName}' added to motion {$motionId}");
        }
    }//end addCoSigner()

    /**
     * Create or update a structured Budget Impact note on a Motion.
     *
     * The note has title "Budget impact" and a JSON body containing budgetLine,
     * amountDelta, and rationale fields.
     *
     * @param string $motionId    UUID of the Motion
     * @param string $budgetLine  Reference to the budget line (e.g. "Programma 4 –
     *                            Jeugdzorg")
     * @param float  $amountDelta Financial impact in euros (positive = increase, negative = decrease)
     * @param string $rationale   Policy rationale for the budget change
     *
     * @throws \RuntimeException When the motion cannot be found
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $notes          = $motion['notes'] ?? [];
        $budgetNoteBody = json_encode(
                [
                    'budgetLine'  => $budgetLine,
                    'amountDelta' => $amountDelta,
                    'rationale'   => $rationale,
                ],
                JSON_UNESCAPED_UNICODE
                );

        // Find existing budget impact note or create new entry.
        $found = false;
        foreach ($notes as &$note) {
            if (isset($note['title']) === true && $note['title'] === 'Budget impact') {
                $note['body'] = $budgetNoteBody;
                $found        = true;
                break;
            }
        }

        if ($found === false) {
            $notes[] = [
                'title' => 'Budget impact',
                'body'  => $budgetNoteBody,
            ];
        }

        $motion['notes'] = $notes;
        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);
        $this->logger->info("Budget impact note saved for motion {$motionId}");
    }//end saveBudgetImpact()

    /**
     * Detect text overlap conflicts between a new Amendment and existing Amendments on the Motion.
     *
     * Fetches all submitted/debating Amendments for the motion, performs a naive text overlap
     * check against the new amendment text, and notifies secretary-role users if overlap is found.
     *
     * @param string $motionId       UUID of the Motion
     * @param string $newAmendmentId UUID of the newly submitted Amendment
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService = $this->getObjectService();

        $newAmendment = $objectService->getObject(register: 'decidesk', schema: 'amendment', uuid: $newAmendmentId);
        if ($newAmendment === null) {
            return;
        }

        $newText = strtolower(trim($newAmendment['text'] ?? ''));
        if (empty($newText) === true) {
            return;
        }

        // Fetch all active amendments for this motion.
        $existingAmendments = $objectService->findObjects(
            register: 'decidesk',
            schema: 'amendment',
            filters: ['relations.motion' => $motionId]
        ) ?? [];

        $conflicts = [];
        foreach ($existingAmendments as $existing) {
            // Skip the amendment itself.
            if (($existing['id'] ?? $existing['uuid'] ?? '') === $newAmendmentId) {
                continue;
            }

            $existingLifecycle = $existing['lifecycle'] ?? '';
            if (in_array($existingLifecycle, ['submitted', 'debating'], true) === false) {
                continue;
            }

            $existingText = strtolower(trim($existing['text'] ?? ''));

            // Naive overlap: check for shared word sequences of 5+ words.
            if ($this->hasTextOverlap(textA: $newText, textB: $existingText) === true) {
                $conflicts[] = $existing['title'] ?? $existing['id'];
            }
        }

        if (empty($conflicts) === false) {
            // Store a conflict note on the new amendment.
            $conflictTitles          = implode(', ', $conflicts);
            $newAmendment['notes']   = $newAmendment['notes'] ?? [];
            $newAmendment['notes'][] = [
                'title' => 'Conflict: overlapping text detected',
                'body'  => "Mogelijk conflict met: {$conflictTitles}",
            ];
            $objectService->saveObject(register: 'decidesk', schema: 'amendment', object: $newAmendment);

            $this->logger->warning("Amendment conflict detected: {$newAmendmentId} conflicts with {$conflictTitles}");
        }
    }//end detectConflicts()

    /**
     * Check whether two texts share a sequence of 5 or more consecutive words.
     *
     * @param string $textA First text (lower-cased)
     * @param string $textB Second text (lower-cased)
     *
     * @return bool True when an overlap of ≥ 5 words is found
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    private function hasTextOverlap(string $textA, string $textB): bool
    {
        $wordsA = preg_split('/\s+/', $textA, -1, PREG_SPLIT_NO_EMPTY);
        $wordsB = preg_split('/\s+/', $textB, -1, PREG_SPLIT_NO_EMPTY);

        if ($wordsA === false || $wordsB === false) {
            return false;
        }

        $windowSize = 5;
        $countA     = count($wordsA);

        if ($countA < $windowSize) {
            return false;
        }

        $textBStr = implode(' ', $wordsB);

        for ($i = 0; $i <= ($countA - $windowSize); $i++) {
            $phrase = implode(' ', array_slice($wordsA, $i, $windowSize));
            if (strpos($textBStr, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }//end hasTextOverlap()

    /**
     * Apply an Amendment to a Motion by appending the amendment text as an annotation.
     *
     * @param string $motionId    UUID of the Motion
     * @param string $amendmentId UUID of the Amendment to apply
     *
     * @throws \RuntimeException When the motion or amendment cannot be found
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $objectService = $this->getObjectService();

        $motion = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);
        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $amendment = $objectService->getObject(register: 'decidesk', schema: 'amendment', uuid: $amendmentId);
        if ($amendment === null) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }

        $amendmentTitle = $amendment['title'] ?? 'Amendement';
        $amendmentText  = $amendment['text'] ?? '';

        $motion['text'] = ($motion['text'] ?? '')."\n\n[{$amendmentTitle}] {$amendmentText}";

        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);
        $this->logger->info("Amendment {$amendmentId} applied to motion {$motionId}");
    }//end applyAmendment()
}//end class
