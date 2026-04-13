<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk Motion Service
 *
 * Service for managing motion lifecycle, co-signatures, budget impact,
 * amendment conflicts, and amendment application.
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
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for motion lifecycle management.
 *
 * Handles lifecycle transitions, co-signatures, budget impact notes,
 * amendment conflict detection, and amendment application.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions keyed by current state.
     *
     * @var array<string,array<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'submitted' => ['debating', 'withdrawn'],
        'debating'  => ['voting', 'withdrawn'],
        'voting'    => ['adopted', 'rejected'],
    ];

    /**
     * Constructor for the MotionService.
     *
     * @param ContainerInterface $container The service container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Retrieve the ObjectService from the container.
     *
     * @return object The OpenRegister ObjectService instance
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Transition an object through its lifecycle states.
     *
     * Validates the requested transition against the allowed transitions map
     * and updates the object's lifecycle and status properties.
     *
     * @param string $objectId   The ID of the object to transition
     * @param string $objectType The type of the object
     * @param string $newState   The desired new lifecycle state
     * @param string $actorId    The ID of the actor performing the transition
     *
     * @return array<string,mixed> The updated object
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function transitionLifecycle(
        string $objectId,
        string $objectType,
        string $newState,
        string $actorId,
    ): array {
        $objectService = $this->getObjectService();
        $object        = $objectService->getObject($objectType, $objectId);

        $currentState = ($object['lifecycle'] ?? '');

        if (isset(self::ALLOWED_TRANSITIONS[$currentState]) === false
            || in_array($newState, self::ALLOWED_TRANSITIONS[$currentState], true) === false
        ) {
            $this->logger->warning(
                'State transition not allowed',
                [
                    'objectId'   => $objectId,
                    'objectType' => $objectType,
                    'from'       => $currentState,
                    'to'         => $newState,
                ]
            );
            throw new \InvalidArgumentException('State transition not permitted');
        }

        $object['lifecycle'] = $newState;
        $object['status']    = $newState;

        $this->logger->info(
            'Lifecycle transition',
            [
                'objectId'   => $objectId,
                'objectType' => $objectType,
                'from'       => $currentState,
                'to'         => $newState,
                'actorId'    => substr(sha1($actorId), 0, 8),
            ]
        );

        return $objectService->saveObject($objectType, $object);
    }//end transitionLifecycle()

    /**
     * Request co-signatures for a motion from the given participants.
     *
     * Logs the co-signature request for each participant. Actual notification
     * delivery requires a running Nextcloud environment.
     *
     * @param string        $motionId       The ID of the motion
     * @param array<string> $participantIds The participant IDs to request signatures from
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject('motion', $motionId);
        $title         = ($motion['title'] ?? 'Untitled motion');

        foreach ($participantIds as $participantId) {
            $this->logger->debug(
                'Co-signature requested',
                [
                    'motionId'      => $motionId,
                    'motionTitle'   => $title,
                    'participantId' => substr(sha1($participantId), 0, 8),
                ]
            );
        }
    }//end requestCoSignature()

    /**
     * Add a co-signer to a motion.
     *
     * This operation is idempotent — if the participant is already listed
     * as a co-signer the motion is returned unchanged.
     *
     * @param string $motionId               The ID of the motion
     * @param string $participantDisplayName The display name of the co-signer
     *
     * @return array<string,mixed> The updated motion
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): array
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject('motion', $motionId);

        $coSigners = ($motion['coSigners'] ?? []);

        if (in_array($participantDisplayName, $coSigners, true) === true) {
            return $motion;
        }

        $coSigners[]         = $participantDisplayName;
        $motion['coSigners'] = $coSigners;

        return $objectService->saveObject('motion', $motion);
    }//end addCoSigner()

    /**
     * Save a budget impact note on a motion.
     *
     * Creates a structured note containing budget line, amount delta, and
     * rationale, then appends it to the motion's notes array.
     *
     * @param string $motionId    The ID of the motion
     * @param string $budgetLine  The budget line affected
     * @param float  $amountDelta The change in amount
     * @param string $rationale   The reason for the budget impact
     *
     * @return array<string,mixed> The updated motion
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function saveBudgetImpact(
        string $motionId,
        string $budgetLine,
        float $amountDelta,
        string $rationale,
    ): array {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject('motion', $motionId);

        $note = [
            'title' => 'Budget impact',
            'body'  => json_encode(
                [
                    'budgetLine'  => $budgetLine,
                    'amountDelta' => $amountDelta,
                    'rationale'   => $rationale,
                ],
                JSON_THROW_ON_ERROR
            ),
        ];

        $notes           = ($motion['notes'] ?? []);
        $notes[]         = $note;
        $motion['notes'] = $notes;

        return $objectService->saveObject('motion', $motion);
    }//end saveBudgetImpact()

    /**
     * Detect conflicts between a new amendment and existing amendments of a motion.
     *
     * Uses naive word overlap: if more than 30 % of words in two amendment
     * texts are shared the amendments are considered conflicting.
     *
     * @param string $motionId       The ID of the motion
     * @param string $newAmendmentId The ID of the new amendment to check
     *
     * @return array<string> IDs of conflicting amendments
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): array
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject('motion', $motionId);
        $amendmentIds  = ($motion['amendments'] ?? []);
        $newAmendment  = $objectService->getObject('amendment', $newAmendmentId);
        $newWords      = $this->extractWords(text: ($newAmendment['text'] ?? ''));

        if (count($newWords) === 0) {
            return [];
        }

        $conflicts = [];

        foreach ($amendmentIds as $existingId) {
            if ($existingId === $newAmendmentId) {
                continue;
            }

            $existing      = $objectService->getObject('amendment', $existingId);
            $existingWords = $this->extractWords(text: ($existing['text'] ?? ''));

            if (count($existingWords) === 0) {
                continue;
            }

            $commonWords  = array_intersect($newWords, $existingWords);
            $smallerCount = min(count($newWords), count($existingWords));
            $overlap      = (count($commonWords) / $smallerCount);

            if ($overlap > 0.3) {
                $conflicts[] = $existingId;
            }
        }

        return $conflicts;
    }//end detectConflicts()

    /**
     * Apply an amendment to a motion.
     *
     * Appends the amendment text to the motion text in a clearly delimited
     * block and saves the updated motion.
     *
     * @param string $motionId    The ID of the motion
     * @param string $amendmentId The ID of the amendment to apply
     *
     * @return array<string,mixed> The updated motion
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function applyAmendment(string $motionId, string $amendmentId): array
    {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject('motion', $motionId);
        $amendment     = $objectService->getObject('amendment', $amendmentId);

        $amendmentTitle = ($amendment['title'] ?? 'Untitled');
        $amendmentText  = ($amendment['text'] ?? '');

        $motion['text'] = ($motion['text'] ?? '')
            ."\n\n[Amendement: {$amendmentTitle}]\n{$amendmentText}";

        return $objectService->saveObject('motion', $motion);
    }//end applyAmendment()

    /**
     * Extract unique lowercase words from a text string.
     *
     * @param string $text The input text
     *
     * @return array<string> Unique lowercase words
     */
    private function extractWords(string $text): array
    {
        $words = preg_split('/\W+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_unique($words);
    }//end extractWords()
}//end class
