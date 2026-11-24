<?php

/**
 * Decidiq meeting command engine.
 *
 * Applies a cross-app command to hold a Meeting for an object in a consuming
 * app (a case session with parties, a site visit). The engine behind
 * MeetingRequestedListener, kept apart from it so what is resolved before what
 * is written, and what is refused, is testable without a dispatcher.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\Kernel\Contracts\MeetingCommandServiceInterface;
use RuntimeException;
use Throwable;

/**
 * Idempotent upsert of a Meeting that belongs to a consumer's object.
 *
 * The subject (schema and uuid) is the key: a repeated command for the same
 * object updates the one meeting held for it, so a producer that re-plans a
 * session moves its meeting instead of minting a second one.
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 */
class MeetingCommandService implements MeetingCommandServiceInterface {

	/**
	 * Schema slug of a meeting.
	 */
	private const SCHEMA_MEETING = 'meeting';

	/**
	 * The meeting type a command gets when it names none.
	 *
	 * A session a consuming app holds with the parties of one of its objects is
	 * a committee-style sitting, not a council meeting.
	 */
	private const DEFAULT_MEETING_TYPE = 'committee';

	/**
	 * The attendance mode a command gets when it names none.
	 */
	private const DEFAULT_MEETING_MODE = 'in-person';

	/**
	 * The lifecycle state a new meeting opens in: planned, not yet opened.
	 */
	private const NEW_LIFECYCLE = 'scheduled';

	/**
	 * Meeting fields a command may set beyond title and start.
	 *
	 * A closed list, not a passthrough: an open merge would let a producer write
	 * `lifecycle` or the subject fields themselves and break the key this
	 * service resolves on.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_ATTRIBUTES = [
		'endDate',
		'location',
		'meetingType',
		'meetingMode',
		'governanceBody',
		'virtualLocation',
		'isPublic',
	];

	/**
	 * The values Meeting.meetingType accepts.
	 *
	 * @var list<string>
	 */
	private const MEETING_TYPES = [
		'regular',
		'extraordinary',
		'committee',
		'public hearing',
		'general_assembly',
		'informational-session',
	];

	/**
	 * The values Meeting.meetingMode accepts.
	 *
	 * @var list<string>
	 */
	private const MEETING_MODES = ['in-person', 'digital', 'hybrid'];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes decidiq's register objects.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * Hold a meeting for a consumer's object, or update the one already held for it.
	 *
	 * @param array<string, mixed> $subject The consumer's object: register, schema, id.
	 * @param array<string, mixed> $meeting Meeting fields; MUST carry title and scheduledDate.
	 *
	 * @return array{id: string, created: bool} The resolved id and whether it was minted here.
	 *
	 * @throws RuntimeException When the command is incomplete or a write fails.
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function upsert(array $subject, array $meeting): array {
		$subject = self::subjectOf(subject: $subject);
		$fields = self::fieldsOf(meeting: $meeting);
		$existing = $this->findMeeting(subject: $subject);

		if ($existing === null) {
			return $this->create(subject: $subject, fields: $fields);
		}

		return $this->update(existing: $existing, fields: $fields);

	}//end upsert()

	/**
	 * The subject, trimmed, refused when its schema or uuid is empty.
	 *
	 * @param array<string, mixed> $subject The consumer's object.
	 *
	 * @return array{register: string, schema: string, id: string} The subject.
	 *
	 * @throws RuntimeException When the schema or the uuid is empty.
	 */
	private static function subjectOf(array $subject): array {
		$resolved = [
			'register' => trim((string)($subject['register'] ?? '')),
			'schema' => trim((string)($subject['schema'] ?? '')),
			'id' => trim((string)($subject['id'] ?? '')),
		];

		if ($resolved['schema'] === '' || $resolved['id'] === '') {
			throw new RuntimeException(
				'A meeting command needs the subject schema and uuid: they are the key a repeat resolves on'
			);
		}

		return $resolved;

	}//end subjectOf()

	/**
	 * The meeting fields the command sets, refused when title or start is unusable.
	 *
	 * @param array<string, mixed> $meeting The commanded fields.
	 *
	 * @return array<string, mixed> Title, start and the allowed attributes that carry a value.
	 *
	 * @throws RuntimeException When the title is empty, the start is no date, or a listed value is unknown.
	 */
	private static function fieldsOf(array $meeting): array {
		$title = trim((string)($meeting['title'] ?? ''));
		if ($title === '') {
			throw new RuntimeException('A meeting command needs a title');
		}

		$fields = [
			'title' => $title,
			'scheduledDate' => self::dateOf(value: ($meeting['scheduledDate'] ?? '')),
		];

		foreach (self::ALLOWED_ATTRIBUTES as $key) {
			if (array_key_exists($key, $meeting) === true && $meeting[$key] !== null && $meeting[$key] !== '') {
				$fields[$key] = $meeting[$key];
			}
		}

		self::assertListed(value: ($fields['meetingType'] ?? null), allowed: self::MEETING_TYPES, field: 'meetingType');
		self::assertListed(value: ($fields['meetingMode'] ?? null), allowed: self::MEETING_MODES, field: 'meetingMode');

		return $fields;

	}//end fieldsOf()

