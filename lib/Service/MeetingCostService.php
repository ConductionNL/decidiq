<?php

/**
 * Decidiq Meeting Cost Service
 *
 * Computes the cost of a meeting (elapsed time x attendee count x the
 * governance body's hourly rate). The live cost panel in the SPA computes a
 * figure for display only; the persisted `meetingCost` is stamped here, on
 * the server, from stored data so post-meeting analytics can trust it (a
 * client-computed persisted cost could be spoofed).
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
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pure cost math plus server-side resolution of the inputs from OpenRegister.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
class MeetingCostService {
	/**
	 * Construct the MeetingCostService.
	 *
	 * @param LoggerInterface $logger PSR-3 logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
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
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Pure cost formula: elapsed hours x attendee count x hourly rate.
	 *
	 * Mirrors src/utils/meetingCost.js so the live panel and the persisted
	 * figure agree. Negative inputs clamp to 0.
	 *
	 * @param int $elapsedSeconds Seconds the meeting was running
	 * @param int $attendeeCount Number of attendees
	 * @param float $hourlyRate Hourly rate in EUR per attendee
	 *
	 * @return float Cost in EUR (rounded to 2 decimals)
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	public function computeCost(int $elapsedSeconds, int $attendeeCount, float $hourlyRate): float {
		$seconds = max(0, $elapsedSeconds);
		$attendees = max(0, $attendeeCount);
		$rate = max(0.0, $hourlyRate);
		$cost = ($seconds / 3600) * $attendees * $rate;
		return round($cost, 2);
	}//end computeCost()

	/**
	 * Resolve a meeting's final cost from stored data.
	 *
	 * Reads the meeting's openedAt/closedAt window (falling back to now when
	 * closedAt is not yet set), counts the meeting's participants, and reads
	 * the linked governance body's `hourlyRate`. Returns null when no rate is
	 * configured (nothing should be persisted) — callers treat null as
	 * "no cost stamped".
	 *
	 * Uses the real OpenRegister ObjectService API (find/findAll, named args).
	 *
	 * @param string $meetingId Meeting UUID
	 * @param array<string, mixed>|null $meeting Optional pre-loaded meeting object
	 *                                           (avoids a re-read during the close transition)
	 *
	 * @return float|null Cost in EUR, or null when no rate is configured
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	public function calculateForMeeting(string $meetingId, ?array $meeting = null): ?float {
		$objectService = $this->getObjectService();

		if ($meeting === null) {
			$entity = $objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
			if ($entity === null) {
				return null;
			}

			$meeting = $entity->getObject();
		}

		$hourlyRate = $this->resolveHourlyRate(meeting: $meeting);
		if ($hourlyRate === null || $hourlyRate <= 0.0) {
			return null;
		}

		$elapsedSeconds = $this->resolveElapsedSeconds(meeting: $meeting);
		$attendeeCount = $this->resolveAttendeeCount(meetingId: $meetingId, meeting: $meeting);

		return $this->computeCost(
			elapsedSeconds: $elapsedSeconds,
			attendeeCount: $attendeeCount,
			hourlyRate: $hourlyRate
		);

	}//end calculateForMeeting()

	/**
	 * Resolve the hourly rate from the meeting's linked governance body.
	 *
	 * @param array<string, mixed> $meeting Meeting object
	 *
	 * @return float|null The body's hourlyRate, or null
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function resolveHourlyRate(array $meeting): ?float {
		$bodyId = $this->resolveGovernanceBodyId(meeting: $meeting);
		if ($bodyId === null) {
			return null;
		}

		try {
			$bodyEntity = $this->getObjectService()->find(
				id: $bodyId,
				register: 'decidiq',
				schema: 'governance-body'
			);
			if ($bodyEntity === null) {
				return null;
			}

			$body = $bodyEntity->getObject();
			$rate = ($body['hourlyRate'] ?? null);
			if ($rate === null || is_numeric($rate) === false) {
				return null;
			}

			return (float)$rate;
		} catch (Throwable $e) {
			$this->logger->debug(
				'Decidiq MeetingCostService: hourlyRate resolution failed',
				['error' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end resolveHourlyRate()

	/**
	 * Resolve the governance-body UUID from a meeting object (both relation
	 * shapes honoured, like ParticipantResolver).
	 *
	 * @param array<string, mixed> $meeting Meeting object
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function resolveGovernanceBodyId(array $meeting): ?string {
		$relations = ($meeting['@self']['relations'] ?? ($meeting['relations'] ?? []));
		if (is_array($relations) === true) {
			foreach ($relations as $key => $relation) {
				if (is_array($relation) === true) {
					if (($relation['schema'] ?? '') === 'governance-body') {
						return ($relation['id'] ?? null);
					}

					continue;
				}

				if (is_string($relation) === true && $key === 'governanceBody') {
					return $relation;
				}
			}
		}

		// Flat top-level field fallback.
		if (isset($meeting['governanceBody']) === true && is_string($meeting['governanceBody']) === true) {
			return $meeting['governanceBody'];
		}

		return null;
	}//end resolveGovernanceBodyId()

	/**
	 * Resolve elapsed seconds from the meeting's openedAt/closedAt stamps.
	 * Falls back to now when closedAt is absent; 0 when openedAt is absent.
	 *
	 * @param array<string, mixed> $meeting Meeting object
	 *
	 * @return int Elapsed seconds (>= 0)
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function resolveElapsedSeconds(array $meeting): int {
		$openedAt = ($meeting['openedAt'] ?? null);
		if ($openedAt === null || $openedAt === '') {
			return 0;
		}

		try {
			$start = new DateTimeImmutable((string)$openedAt);
			$closedAt = ($meeting['closedAt'] ?? null);
			$end = new DateTimeImmutable('now');
			if ($closedAt !== null && $closedAt !== '') {
				$end = new DateTimeImmutable((string)$closedAt);
			}

			return max(0, $end->getTimestamp() - $start->getTimestamp());
		} catch (Throwable $e) {
			return 0;
		}

	}//end resolveElapsedSeconds()

	/**
	 * Count the people who attended the meeting.
	 *
	 * Participant declares no `meeting` property, so a filter on it matched
	 * the wrong set. The attendees are the members of the meeting's body who
	 * are marked present; when nobody's attendance was taken, the body's
	 * roster is the attendance.
	 *
	 * @param string              $meetingId Meeting UUID (for the log)
	 * @param array<string,mixed> $meeting   The meeting object
	 *
	 * @return int Attendee count (>= 0)
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 */
	private function resolveAttendeeCount(string $meetingId, array $meeting): int {
		$bodyId = $this->resolveGovernanceBodyId(meeting: $meeting);
		if ($bodyId === null) {
			return 0;
		}

		try {
			$objectService = $this->getObjectService();
			$objectService->setRegister('decidiq');
			$objectService->setSchema('participant');

			$results = $objectService->findAll(['filters' => ['governanceBody' => $bodyId], 'limit' => 500]);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Decidiq MeetingCostService: attendee count failed',
				['meetingId' => $meetingId, 'error' => $e->getMessage()]
			);
			return 0;
		}

		$attendance = new MeetingAttendanceReader(objectService: $this->objectService, logger: $this->logger);

		return $this->countAttendees(
			results: $results,
			bodyId: $bodyId,
			attendance: $attendance,
			statuses: $attendance->statusesFor(meetingId: $meetingId)
		);

	}//end resolveAttendeeCount()

	/**
	 * Count the body's members marked present, or the whole roster when
	 * nobody's attendance was taken.
	 *
	 * @param iterable<mixed>       $results  Participants as found (entities or arrays)
	 * @param string                $bodyId   The meeting's governance body
	 * @param MeetingAttendanceReader $attendance Overlays this meeting's attendance
	 * @param array<string, string> $statuses This meeting's attendance per participant (pla-09)
	 *
	 * @return int Attendee count (>= 0)
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	private function countAttendees(iterable $results, string $bodyId, MeetingAttendanceReader $attendance, array $statuses): int {
		$members = 0;
		$present = 0;
		$attendanceTaken = false;
		foreach ($results as $result) {
			$participant = $this->participantData(result: $result);
			if ($participant === null || ($participant['governanceBody'] ?? $bodyId) !== $bodyId) {
				continue;
			}

			$members++;
			$participant = $attendance->overlay(participants: [$participant], statuses: $statuses)[0];
			$status = (string)($participant['attendanceStatus'] ?? '');
			$attendanceTaken = ($attendanceTaken === true || $status !== '');
			if ($status === 'present') {
				$present++;
			}
		}

		if ($attendanceTaken === true) {
			return $present;
		}

		return $members;

	}//end countAttendees()

	/**
	 * The participant's data, from an entity or an already serialised array.
	 *
	 * @param mixed $result One findAll() result
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 */
	private function participantData(mixed $result): ?array {
		$uuid = null;
		if (is_object($result) === true && method_exists($result, 'getObject') === true) {
			// The properties come from getObject(); the id comes from the
			// entity, so this meeting's attendance can be matched to it.
			if (method_exists($result, 'getUuid') === true) {
				$uuid = $result->getUuid();
			}

			$result = $result->getObject();
		}

		if (is_array($result) === false) {
			return null;
		}

		if (is_string($uuid) === true && $uuid !== '' && isset($result['id']) === false) {
			$result['id'] = $uuid;
		}

		return $result;

	}//end participantData()
}//end class
