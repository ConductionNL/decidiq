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
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => self::SCHEMA,
						'meeting' => $meetingId,
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Decidiq: attendance of a meeting could not be read, using the participant value',
				['meetingId' => $meetingId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$statuses = [];
		foreach ($rows as $row) {
			$record = self::data(row: $row);
			if (self::refId(ref: ($record['meeting'] ?? null)) !== $meetingId) {
				continue;
			}

			$participant = self::refId(ref: ($record['participant'] ?? null));
			if ($participant !== '') {
				$statuses[$participant] = (string)($record['status'] ?? '');
			}
		}

		return $statuses;

	}//end statusesFor()

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
	public static function overlay(array $participants, array $statuses): array {
		if ($statuses === []) {
			return $participants;
		}

		foreach ($participants as $index => $participant) {
			$participants[$index]['attendanceStatus'] = ($statuses[self::idOf(row: $participant)] ?? '');
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
	public static function idOf(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return (string)($row['id'] ?? $row['uuid'] ?? $self['id'] ?? '');

	}//end idOf()

	/**
	 * An object as an array, from an entity or an array.
	 *
	 * @param mixed $row One findAll() result
	 *
	 * @return array<string, mixed>
	 */
	private static function data(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;

	}//end data()

	/**
	 * Read a reference that is a bare uuid or an expanded object.
	 *
	 * @param mixed $ref The reference
	 *
	 * @return string
	 */
	private static function refId(mixed $ref): string {
		if (is_string($ref) === true) {
			return $ref;
		}

		if (is_array($ref) === true) {
			return (string)($ref['id'] ?? $ref['uuid'] ?? '');
		}

		return '';

	}//end refId()
}//end class
