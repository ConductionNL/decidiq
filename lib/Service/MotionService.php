<?php

/**
 * Decidesk Motion Service
 *
 * Service for managing motion lifecycle, co-signatories, amendments, and budget impact.
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
 * Service for managing motion lifecycle, co-signatories, amendments, and budget impact.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions for Motion objects.
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
     * Retrieve the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Retrieve the OpenRegister NotificationService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');
    }//end getNotificationService()

    /**
     * Transition the lifecycle of a Motion or Amendment object to a new state.
     *
     * Validates the transition is allowed, persists the change via ObjectService,
     * and logs the event via ActivityService.
     *
     * @param string $objectId   The UUID of the Motion or Amendment
     * @param string $objectType Schema type: "motion" or "amendment"
     * @param string $newState   Target lifecycle state
     * @param string $actorId    Nextcloud user ID of the user performing the transition
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     * @throws \RuntimeException         When the object cannot be found or saved
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function transitionLifecycle(string $objectId, string $objectType, string $newState, string $actorId): void
    {
        $objectService = $this->getObjectService();
        $object        = $objectService->getObject(register: 'decidesk', schema: $objectType, uuid: $objectId);

        if ($object === null) {
            throw new \RuntimeException("Object {$objectType}/{$objectId} not found");
        }

        $currentState = ($object['lifecycle'] ?? $object['status'] ?? 'submitted');
        $allowed      = (self::ALLOWED_TRANSITIONS[$currentState] ?? []);

        if (in_array($newState, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '{$currentState}' to '{$newState}' is not allowed for {$objectType}/{$objectId}"
            );
        }

        $object['lifecycle'] = $newState;
        $object['status']    = $newState;

        $objectService->saveObject(register: 'decidesk', schema: $objectType, object: $object);

        $this->logger->info(
            'Decidesk: lifecycle transition',
            [
                'objectType' => $objectType,
                'objectId'   => $objectId,
                'from'       => $currentState,
                'to'         => $newState,
                'actor'      => $actorId,
            ]
        );

    }//end transitionLifecycle()

    /**
     * Request co-signature on a motion from a list of participants.
     *
     * Sends a Nextcloud notification to each participant containing the motion
     * title and a link to confirm their co-signature.
     *
     * @param string        $motionId       The UUID of the Motion
     * @param array<string> $participantIds Nextcloud user IDs to invite
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $notificationService = $this->getNotificationService();
        $motionTitle         = ($motion['title'] ?? $motionId);

        foreach ($participantIds as $participantId) {
            try {
                $notificationService->sendNotification(
                    userId: $participantId,
                    subject: 'co_sign_request',
                    message: "U bent uitgenodigd om motie '{$motionTitle}' mede te ondertekenen.",
                    objectType: 'motion',
                    objectId: $motionId,
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: failed to send co-sign notification',
                    ['participant' => $participantId, 'motion' => $motionId, 'error' => $e->getMessage()]
                );
            }
        }

    }//end requestCoSignature()

    /**
     * Add a co-signer's display name to a motion (idempotent).
     *
     * Fetches the Motion, appends the display name to the coSigners array if
     * not already present, and saves the object via ObjectService.
     *
     * @param string $motionId               The UUID of the Motion
     * @param string $participantDisplayName The display name to add
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

        $coSigners = ($motion['coSigners'] ?? []);

        // Idempotency: do not add duplicate names.
        if (in_array($participantDisplayName, $coSigners, true) === true) {
            return;
        }

        $coSigners[]         = $participantDisplayName;
        $motion['coSigners'] = $coSigners;

        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

        $this->logger->info(
            'Decidesk: co-signer added',
            ['motionId' => $motionId, 'coSigner' => $participantDisplayName]
        );

    }//end addCoSigner()

    /**
     * Save or update the budget impact structured note on a Motion.
     *
     * Creates or replaces a note with title "Budget impact" containing a JSON
     * body with budgetLine, amountDelta, and rationale fields.
     *
     * @param string $motionId    The UUID of the Motion
     * @param string $budgetLine  Budget line reference (e.g. "4.2 Jeugdzorg")
     * @param float  $amountDelta Positive or negative euro amount
     * @param string $rationale   Policy rationale for the budget change
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);

        if ($motion === null) {
            throw new \RuntimeException("Motion {$motionId} not found");
        }

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

        // Replace existing budget impact note or append new one.
        $notes = ($motion['notes'] ?? []);
        $found = false;
        foreach ($notes as $index => $note) {
            if (($note['title'] ?? '') === 'Budget impact') {
                $notes[$index] = $budgetNote;
                $found         = true;
                break;
            }
        }

        if ($found === false) {
            $notes[] = $budgetNote;
        }

        $motion['notes'] = $notes;

        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

        $this->logger->info(
            'Decidesk: budget impact saved',
            ['motionId' => $motionId, 'amountDelta' => $amountDelta]
        );

    }//end saveBudgetImpact()

    /**
     * Detect text conflicts between submitted amendments on a motion.
     *
     * Fetches all submitted/debating Amendments for the motion, performs a
     * naive text-overlap check against the new amendment text, and notifies
     * secretary-role users via NotificationService if overlap is found.
     *
     * @param string $motionId       The UUID of the parent Motion
     * @param string $newAmendmentId The UUID of the newly submitted Amendment
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService = $this->getObjectService();

        $newAmendment = $objectService->getObject(register: 'decidesk', schema: 'amendment', uuid: $newAmendmentId);
        if ($newAmendment === null) {
            return;
        }

        $newText = strtolower((string) ($newAmendment['text'] ?? ''));
        if ($newText === '') {
            return;
        }

        // Fetch existing amendments for the same motion in active lifecycle states.
        $existing = $objectService->findAll(
            register: 'decidesk',
            schema: 'amendment',
            filters: [
                'motion'    => $motionId,
                'lifecycle' => ['submitted', 'debating'],
            ]
        );

        $conflicts = [];
        foreach (($existing ?? []) as $amendment) {
            if (($amendment['id'] ?? $amendment['uuid'] ?? null) === $newAmendmentId) {
                continue;
            }

            $existingText = strtolower((string) ($amendment['text'] ?? ''));
            if ($existingText === '') {
                continue;
            }

            // Naive overlap: check for shared 5+-word phrases.
            $newWords      = preg_split('/\s+/', $newText);
            $existingWords = preg_split('/\s+/', $existingText);

            if ($newWords === false || $existingWords === false) {
                continue;
            }

            $overlap = array_intersect($newWords, $existingWords);
            if (count($overlap) >= 5) {
                $conflicts[] = ($amendment['title'] ?? ($amendment['id'] ?? 'unknown'));
            }
        }//end foreach

        if (empty($conflicts) === false) {
            // Store a conflict note on the new amendment.
            $newAmendment['notes'] = array_merge(
                ($newAmendment['notes'] ?? []),
                [
                    [
                        'title' => 'Conflict: mogelijke tekstoverlap',
                        'body'  => 'Mogelijk conflict met: '.implode(', ', $conflicts),
                    ],
                ]
            );
            $objectService->saveObject(register: 'decidesk', schema: 'amendment', object: $newAmendment);

            // Notify secretary-role users.
            try {
                $notificationService = $this->getNotificationService();
                $notificationService->sendNotification(
                    userId: 'secretary',
                    subject: 'amendment_conflict',
                    message: 'Mogelijk conflict gedetecteerd bij amendement: '.($newAmendment['title'] ?? $newAmendmentId),
                    objectType: 'amendment',
                    objectId: $newAmendmentId,
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: failed to send conflict notification',
                    ['amendmentId' => $newAmendmentId, 'error' => $e->getMessage()]
                );
            }
        }//end if

    }//end detectConflicts()

    /**
     * Apply an adopted amendment to its parent motion.
     *
     * Reads the Amendment text and appends it as an annotation to the Motion
     * text field, then saves the Motion via ObjectService.
     *
     * @param string $motionId    The UUID of the parent Motion
     * @param string $amendmentId The UUID of the Amendment to apply
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
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

        $amendmentTitle = ($amendment['title'] ?? $amendmentId);
        $amendmentText  = ($amendment['text'] ?? '');

        $motion['text'] = ($motion['text'] ?? '')."\n\n[Amendement toegepast: {$amendmentTitle}]\n".$amendmentText;

        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

        $this->logger->info(
            'Decidesk: amendment applied to motion',
            ['motionId' => $motionId, 'amendmentId' => $amendmentId]
        );

    }//end applyAmendment()
}//end class
