<?php
/**
 * Decidesk Engagement Service
 *
 * Stateless service handling EngagementRecord aggregation: speeches,
 * questions, topics suggested, and a derived engagement score per
 * participant per meeting.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-8
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for capturing and querying participant engagement data.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-8.1
 */
class EngagementService
{
    /**
     * Construct the EngagementService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Capture an engagement event (speech, question, or topic suggestion).
     *
     * If an EngagementRecord exists for the (meeting, participant) tuple,
     * the new event is appended. Otherwise a new record is created.
     *
     * @param string               $meetingId   UUID of the Meeting object
     * @param string               $participant Participant UUID
     * @param string               $eventType   One of 'speech', 'question', 'topic'
     * @param array<string, mixed> $eventData   Event payload
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When event type is unknown
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.1
     */
    public function captureEngagement(
        string $meetingId,
        string $participant,
        string $eventType,
        array $eventData
    ): array {
        $existing = $this->findEngagementForMeetingAndParticipant(meetingId: $meetingId, participant: $participant);

        $record = $existing;
        if ($existing === null) {
            $record = [
                'meeting'          => $meetingId,
                'participant'      => $participant,
                'speeches'         => [],
                'questionsRaised'  => [],
                'topicsSuggested'  => [],
                'speakingDuration' => 0,
                'engagementScore'  => 0,
            ];
        }

        switch ($eventType) {
            case 'speech':
                $record['speeches'][] = $eventData;
                $duration = (int) ($eventData['duration'] ?? 0);
                if ($duration === 0
                    && isset($eventData['startTime'], $eventData['endTime']) === true
                ) {
                    try {
                        $start    = new DateTimeImmutable((string) $eventData['startTime']);
                        $end      = new DateTimeImmutable((string) $eventData['endTime']);
                        $duration = max(0, $end->getTimestamp() - $start->getTimestamp());
                    } catch (Throwable $e) {
                        $duration = 0;
                    }
                }

                $record['speakingDuration'] = ((int) ($record['speakingDuration'] ?? 0)) + $duration;
                break;

            case 'question':
                $record['questionsRaised'][] = $eventData;
                break;

            case 'topic':
                $record['topicsSuggested'][] = $eventData;
                break;

            default:
                throw new InvalidArgumentException("Unknown engagement event type '$eventType'");
        }//end switch

        $record['engagementScore'] = $this->calculateScore(record: $record);

        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $record,
            register: 'decidesk',
            schema: 'engagement-record',
            uuid: ($existing['id'] ?? null),
        );

        $this->logger->info(
            'Decidesk: Engagement captured',
            ['meetingId' => $meetingId, 'participant' => $participant, 'eventType' => $eventType]
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end captureEngagement()

    /**
     * Find an existing EngagementRecord for a (meeting, participant) pair.
     *
     * @param string $meetingId   Meeting UUID
     * @param string $participant Participant UUID
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.1
     */
    public function findEngagementForMeetingAndParticipant(
        string $meetingId,
        string $participant
    ): ?array {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('engagement-record');

            // ObjectService::findAll() takes a single $config array — the
            // named-argument form (limit:/offset:/filters:) threw
            // "Unknown named parameter" and was swallowed by the catch below.
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'meeting'     => $meetingId,
                        'participant' => $participant,
                    ],
                    'limit'   => 1,
                    'offset'  => 0,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                'Decidesk: findEngagementForMeetingAndParticipant failed',
                ['error' => $e->getMessage()]
            );
            return null;
        }//end try

        foreach ($results as $entity) {
            if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                return $entity->getObject();
            }

            if (is_array($entity) === true) {
                return $entity;
            }
        }

        return null;

    }//end findEngagementForMeetingAndParticipant()

    /**
     * List EngagementRecords for a meeting.
     *
     * @param string $meetingId Meeting UUID
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.1
     */
    public function findEngagementForMeeting(string $meetingId): array
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('engagement-record');

            // ObjectService::findAll() takes a single $config array — the
            // named-argument form (limit:/offset:/filters:) threw
            // "Unknown named parameter" and was swallowed by the catch below.
            $results = $objectService->findAll(
                [
                    'filters' => ['meeting' => $meetingId],
                    'limit'   => 500,
                    'offset'  => 0,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Decidesk: findEngagementForMeeting failed',
                ['meetingId' => $meetingId, 'error' => $e->getMessage()]
            );
            return [];
        }//end try

        $out = [];
        foreach ($results as $entity) {
            if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                $out[] = $entity->getObject();
            } else if (is_array($entity) === true) {
                $out[] = $entity;
            }
        }

        return $out;

    }//end findEngagementForMeeting()

    /**
     * Calculate the engagement score (0-100) for a record.
     *
     * Simple heuristic combining speaking duration, questions, and topics.
     *
     * @param array<string, mixed> $record EngagementRecord
     *
     * @return int
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.1
     */
    public function calculateScore(array $record): int
    {
        $speakingMinutes = ((int) ($record['speakingDuration'] ?? 0)) / 60;

        $speeches = 0;
        if (is_array(($record['speeches'] ?? null)) === true) {
            $speeches = count($record['speeches']);
        }

        $questions = 0;
        if (is_array(($record['questionsRaised'] ?? null)) === true) {
            $questions = count($record['questionsRaised']);
        }

        $topics = 0;
        if (is_array(($record['topicsSuggested'] ?? null)) === true) {
            $topics = count($record['topicsSuggested']);
        }

        $score = (int) min(100, ($speakingMinutes * 2) + ($speeches * 5) + ($questions * 4) + ($topics * 6));
        return max(0, $score);

    }//end calculateScore()
}//end class
