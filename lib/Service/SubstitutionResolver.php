<?php

/**
 * Decidiq Substitution Resolver
 *
 * Answers "who holds this seat now" for one meeting (bodies-substitute-mandate-swap,
 * design D2). A mandate substitution names the member who left and the
 * substitute who took the seat; while it has no end time the substitute votes
 * for the seat and the member does not. The three voting paths (who may cast,
 * the quorum, a voting group preset) ask this resolver instead of editing the
 * body-wide participants. The active substitutions of a meeting are read once
 * per resolver.
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
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The seats of a meeting as its substitutions change them.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
 */
class SubstitutionResolver {

	/**
	 * The schema of a substitution.
	 *
	 * @var string
	 */
	public const SCHEMA = 'mandate-substitution';

	/**
	 * Every substitution of a meeting, by meeting id, read once.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the substitutions
	 * @param LoggerInterface|null   $logger        Logs a failed read
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ?LoggerInterface $logger=null,
	) {
	}//end __construct()

	/**
	 * Every substitution of a meeting, active and ended, oldest first.
	 *
	 * A failed read answers no substitutions, so every member votes in their
	 * own seat, which is what the meeting did before this change.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return list<array<string, mixed>> Each with its `id`
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member
	 */
	public function allFor(string $meetingId): array {
		if ($meetingId === '') {
			return [];
		}

		if (isset($this->cache[$meetingId]) === true) {
			return $this->cache[$meetingId];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema'   => self::SCHEMA,
						'meeting'  => $meetingId,
					],
					'limit'   => 1000,
				]
			);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Decidiq: substitutions of a meeting could not be read; every member keeps their seat',
				['meetingId' => $meetingId, 'exception' => $e->getMessage()]
			);
			$rows = [];
		}

		$records = [];
		foreach ($rows as $row) {
			$record = $this->data(row: $row);
			if ($this->refId(ref: ($record['meeting'] ?? null)) === $meetingId) {
				$records[] = $record;
			}
		}

		usort($records, static fn (array $a, array $b): int => strcmp((string)($a['startedAt'] ?? ''), (string)($b['startedAt'] ?? '')));

		$this->cache[$meetingId] = $records;
		return $records;

	}//end allFor()

	/**
	 * The substitutions of a meeting that have not ended.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function activeFor(string $meetingId): array {
		return array_values(
			array_filter(
				$this->allFor(meetingId: $meetingId),
				static fn (array $record): bool => (string)($record['endedAt'] ?? '') === ''
			)
		);
	}//end activeFor()

	/**
	 * The active substitution that took this member's seat, or null.
	 *
	 * @param string $meetingId     The meeting
	 * @param string $participantId The member
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function substitutionOf(string $meetingId, string $participantId): ?array {
		foreach ($this->activeFor(meetingId: $meetingId) as $record) {
			if ($this->refId(ref: ($record['outgoingParticipant'] ?? null)) === $participantId) {
				return $record;
			}
		}

		return null;
	}//end substitutionOf()

	/**
	 * Whether a substitute holds this member's seat now.
	 *
	 * @param string $meetingId     The meeting
	 * @param string $participantId The member
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function isSubstitutedOut(string $meetingId, string $participantId): bool {
		return $this->substitutionOf(meetingId: $meetingId, participantId: $participantId) !== null;
	}//end isSubstitutedOut()

	/**
	 * Who votes for this member's seat now: the substitute, or the member.
	 *
	 * @param string $meetingId     The meeting
	 * @param string $participantId The member
	 *
	 * @return string The participant id
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function seatHolderFor(string $meetingId, string $participantId): string {
		$record = $this->substitutionOf(meetingId: $meetingId, participantId: $participantId);
		if ($record === null) {
			return $participantId;
		}

		return $this->refId(ref: ($record['incomingParticipant'] ?? null));
	}//end seatHolderFor()

	/**
	 * The participants who hold a seat as a substitute now.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function activeSubstitutes(string $meetingId): array {
		return array_values(
			array_map(
				fn (array $record): string => $this->refId(ref: ($record['incomingParticipant'] ?? null)),
				$this->activeFor(meetingId: $meetingId)
			)
		);
	}//end activeSubstitutes()

	/**
	 * The participants as the quorum counts them: a substituted seat counts
	 * once, through its substitute.
	 *
	 * The substitute's own row is dropped, so the seat is not counted twice,
	 * and the member's row stands for the filled seat: when this meeting's
	 * attendance is taken, the seat is present.
	 *
	 * @param string                           $meetingId    The meeting
	 * @param list<array<string, mixed>>       $participants The meeting's participants, attendance applied
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function seatsForQuorum(string $meetingId, array $participants): array {
		$active = $this->activeFor(meetingId: $meetingId);
		if ($active === []) {
			return $participants;
		}

		$attendanceTaken = array_filter($participants, static fn (array $row): bool => (string)($row['attendanceStatus'] ?? '') !== '') !== [];
		$outgoing = [];
		$incoming = [];
		foreach ($active as $record) {
			$outgoing[$this->refId(ref: ($record['outgoingParticipant'] ?? null))] = true;
			$incoming[$this->refId(ref: ($record['incomingParticipant'] ?? null))] = true;
		}

		$seats = [];
		foreach ($participants as $row) {
			$id = $this->idOf(row: $row);
			if (isset($incoming[$id]) === true) {
				continue;
			}

			if (isset($outgoing[$id]) === true && $attendanceTaken === true) {
				$row['attendanceStatus'] = 'present';
			}

			$seats[] = $row;
		}

		return $seats;

	}//end seatsForQuorum()

	/**
	 * The id of a participant row.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function idOf(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return (string)($row['id'] ?? $row['uuid'] ?? $self['id'] ?? '');
	}//end idOf()

	/**
	 * An object as an array.
	 *
	 * @param mixed $row An ObjectEntity or an array
	 *
	 * @return array<string, mixed>
	 */
	private function data(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		if (isset($row['id']) === false) {
			$row['id'] = $this->idOf(row: $row);
		}

		return $row;
	}//end data()

	/**
	 * The id a relation value points at.
	 *
	 * @param mixed $ref A uuid string or a resolved object
	 *
	 * @return string
	 */
	private function refId(mixed $ref): string {
		if (is_string($ref) === true) {
			return $ref;
		}

		if (is_array($ref) === true) {
			return (string)($ref['id'] ?? $ref['uuid'] ?? '');
		}

		return '';
	}//end refId()
}//end class
