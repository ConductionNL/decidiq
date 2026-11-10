<?php

/**
 * Decidiq Meeting Scoped Records
 *
 * The register objects that belong to one meeting, such as its attendance or
 * its mandate substitutions: the query that reads them, the rows that really
 * name the meeting, and the ids and references on those rows.
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

/**
 * Reads one schema's objects for a meeting.
 *
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
final class MeetingScopedRecords {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param string                 $schema        The schema slug of the records
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly string $schema,
	) {
	}//end __construct()

	/**
	 * The rows the register answers for a meeting, as it answers them.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<int|string, mixed> The rows
	 *
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	public function rowsFor(string $meetingId): array {
		return $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => $this->schema,
					'meeting' => $meetingId,
				],
				'limit' => 1000,
			]
		);
	}//end rowsFor()

	/**
	 * The rows, as arrays, whose meeting reference is this meeting. The filter
	 * is re-checked here rather than trusted.
	 *
	 * @param iterable<mixed> $rows      The rows read
	 * @param string          $meetingId The meeting UUID
	 *
	 * @return list<array<string, mixed>> The records, in read order
	 *
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	public static function ofMeeting(iterable $rows, string $meetingId): array {
		$records = [];
		foreach ($rows as $row) {
			$record = self::data(row: $row);
			if (self::refId(ref: ($record['meeting'] ?? null)) === $meetingId) {
				$records[] = $record;
			}
		}

		return $records;
	}//end ofMeeting()

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
	 * Read a reference that is a bare uuid or an expanded object.
	 *
	 * @param mixed $ref The reference
	 *
	 * @return string
	 *
	 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
	 */
	public static function refId(mixed $ref): string {
		if (is_string($ref) === true) {
			return $ref;
		}

		if (is_array($ref) === true) {
			return (string)($ref['id'] ?? $ref['uuid'] ?? '');
		}

		return '';
	}//end refId()

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
}//end class
