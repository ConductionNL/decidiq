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

		$records = array_map(
			static fn (array $record): array => self::withId(record: $record),
			MeetingScopedRecords::ofMeeting(rows: $this->rowsFor(meetingId: $meetingId), meetingId: $meetingId)
		);
		usort($records, static fn (array $a, array $b): int => strcmp((string)($a['startedAt'] ?? ''), (string)($b['startedAt'] ?? '')));

		$this->cache[$meetingId] = $records;
		return $records;

	}//end allFor()

	/**
	 * The substitution rows the register answers for a meeting; none when the
	 * read fails, so every member keeps their seat.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<int|string, mixed> The rows
	 */
	private function rowsFor(string $meetingId): array {
		try {
			return (new MeetingScopedRecords(objectService: $this->objectService, schema: self::SCHEMA))->rowsFor(meetingId: $meetingId);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Decidiq: substitutions of a meeting could not be read; every member keeps their seat',
				['meetingId' => $meetingId, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end rowsFor()

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
			if (MeetingScopedRecords::refId(ref: ($record['outgoingParticipant'] ?? null)) === $participantId) {
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

		return MeetingScopedRecords::refId(ref: ($record['incomingParticipant'] ?? null));
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
		return array_map(
			fn (array $record): string => MeetingScopedRecords::refId(ref: ($record['incomingParticipant'] ?? null)),
			$this->activeFor(meetingId: $meetingId)
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

		$sides = self::seatSides(active: $active, participants: $participants);
		$seats = [];
		foreach ($participants as $row) {
			$seat = $this->seatOf(row: $row, sides: $sides);
			if ($seat !== null) {
				$seats[] = $seat;
			}
		}

		return $seats;

	}//end seatsForQuorum()

	/**
	 * Who left a seat and who took one, and whether attendance was taken.
	 *
	 * @param list<array<string, mixed>>       $active       The active substitutions
	 * @param array<int, array<string, mixed>> $participants The participants
	 *
	 * @return array{outgoing: array<string, true>, incoming: array<string, true>, attendanceTaken: bool}
	 */
	private static function seatSides(array $active, array $participants): array {
		$sides = [
			'outgoing' => [],
			'incoming' => [],
			'attendanceTaken' => array_filter($participants, static fn (array $row): bool => (string)($row['attendanceStatus'] ?? '') !== '') !== [],
		];
		foreach ($active as $record) {
			$sides['outgoing'][MeetingScopedRecords::refId(ref: ($record['outgoingParticipant'] ?? null))] = true;
			$sides['incoming'][MeetingScopedRecords::refId(ref: ($record['incomingParticipant'] ?? null))] = true;
		}

		return $sides;
	}//end seatSides()

	/**
	 * The seat a participant takes in the quorum: none for a substitute, and
	 * present for the member they replaced once attendance was taken.
	 *
	 * @param array<string, mixed>                                                                         $row   The participant
	 * @param array{outgoing: array<string, true>, incoming: array<string, true>, attendanceTaken: bool} $sides From seatSides()
	 *
	 * @return array<string, mixed>|null The seat, or null when the participant fills another's
	 */
	private function seatOf(array $row, array $sides): ?array {
		$id = $this->idOf(row: $row);
		if (isset($sides['incoming'][$id]) === true) {
			return null;
		}

		if (isset($sides['outgoing'][$id]) === true && $sides['attendanceTaken'] === true) {
			$row['attendanceStatus'] = 'present';
		}

		return $row;
	}//end seatOf()

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
		return MeetingScopedRecords::idOf(row: $row);
	}//end idOf()

	/**
	 * A substitution record that always carries its id.
	 *
	 * @param array<string, mixed> $record The record
	 *
	 * @return array<string, mixed>
	 */
	private static function withId(array $record): array {
		if (isset($record['id']) === false) {
			$record['id'] = MeetingScopedRecords::idOf(row: $record);
		}

		return $record;
	}//end withId()
}//end class
