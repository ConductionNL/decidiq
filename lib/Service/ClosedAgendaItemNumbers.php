<?php

/**
 * Decidiq Closed Agenda Item Numbers
 *
 * The places of the closed agenda items of a meeting that the caller may not
 * read, so the agenda can show them as "Besloten punt" with their number.
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
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Lists where a meeting's hidden closed items sit, and nothing else about them.
 *
 * The read rules hide a non-public agenda item from whoever may not read it,
 * so the caller's own list has a gap. This fills the gap with the order
 * number alone: the item's id, title and papers are never returned, and an
 * item the caller can read is never listed. A closed sub-item names its
 * parent only when the caller can read that parent; under a hidden parent it
 * is left out, as the parent's own "Besloten punt" covers it.
 *
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */
class ClosedAgendaItemNumbers {
	/**
	 * Most agenda items read per meeting.
	 */
	private const ITEM_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The hidden closed items of a meeting, as order number and visible parent.
	 *
	 * The meeting is read as the caller: who may not read the meeting learns
	 * nothing about its agenda.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
	 *
	 * @throws MissingObjectException When the caller cannot read the meeting.
	 *
	 * @return list<array{orderNumber: int, parentItem: ?string}> Sorted by order number.
	 */
	public function forMeeting(string $meetingId): array {
		$meeting = $this->objectService->find(
			id: $meetingId,
			options: new ObjectFindOptions(register: 'decidiq', schema: 'meeting')
		);
		if ($meeting === null) {
			throw new MissingObjectException('Meeting not found.');
		}

		$visible = $this->itemIds(rows: $this->items(meetingId: $meetingId, asCaller: true), meetingId: $meetingId);

		$closed = [];
		foreach ($this->items(meetingId: $meetingId, asCaller: false) as $row) {
			$entry = $this->hiddenClosedEntry(item: $this->rowData(row: $row), meetingId: $meetingId, visible: $visible);
			if ($entry !== null) {
				$closed[] = $entry;
			}
		}

		usort($closed, static fn (array $a, array $b): int => $a['orderNumber'] <=> $b['orderNumber']);
		return $closed;
	}//end forMeeting()

	/**
	 * The entry for one item when it is closed, on this meeting and hidden from the caller.
	 *
	 * @param array<string, mixed>|null $item      The item data.
	 * @param string                    $meetingId The meeting.
	 * @param array<string, true>       $visible   Ids the caller can read.
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
	 *
	 * @return array{orderNumber: int, parentItem: ?string}|null
	 */
	private function hiddenClosedEntry(?array $item, string $meetingId, array $visible): ?array {
		if ($item === null || ($item['public'] ?? true) !== false || $this->onMeeting(item: $item, meetingId: $meetingId) === false) {
			return null;
		}

		$id = $this->idOf(item: $item);
		if ($id === '' || isset($visible[$id]) === true) {
			return null;
		}

		$parent = $this->refId(ref: ($item['parentItem'] ?? null));
		if ($parent !== '' && isset($visible[$parent]) === false) {
			return null;
		}

		return [
			'orderNumber' => (int)($item['orderNumber'] ?? 0),
			'parentItem'  => ($parent === '' ? null : $parent),
		];
	}//end hiddenClosedEntry()

	/**
	 * The agenda item rows of the meeting, as the caller or in system context.
	 *
	 * @param string $meetingId The meeting.
	 * @param bool   $asCaller  True to apply the caller's read rules.
	 *
	 * @return array<int|string, mixed>
	 */
	private function items(string $meetingId, bool $asCaller): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => ['register' => 'decidiq', 'schema' => 'agenda-item', 'meeting' => $meetingId],
				'limit'   => self::ITEM_LIMIT,
			],
			_rbac: $asCaller,
			_multitenancy: $asCaller
		);

		return (array)($rows['results'] ?? $rows);
	}//end items()

	/**
	 * The ids of the rows on this meeting.
	 *
	 * @param array<int|string, mixed> $rows      The rows.
	 * @param string                   $meetingId The meeting.
	 *
	 * @return array<string, true>
	 */
	private function itemIds(array $rows, string $meetingId): array {
		$ids = [];
		foreach ($rows as $row) {
			$item = $this->rowData(row: $row);
			if ($item !== null && $this->onMeeting(item: $item, meetingId: $meetingId) === true && $this->idOf(item: $item) !== '') {
				$ids[$this->idOf(item: $item)] = true;
			}
		}

		return $ids;
	}//end itemIds()

	/**
	 * Whether an item belongs to the meeting; one without the relation does not.
	 *
	 * @param array<string, mixed> $item      The item data.
	 * @param string               $meetingId The meeting.
	 *
	 * @return bool
	 */
	private function onMeeting(array $item, string $meetingId): bool {
		return $this->refId(ref: ($item['meeting'] ?? null)) === $meetingId;
	}//end onMeeting()

	/**
	 * The id of an item: its `id`, else the uuid under `@self`.
	 *
	 * @param array<string, mixed> $item The item data.
	 *
	 * @return string
	 */
	private function idOf(array $item): string {
		$id = ($item['id'] ?? ($item['@self']['id'] ?? ''));
		return is_scalar($id) === true ? (string)$id : '';
	}//end idOf()

	/**
	 * The id a reference holds, whether written as a string or as an object.
	 *
	 * @param mixed $ref The reference.
	 *
	 * @return string
	 */
	private function refId(mixed $ref): string {
		if (is_array($ref) === true) {
			$ref = ($ref['id'] ?? ($ref['uuid'] ?? ''));
		}

		return is_scalar($ref) === true ? (string)$ref : '';
	}//end refId()

	/**
	 * A row as an array, or null when it is neither an array nor serialisable.
	 *
	 * @param mixed $row The row or entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowData(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		return is_array($row) === true ? $row : null;
	}//end rowData()
}//end class
