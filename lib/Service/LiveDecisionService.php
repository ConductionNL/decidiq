<?php

/**
 * Decidesk Live Decision Service
 *
 * Service for recording decisions during active meetings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for recording decisions during an active meeting.
 *
 * Provides methods to record decisions linked to a meeting and ensure
 * a draft Minutes object exists for the meeting.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 */
class LiveDecisionService
{
    /**
     * Constructor for LiveDecisionService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record a decision during an active meeting.
     *
     * Verifies the meeting is in 'opened' state, creates the Decision,
     * ensures a draft Minutes exists, and links the Decision to the Minutes.
     *
     * @param string $meetingId    UUID of the meeting
     * @param array  $decisionData Array with keys: title (string), text (string), outcome (string), legalBasis? (string)
     * @param string $actorId      User ID of the decision recorder
     *
     * @throws \InvalidArgumentException If meeting is not in opened state
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
     *
     * @return string The slug of the newly created Decision
     */
    public function recordDecision(
        string $meetingId,
        array $decisionData,
        string $actorId
    ): string {
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Fetch the meeting.
            $meeting = $objectService->find(id: $meetingId);
            if ($meeting === null) {
                throw new \InvalidArgumentException('Meeting not found');
            }

            $meetingObj = $meeting->getObject();
            $lifecycle  = $meetingObj['lifecycle'] ?? 'draft';

            // Verify meeting is opened.
            if ($lifecycle !== 'opened') {
                throw new \InvalidArgumentException(
                    'Meeting must be in "opened" state to record decisions. Current state: '.$lifecycle
                );
            }

            // Ensure draft Minutes exists.
            $minutesId = $this->ensureDraftMinutes($meetingId);

            // Create the Decision.
            $decisionPayload = [
                '@self'        => [
                    'register' => 'decidesk',
                    'schema'   => 'Decision',
                ],
                'title'        => $decisionData['title'] ?? '',
                'text'         => $decisionData['text'] ?? '',
                'outcome'      => $decisionData['outcome'] ?? 'adopted',
                'decisionDate' => date('c'),
                'lifecycle'    => 'draft',
            ];

            if (!empty($decisionData['legalBasis'])) {
                $decisionPayload['legalBasis'] = $decisionData['legalBasis'];
            }

            // Add relations.
            $decisionPayload['meeting'] = $meetingId;
            $decisionPayload['minutes'] = $minutesId;

            $createdDecision = $objectService->saveObject(
                object: $decisionPayload,
                register: 'decidesk',
                schema: 'Decision'
            );

            $decisionSlug = $createdDecision['@self']['slug'] ?? $createdDecision['id'] ?? '';

            $this->logger->info(
                'Decidesk: decision recorded during meeting',
                ['meetingId' => $meetingId, 'decisionId' => $decisionSlug, 'actor' => $actorId]
            );

            return $decisionSlug;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to record decision',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end recordDecision()

    /**
     * Ensure a draft Minutes object exists for a meeting.
     *
     * If a Minutes object linked to the meeting exists, returns its slug.
     * Otherwise, creates a new draft Minutes and returns its slug.
     *
     * @param string $meetingId UUID of the meeting
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
     *
     * @return string The slug of the draft Minutes
     */
    public function ensureDraftMinutes(string $meetingId): string
    {
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Check if Minutes already exist for this meeting.
            $params = [
                'meeting' => $meetingId,
                '_limit'  => 1,
            ];

            $existingMinutes = $objectService->findAll(
                register: 'decidesk',
                schema: 'Minutes',
                params: $params
            );

            if (!empty($existingMinutes['results'])) {
                $minutes = $existingMinutes['results'][0];
                return $minutes['@self']['slug'] ?? $minutes['id'] ?? '';
            }

            // Fetch the meeting to get its title.
            $meeting = $objectService->find(id: $meetingId);
            if ($meeting === null) {
                throw new \RuntimeException('Meeting not found');
            }

            $meetingObj   = $meeting->getObject();
            $meetingTitle = $meetingObj['title'] ?? 'Onbekende vergadering';

            // Create draft Minutes.
            $minutesPayload = [
                '@self'     => [
                    'register' => 'decidesk',
                    'schema'   => 'Minutes',
                ],
                'title'     => 'Concept notulen — '.$meetingTitle,
                'lifecycle' => 'draft',
                'version'   => 1,
                'content'   => '',
                'meeting'   => $meetingId,
            ];

            $createdMinutes = $objectService->saveObject(
                object: $minutesPayload,
                register: 'decidesk',
                schema: 'Minutes'
            );

            $minutesSlug = $createdMinutes['@self']['slug'] ?? $createdMinutes['id'] ?? '';

            $this->logger->info(
                'Decidesk: auto-created draft minutes for meeting',
                ['meetingId' => $meetingId, 'minutesId' => $minutesSlug]
            );

            return $minutesSlug;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to ensure draft minutes',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end ensureDraftMinutes()
}//end class
