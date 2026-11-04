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
		$meeting = $this->resolveMeeting(meetingId: $meetingId, meeting: $meeting);
		if ($meeting === null) {
			return null;
		}

		$hourlyRate = $this->resolveHourlyRate(meeting: $meeting);
		if ($hourlyRate === null) {
			return null;
		}

		$elapsedSeconds = $this->resolveElapsedSeconds(meeting: $meeting);
		$attendeeCount = $this->resolveAttendeeCount(meetingId: $meetingId, meeting: $meeting);

		return $this->computeCost(elapsedSeconds: $elapsedSeconds, attendeeCount: $attendeeCount, hourlyRate: $hourlyRate);

	}//end calculateForMeeting()

	/**
	 * The pre-loaded meeting, or the meeting read from OpenRegister.
	 *
	 * @param string                    $meetingId Meeting UUID
	 * @param array<string, mixed>|null $meeting   Optional pre-loaded meeting object
	 *
	 * @return array<string, mixed>|null The meeting, or null when it does not exist
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function resolveMeeting(string $meetingId, ?array $meeting): ?array {
		if ($meeting !== null) {
			return $meeting;
		}

		$entity = $this->getObjectService()->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($entity === null) {
			return null;
		}

		return $entity->getObject();

	}//end resolveMeeting()

	/**
	 * Resolve the hourly rate from the meeting's linked governance body.
	 *
	 * @param array<string, mixed> $meeting Meeting object
	 *
	 * @return float|null The body's hourlyRate, or null when it is not above zero
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function resolveHourlyRate(array $meeting): ?float {
		$bodyId = $this->resolveGovernanceBodyId(meeting: $meeting);
		if ($bodyId === null) {
			return null;
		}

		try {
			return $this->bodyHourlyRate(bodyId: $bodyId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Decidiq MeetingCostService: hourlyRate resolution failed',
				['error' => $e->getMessage()]
			);
			return null;
		}

	}//end resolveHourlyRate()

	/**
	 * The numeric hourlyRate of a governance body.
	 *
	 * @param string $bodyId Governance body UUID
	 *
	 * @return float|null The rate, or null when the body or a numeric rate above zero is missing
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function bodyHourlyRate(string $bodyId): ?float {
		$bodyEntity = $this->getObjectService()->find(id: $bodyId, register: 'decidiq', schema: 'governance-body');
		if ($bodyEntity === null) {
			return null;
		}

		$rate = ($bodyEntity->getObject()['hourlyRate'] ?? null);
		if (is_numeric($rate) === false || (float)$rate <= 0.0) {
			return null;
		}

		return (float)$rate;

	}//end bodyHourlyRate()

	/**
	 * Resolve the governance-body UUID from a meeting object (both relation
	 * shapes honoured, like ParticipantResolver), then from the flat field.
	 *
	 * @param array<string, mixed> $meeting Meeting object
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function resolveGovernanceBodyId(array $meeting): ?string {
		$match = self::relationsBodyId(relations: self::relationsOf(meeting: $meeting));
		if ($match !== []) {
			return $match[0];
		}

		$bodyId = ($meeting['governanceBody'] ?? null);
		if (is_string($bodyId) === true) {
			return $bodyId;
		}

		return null;
	}//end resolveGovernanceBodyId()

	/**
	 * The meeting's relations, from `@self` or the top level; empty when they are not an array.
	 *
	 * @param array<string, mixed> $meeting Meeting object
	 *
	 * @return array<mixed>
	 */
	private static function relationsOf(array $meeting): array {
		$relations = ($meeting['@self']['relations'] ?? ($meeting['relations'] ?? []));
		if (is_array($relations) === false) {
			return [];
		}

		return $relations;
	}//end relationsOf()

	/**
	 * The first relation that names the governance body.
	 *
	 * @param array<mixed> $relations The meeting's relations
	 *
	 * @return array{0?: mixed} The body id wrapped in a list, or empty when no relation names it
	 */
	private static function relationsBodyId(array $relations): array {
		foreach ($relations as $key => $relation) {
			$match = self::relationBodyId(key: $key, relation: $relation);
			if ($match !== []) {
				return $match;
			}
		}

		return [];
	}//end relationsBodyId()

	/**
	 * The body id one relation names: a relation object of the governance-body
	 * schema, or a uuid under the `governanceBody` key.
	 *
	 * @param int|string $key      The relation key
	 * @param mixed      $relation The relation
	 *
	 * @return array{0?: mixed} The body id wrapped in a list, or empty when this relation names none
	 */
	private static function relationBodyId(int|string $key, mixed $relation): array {
		if (is_array($relation) === true) {
			if (($relation['schema'] ?? '') !== 'governance-body') {
				return [];
			}

			return [($relation['id'] ?? null)];
		}

		if (is_string($relation) === true && $key === 'governanceBody') {
			return [$relation];
		}

		return [];
	}//end relationBodyId()

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
			return max(0, self::endOf(meeting: $meeting)->getTimestamp() - $start->getTimestamp());
		} catch (Throwable $e) {
			return 0;
		}

	}//end resolveElapsedSeconds()

	/**
	 * When the meeting ended: its closedAt stamp, or now while it has none.
	 *
	 * @param array<string, mixed> $meeting Meeting object
	 *
	 * @return DateTimeImmutable
	 */
	private static function endOf(array $meeting): DateTimeImmutable {
		$closedAt = ($meeting['closedAt'] ?? null);
		if ($closedAt === null || $closedAt === '') {
			return new DateTimeImmutable('now');
		}

		return new DateTimeImmutable((string)$closedAt);
	}//end endOf()

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

		$results = $this->findBodyParticipants(meetingId: $meetingId, bodyId: $bodyId);
		if ($results === null) {
			return 0;
		}

		$attendance = new MeetingAttendanceReader(objectService: $this->objectService, logger: $this->logger);
		$statuses = $attendance->statusesFor(meetingId: $meetingId);
		$overlay = static fn (array $participant): array => $attendance->overlay(participants: [$participant], statuses: $statuses)[0];

		return $this->countAttendees(results: $results, tally: new MeetingAttendeeTally(bodyId: $bodyId, overlay: $overlay));

	}//end resolveAttendeeCount()

	/**
	 * The participants of a governance body.
	 *
	 * @param string $meetingId Meeting UUID (for the log)
	 * @param string $bodyId    Governance body UUID
	 *
	 * @return array<mixed>|null The participants as found, or null when they cannot be read
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 */
	private function findBodyParticipants(string $meetingId, string $bodyId): ?array {
		try {
			$objectService = $this->getObjectService();
			$objectService->setRegister('decidiq');
			$objectService->setSchema('participant');

			return $objectService->findAll(['filters' => ['governanceBody' => $bodyId], 'limit' => 500]);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Decidiq MeetingCostService: attendee count failed',
				['meetingId' => $meetingId, 'error' => $e->getMessage()]
			);
			return null;
		}

	}//end findBodyParticipants()

	/**
	 * Count the body's members marked present, or the whole roster when
	 * nobody's attendance was taken.
	 *
	 * @param iterable<mixed>      $results Participants as found (entities or arrays)
	 * @param MeetingAttendeeTally $tally   Counts the body's members and who was present
	 *
	 * @return int Attendee count (>= 0)
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	private function countAttendees(iterable $results, MeetingAttendeeTally $tally): int {
		foreach ($results as $result) {
			$tally->add(participant: $this->participantData(result: $result));
		}

		return $tally->count();

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
			$uuid = self::entityUuid(entity: $result);
			$result = $result->getObject();
		}

		if (is_array($result) === false) {
			return null;
		}

		return self::withUuid(participant: $result, uuid: $uuid);

	}//end participantData()

	/**
	 * The uuid of an entity that has a getUuid() method.
	 *
	 * @param object $entity One findAll() result
	 *
	 * @return mixed The uuid, or null when the entity has no getUuid()
	 */
	private static function entityUuid(object $entity): mixed {
		if (method_exists($entity, 'getUuid') === true) {
			return $entity->getUuid();
		}

		return null;
	}//end entityUuid()

	/**
	 * The participant with the entity uuid as its id, when it has none of its own.
	 *
	 * @param array<string,mixed> $participant The participant's data
	 * @param mixed               $uuid        The entity uuid
	 *
	 * @return array<string,mixed>
	 */
	private static function withUuid(array $participant, mixed $uuid): array {
		if (is_string($uuid) === true && $uuid !== '' && isset($participant['id']) === false) {
			$participant['id'] = $uuid;
		}

		return $participant;
	}//end withUuid()
}//end class