	/**
	 * The start as an ISO-8601 moment, refused when it is no date.
	 *
	 * @param mixed $value The commanded start.
	 *
	 * @return string The start, as given.
	 *
	 * @throws RuntimeException When the value is empty or does not parse.
	 */
	private static function dateOf(mixed $value): string {
		$value = trim((string)$value);
		if ($value === '') {
			throw new RuntimeException('A meeting command needs a scheduledDate');
		}

		try {
			new DateTimeImmutable($value);
		} catch (Throwable $e) {
			throw new RuntimeException('A meeting command needs a scheduledDate that is a date: ' . $value, 0, $e);
		}

		return $value;

	}//end dateOf()

	/**
	 * Refuse a value outside the schema's list.
	 *
	 * @param mixed        $value   The value, or null when the command names none.
	 * @param list<string> $allowed The listed values.
	 * @param string       $field   The field, for the message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the value is not listed.
	 */
	private static function assertListed(mixed $value, array $allowed, string $field): void {
		if ($value === null) {
			return;
		}

		if (in_array($value, $allowed, true) === false) {
			throw new RuntimeException('Unknown ' . $field . ': ' . (string)$value);
		}

	}//end assertListed()

	/**
	 * Store a new meeting for the subject.
	 *
	 * @param array{register: string, schema: string, id: string} $subject The consumer's object.
	 * @param array<string, mixed>                                $fields  The commanded fields.
	 *
	 * @return array{id: string, created: bool} The new id.
	 *
	 * @throws RuntimeException When the store returns no id.
	 */
	private function create(array $subject, array $fields): array {
		$payload = $fields + [
			'meetingType' => self::DEFAULT_MEETING_TYPE,
			'meetingMode' => self::DEFAULT_MEETING_MODE,
			'lifecycle' => self::NEW_LIFECYCLE,
		];

		$payload['subjectSchema'] = $subject['schema'];
		$payload['subjectId'] = $subject['id'];
		if ($subject['register'] !== '') {
			$payload['subjectRegister'] = $subject['register'];
		}

		$stored = $this->store->save(schema: self::SCHEMA_MEETING, object: $payload);

		return ['id' => $this->requireId(row: $stored), 'created' => true];

	}//end create()

	/**
	 * Move the meeting already held for the subject to the commanded fields.
	 *
	 * A patch, not a replace: the lifecycle, the agenda and everything decidiq
	 * added to the meeting since it was raised stay as they are.
	 *
	 * @param array<string, mixed> $existing The stored meeting.
	 * @param array<string, mixed> $fields   The commanded fields.
	 *
	 * @return array{id: string, created: bool} The id.
	 *
	 * @throws RuntimeException When the stored meeting or the store's answer has no id.
	 */
	private function update(array $existing, array $fields): array {
		$id = $this->requireId(row: $existing);
		$stored = $this->store->patch(schema: self::SCHEMA_MEETING, data: $fields, uuid: $id);

		return ['id' => ($this->idOf(row: $stored) ?? $id), 'created' => false];

	}//end update()

	/**
	 * Resolve the meeting already held for the subject.
	 *
	 * @param array{register: string, schema: string, id: string} $subject The consumer's object.
	 *
	 * @return array<string, mixed>|null The row, or null when none matches.
	 */
	private function findMeeting(array $subject): ?array {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_MEETING,
			filters: ['subjectSchema' => $subject['schema'], 'subjectId' => $subject['id']],
		);

		// Re-checked in PHP rather than trusted: a filter key the store drops
		// returns EVERY meeting, and the first unrelated one would be moved.
		foreach ($rows as $row) {
			if (self::isMeetingOf(row: $row, subject: $subject) === true) {
				return $row;
			}
		}

		return null;

	}//end findMeeting()

	/**
	 * Whether a meeting row belongs to the subject.
	 *
	 * @param array<string, mixed>                                $row     The meeting row.
	 * @param array{register: string, schema: string, id: string} $subject The consumer's object.
	 *
	 * @return bool True when schema and uuid match, and the register too when both name one.
	 */
	private static function isMeetingOf(array $row, array $subject): bool {
		if ((string)($row['subjectSchema'] ?? '') !== $subject['schema']
			|| (string)($row['subjectId'] ?? '') !== $subject['id']
		) {
			return false;
		}

		$register = (string)($row['subjectRegister'] ?? '');

		return $register === '' || $subject['register'] === '' || $register === $subject['register'];

	}//end isMeetingOf()

	/**
	 * A row's id, refused when it has none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id.
	 *
	 * @throws RuntimeException When the row carries no id.
	 */
	private function requireId(array $row): string {
		$id = $this->idOf(row: $row);
		if ($id === null) {
			throw new RuntimeException('OpenRegister returned a meeting with no id');
		}

		return $id;

	}//end requireId()

	/**
	 * Read an object's id out of either shape OpenRegister returns.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null The id, or null when absent.
	 */
	private function idOf(array $row): ?string {
		foreach ([($row['id'] ?? null), ($row['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;

	}//end idOf()

}//end class
