<?php

/**
 * Decidiq Meeting Attendance Reader
 *
 * Reads the attendance recorded for one meeting (meeting-attendance-per-meeting,
 * pla-09). Each MeetingAttendance object says whether one participant was
 * present, absent, excused or represented at one meeting, so a later meeting
 * no longer overwrites an earlier one. A meeting without attendance records
 * falls back to the participant's own attendanceStatus, which is what every
 * meeting read before this change.
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
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The attendance recorded for a meeting, per participant.
 *
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
final class MeetingAttendanceReader {

	/**
	 * The schema slug of an attendance record.
	 *
	 * @var string
	 */
	public const SCHEMA = 'meeting-attendance';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
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
	 * The status recorded per participant for this meeting. Empty when the
	 * meeting has no attendance records, or when they cannot be read: the
	 * callers then keep the participant's own attendanceStatus.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<string, string> Participant UUID => status
	 *
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	public function statusesFor(string $meetingId): array {
		if ($meetingId === '') {
			return [];
		}

		try {
			$rows = (new MeetingScopedRecords(objectService: $this->objectService, schema: self::SCHEMA))->rowsFor(meetingId: $meetingId);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Decidiq: attendance of a meeting could not be read, using the participant value',
				['meetingId' => $meetingId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return self::statusesOf(records: MeetingScopedRecords::ofMeeting(rows: $rows, meetingId: $meetingId));

	}//end statusesFor()

	/**
	 * The status each attendance record gives its participant; a later record wins.
	 *
	 * @param list<array<string, mixed>> $records The meeting's attendance records
	 *
	 * @return array<string, string> Participant UUID => status
	 */
	private static function statusesOf(array $records): array {
		$statuses = [];
		foreach ($records as $record) {
			$participant = MeetingScopedRecords::refId(ref: ($record['participant'] ?? null));
			if ($participant !== '') {
				$statuses[$participant] = (string)($record['status'] ?? '');
			}
		}

		return $statuses;

	}//end statusesOf()

	/**
	 * The participants with this meeting's status as their attendanceStatus.
	 * With no records the participants are returned unchanged; with records,
	 * a participant without one reads as not recorded ('').
	 *
	 * @param array<int, array<string, mixed>> $participants The participants
	 * @param array<string, string>            $statuses     From statusesFor()
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	public function overlay(array $participants, array $statuses): array {
		if ($statuses === []) {
			return $participants;
		}

		foreach ($participants as $index => $participant) {
			$participants[$index]['attendanceStatus'] = ($statuses[$this->idOf(row: $participant)] ?? '');
		}

		return $participants;

	}//end overlay()

	/**
	 * The UUID of a serialised object.
	 *
	 * @param array<string, mixed> $row The object
	 *
	 * @return string
	 *
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	public function idOf(array $row): string {
		return MeetingScopedRecords::idOf(row: $row);

	}//end idOf()
}//end class
