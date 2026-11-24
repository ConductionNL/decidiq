<?php

/**
 * Decidiq RoomSystemFeed
 *
 * Keeps the chamber's displays and desks showing what decidiq shows: the
 * published agenda with its current item, the speakers' list and the totals
 * of each closed voting round.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Turns a change of a meeting, a voting round or an engagement record into
 * the message the room system needs, and sends it only while a room system
 * is linked. Each method compares the saved object with the one it replaced
 * and sends nothing when nothing the room shows changed. Objects are read in
 * system context: the change was already allowed when it was saved.
 *
 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */
class RoomSystemFeed {

	/**
	 * The meeting fields the room's agenda display follows.
	 */
	private const AGENDA_FIELDS = ['agendaPublishedAt', 'agendaVersion', 'currentAgendaItem'];

	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects OpenRegister objects.
	 * @param RoomSystemClient       $client  Sends to the linked room system.
	 *
	 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
		private readonly RoomSystemClient $client,
	) {
	}//end __construct()

	/**
	 * Send the published agenda and its current item when either changed.
	 *
	 * @param array<string, mixed> $meeting The meeting as saved, with its `id`.
	 * @param array<string, mixed> $old     The meeting before the save; empty for a new one.
	 *
	 * @return bool Whether a message was sent.
	 *
	 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function agendaChanged(array $meeting, array $old): bool {
		$published = self::isBlank(value: $meeting['agendaPublishedAt'] ?? null) === false;
		if ($published === false || self::changed(new: $meeting, old: $old, fields: self::AGENDA_FIELDS) === false) {
			return false;
		}

		if ($this->client->isConnected() === false) {
			return false;
		}

		return $this->client->send(operation: 'agenda', body: $this->agendaOf(meeting: $meeting));
	}//end agendaChanged()

	/**
	 * Send a voting round's totals, with the meeting's current item, once it closes.
	 *
	 * @param array<string, mixed> $round The round as saved, with its `id`.
	 * @param array<string, mixed> $old   The round before the save; empty for a new one.
	 *
	 * @return bool Whether a message was sent.
	 *
	 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function roundClosed(array $round, array $old): bool {
		if (self::isBlank(value: $round['closedAt'] ?? null) === true || self::isBlank(value: $old['closedAt'] ?? null) === false) {
			return false;
		}

		if ($this->client->isConnected() === false) {
			return false;
		}

		return $this->client->send(operation: 'vote-result', body: $this->resultOf(round: $round));
	}//end roundClosed()

	/**
	 * Send the meeting's speakers' list when a member's speeches changed.
	 *
	 * @param array<string, mixed> $record The engagement record as saved.
	 * @param array<string, mixed> $old    The record before the save; empty for a new one.
	 *
	 * @return bool Whether a message was sent.
	 *
	 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function speakersChanged(array $record, array $old): bool {
		$meetingId = (string)($record['meeting'] ?? '');
		if ($meetingId === '' || self::changed(new: $record, old: $old, fields: ['speeches']) === false) {
			return false;
		}

		if ($this->client->isConnected() === false) {
			return false;
		}

		return $this->client->send(operation: 'speakers', body: ['meeting' => $meetingId, 'speakers' => $this->speakersOf(meetingId: $meetingId)]);
	}//end speakersChanged()

	/**
	 * The agenda message: the items in order and the one under discussion.
	 *
	 * @param array<string, mixed> $meeting The meeting, with its `id`.
	 *
	 * @return array<string, mixed>
	 */
	private function agendaOf(array $meeting): array {
		$items = array_map(
			static fn (array $item): array => [
				'id'          => (string)$item['id'],
				'orderNumber' => (int)($item['orderNumber'] ?? 0),
				'title'       => (string)($item['title'] ?? ''),
			],
			$this->rows(schema: 'agenda-item', filters: ['meeting' => (string)$meeting['id']])
		);
		usort($items, static fn (array $a, array $b): int => $a['orderNumber'] <=> $b['orderNumber']);

		return [
			'meeting'           => (string)$meeting['id'],
			'title'             => (string)($meeting['title'] ?? ''),
			'agendaVersion'     => $meeting['agendaVersion'] ?? null,
			'currentAgendaItem' => self::nullable(value: $meeting['currentAgendaItem'] ?? null),
			'items'             => $items,
		];
	}//end agendaOf()

	/**
	 * The vote-result message: the round's totals and the meeting's current item.
	 *
	 * @param array<string, mixed> $round The closed round, with its `id`.
	 *
	 * @return array<string, mixed>
	 */
	private function resultOf(array $round): array {
		$meetingId = (string)($round['meeting'] ?? '');
		$meeting = $this->find(id: $meetingId, schema: 'meeting') ?? [];
		$itemId = self::nullable(value: $meeting['currentAgendaItem'] ?? null);
		$item = $this->find(id: (string)$itemId, schema: 'agenda-item');

		return [
			'meeting'           => $meetingId,
			'votingRound'       => (string)$round['id'],
			'currentAgendaItem' => $itemId,
			'agendaItemTitle'   => (string)($item['title'] ?? ''),
			'votesFor'          => (int)($round['votesFor'] ?? 0),
			'votesAgainst'      => (int)($round['votesAgainst'] ?? 0),
			'votesAbstain'      => (int)($round['votesAbstain'] ?? 0),
			'result'            => (string)($round['result'] ?? ''),
		];
	}//end resultOf()

	/**
	 * Tell the room system who has the floor now, with the member's seat, so
	 * its cameras and microphones follow the speaker (REQ-PLB-006). Sends
	 * nothing while no room system is linked.
	 *
	 * @param string $meetingId     The meeting UUID.
	 * @param string $participantId The participant who was given the floor.
	 *
	 * @return bool Whether the room system took the message.
	 *
	 * @spec openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-006-the-rooms-cameras-and-microphones-follow-the-speaker
	 */
	public function floorGiven(string $meetingId, string $participantId): bool {
		if ($meetingId === '' || $participantId === '' || $this->client->isConnected() === false) {
			return false;
		}

		$member = ($this->find(id: $participantId, schema: 'participant') ?? []);

		return $this->client->send(
			operation: 'floor',
			body: [
				'meeting'     => $meetingId,
				'participant' => $participantId,
				'name'        => (string)($member['displayName'] ?? ''),
				'seat'        => self::nullable(value: $member['roomSeat'] ?? null),
			]
		);
	}//end floorGiven()

	/**
	 * Every speech in the meeting, in the order they started, with the member's name and seat.
	 *
	 * @param string $meetingId The meeting UUID.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function speakersOf(string $meetingId): array {
		$speakers = [];
		foreach ($this->rows(schema: 'engagement-record', filters: ['meeting' => $meetingId]) as $record) {
			$member = $this->find(id: (string)($record['participant'] ?? ''), schema: 'participant') ?? [];
			foreach ((array)($record['speeches'] ?? []) as $speech) {
				$speakers[] = self::speakerOf(record: $record, member: $member, speech: (array)$speech);
			}
		}

		usort($speakers, static fn (array $a, array $b): int => strcmp($a['startTime'], $b['startTime']));
		return $speakers;
	}//end speakersOf()

	/**
	 * One entry of the speakers' list.
	 *
	 * @param array<string, mixed> $record The member's engagement record.
	 * @param array<string, mixed> $member The participant, empty when it is gone.
	 * @param array<string, mixed> $speech The speech.
	 *
	 * @return array<string, mixed>
	 */
	private static function speakerOf(array $record, array $member, array $speech): array {
		return [
			'participant' => (string)($record['participant'] ?? ''),
			'name'        => (string)($member['displayName'] ?? ''),
			'seat'        => (string)($member['roomSeat'] ?? ''),
			'agendaItem'  => self::nullable(value: $speech['agendaItem'] ?? null),
			'startTime'   => (string)($speech['startTime'] ?? ''),
			'endTime'     => (string)($speech['endTime'] ?? ''),
		];
	}//end speakerOf()

	/**
	 * Whether any of the fields differs between the saved object and the one it replaced.
	 *
	 * @param array<string, mixed> $new    The saved object.
	 * @param array<string, mixed> $old    The object before the save.
	 * @param list<string>         $fields The fields.
	 *
	 * @return bool
	 */
	private static function changed(array $new, array $old, array $fields): bool {
		foreach ($fields as $field) {
			if (json_encode($new[$field] ?? null) !== json_encode($old[$field] ?? null)) {
				return true;
			}
		}

		return false;
	}//end changed()

	/**
	 * Whether a value is unset: null or an empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isBlank(mixed $value): bool {
		return $value === null || $value === '';
	}//end isBlank()

	/**
	 * A reference as a string, or null when it is unset.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string|null
	 */
	private static function nullable(mixed $value): ?string {
		if (self::isBlank(value: $value) === true) {
			return null;
		}

		return (string)$value;
	}//end nullable()

	/**
	 * One object as an array with its `id`, or null when it does not exist.
	 *
	 * @param string $id     The UUID.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objects->find(
				id: $id,
				options: new ObjectFindOptions(register: self::REGISTER, schema: $schema),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return ['id' => (string)$entity->getUuid()] + (array)$entity->getObject();
	}//end find()

	/**
	 * The objects of a schema matching the filters, as arrays with their `id`.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, string> $filters The filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objects->findAll(
			config: ['filters' => (['register' => self::REGISTER, 'schema' => $schema] + $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		return ObjectRows::of(found: $found);
	}//end rows()
}//end class
