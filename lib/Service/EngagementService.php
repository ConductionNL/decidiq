<?php

/**
 * Decidiq Engagement Service
 *
 * Stateless service handling EngagementRecord aggregation: speeches,
 * questions, topics suggested, and a derived engagement score per
 * participant per meeting.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Kernel\Contracts\EngagementServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for capturing and querying participant engagement data.
 *
 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
 */
class EngagementService implements EngagementServiceInterface {

	/**
	 * The record list each event type is appended to.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_LISTS = [
		'speech' => 'speeches',
		'question' => 'questionsRaised',
		'topic' => 'topicsSuggested',
	];
	/**
	 * Construct the EngagementService.
	 *
	 * @param LoggerInterface $logger Logger interface
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get the OpenRegister ObjectService from the container.
	 *
	 * @return object
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Capture an engagement event (speech, question, or topic suggestion).
	 *
	 * If an EngagementRecord exists for the (meeting, participant) tuple,
	 * the new event is appended. Otherwise a new record is created.
	 *
	 * @param string $meetingId UUID of the Meeting object
	 * @param string $participant Participant UUID
	 * @param string $eventType One of 'speech', 'question', 'topic'
	 * @param array<string, mixed> $eventData Event payload
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When event type is unknown
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	public function captureEngagement(
		string $meetingId,
		string $participant,
		string $eventType,
		array $eventData,
	): array {
		$existing = $this->findEngagementForMeetingAndParticipant(meetingId: $meetingId, participant: $participant);
		$record = $existing ?? $this->newRecord(meetingId: $meetingId, participant: $participant);
		$record = $this->applyEvent(record: $record, event: ['type' => $eventType, 'data' => $eventData]);
		$record['engagementScore'] = $this->calculateScore(record: $record);

		$saved = $this->getObjectService()->saveObject(object: $record, register: 'decidiq', schema: 'engagement-record', uuid: ($existing['id'] ?? null));
		$this->logger->info('Decidiq: Engagement captured', ['meetingId' => $meetingId, 'participant' => $participant, 'eventType' => $eventType]);
		return $this->savedRecord(saved: $saved);
	}//end captureEngagement()

	/**
	 * A new, empty engagement record of one participant in one meeting.
	 *
	 * @param string $meetingId Meeting UUID
	 * @param string $participant Participant UUID
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function newRecord(string $meetingId, string $participant): array {
		return [
			'meeting' => $meetingId,
			'participant' => $participant,
			'speeches' => [],
			'questionsRaised' => [],
			'topicsSuggested' => [],
			'speakingDuration' => 0,
			'engagementScore' => 0,
		];
	}//end newRecord()

	/**
	 * The saved record as an array.
	 *
	 * @param mixed $saved What saveObject() returned
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function savedRecord(mixed $saved): array {
		if (is_array($saved) === true) {
			return $saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
			return (array)$saved->getObject();
		}

		return (array)$saved;
	}//end savedRecord()

	/**
	 * Fold one engagement event into the record it belongs to.
	 *
	 * @param array<string, mixed> $record The engagement record
	 * @param array{type: string, data: array<string, mixed>} $event The event type ('speech', 'question' or 'topic') and its payload
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @throws InvalidArgumentException When event type is unknown
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function applyEvent(array $record, array $event): array {
		$list = self::EVENT_LISTS[$event['type']] ?? null;
		if ($list === null) {
			throw new InvalidArgumentException("Unknown engagement event type '{$event['type']}'");
		}

		$record[$list][] = $event['data'];
		if ($event['type'] === 'speech') {
			$record['speakingDuration'] = (((int)($record['speakingDuration'] ?? 0)) + $this->speechDuration(eventData: $event['data']));
		}

		return $record;
	}//end applyEvent()

	/**
	 * The duration of one speech event, in seconds.
	 *
	 * Prefers the reported duration; when it is absent or zero, derives it from
	 * startTime/endTime. An unparseable timestamp pair yields zero.
	 *
	 * @param array<string, mixed> $eventData Event payload
	 *
	 * @return int The duration in seconds.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function speechDuration(array $eventData): int {
		$duration = (int)($eventData['duration'] ?? 0);
		if ($duration !== 0) {
			return $duration;
		}

		if (isset($eventData['startTime'], $eventData['endTime']) === false) {
			return 0;
		}

		return $this->spanSeconds(eventData: $eventData);

	}//end speechDuration()

	/**
	 * Seconds from a speech's start to its end, at least 0; 0 when either
	 * time does not parse.
	 *
	 * @param array<string, mixed> $eventData Speech event data with startTime and endTime
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function spanSeconds(array $eventData): int {
		try {
			$start = new DateTimeImmutable((string)$eventData['startTime']);
			$end = new DateTimeImmutable((string)$eventData['endTime']);
			return max(0, ($end->getTimestamp() - $start->getTimestamp()));
		} catch (Throwable $e) {
			return 0;
		}

	}//end spanSeconds()

	/**
	 * Find an existing EngagementRecord for a (meeting, participant) pair.
	 *
	 * @param string $meetingId Meeting UUID
	 * @param string $participant Participant UUID
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	public function findEngagementForMeetingAndParticipant(
		string $meetingId,
		string $participant,
	): ?array {
		try {
			$results = $this->queryEngagement(query: ['filters' => ['meeting' => $meetingId, 'participant' => $participant], 'limit' => 1, 'offset' => 0]);
		} catch (Throwable $e) {
			$this->logger->debug('Decidiq: findEngagementForMeetingAndParticipant failed', ['error' => $e->getMessage()]);
			return null;
		}

		$records = $this->recordsOf(results: $results);
		return $records[0] ?? null;
	}//end findEngagementForMeetingAndParticipant()

	/**
	 * Query the engagement records.
	 *
	 * @param array<string, mixed> $query The findAll() config
	 *
	 * @throws Throwable When the query fails
	 *
	 * @return iterable<mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function queryEngagement(array $query): iterable {
		$objectService = $this->getObjectService();
		$objectService->setRegister('decidiq');
		$objectService->setSchema('engagement-record');
		return $objectService->findAll($query);
	}//end queryEngagement()

	/**
	 * The records among query results: entities as their object, arrays as
	 * they are, anything else left out.
	 *
	 * @param iterable<mixed> $results The query results
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function recordsOf(iterable $results): array {
		$out = [];
		foreach ($results as $entity) {
			if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
				$out[] = $entity->getObject();
			} elseif (is_array($entity) === true) {
				$out[] = $entity;
			}
		}

		return $out;
	}//end recordsOf()

	/**
	 * List EngagementRecords for a meeting.
	 *
	 * @param string $meetingId Meeting UUID
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	public function findEngagementForMeeting(string $meetingId): array {
		try {
			$results = $this->queryEngagement(query: ['filters' => ['meeting' => $meetingId], 'limit' => 500, 'offset' => 0]);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: findEngagementForMeeting failed', ['meetingId' => $meetingId, 'error' => $e->getMessage()]);
			return [];
		}

		return $this->recordsOf(results: $results);
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
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	public function calculateScore(array $record): int {
		$speakingMinutes = ((int)($record['speakingDuration'] ?? 0)) / 60;
		$speeches = $this->countOf(record: $record, key: 'speeches');
		$questions = $this->countOf(record: $record, key: 'questionsRaised');
		$topics = $this->countOf(record: $record, key: 'topicsSuggested');

		$score = (int)min(100, ($speakingMinutes * 2) + ($speeches * 5) + ($questions * 4) + ($topics * 6));
		return max(0, $score);
	}//end calculateScore()

	/**
	 * The number of entries in one of the record's lists; 0 when it is not a list.
	 *
	 * @param array<string, mixed> $record The engagement record
	 * @param string $key The list
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	private function countOf(array $record, string $key): int {
		if (is_array(($record[$key] ?? null)) === true) {
			return count($record[$key]);
		}

		return 0;
	}//end countOf()
}//end class
