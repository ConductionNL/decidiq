<?php

/**
 * Decidiq Agenda Item Copier
 *
 * Builds the agenda items a meeting gets when its agenda is copied from an
 * earlier meeting (agenda-templates-and-copy, pla-14). A copied item keeps
 * what it is (title, kind, description, planned duration, configured type
 * and its fields, formality) and leaves behind what happened to it (its
 * outcome, adoption time, durations measured in the meeting).
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
 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Turns one meeting's agenda items into new items for another meeting.
 *
 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 */
final class AgendaItemCopier {

	/**
	 * The fields a copied agenda item keeps. src/utils/agendaCopy.js keeps
	 * the same list for the Agenda widget.
	 *
	 * @var string[]
	 */
	public const KEPT_FIELDS = ['title', 'itemType', 'description', 'estimatedDuration', 'type', 'typeFields', 'isFormality'];

	/**
	 * The new items, in the earlier order, numbered from $startAt, for the
	 * items of $fromMeetingId only. Sub-items are copied as top-level items.
	 *
	 * @param iterable<mixed> $items         The earlier items (entities or arrays)
	 * @param string          $fromMeetingId The meeting they belong to
	 * @param string          $toMeetingId   The meeting that gets the copies
	 * @param int             $startAt       The order number of the first copy
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
	 */
	public function payloads(iterable $items, string $fromMeetingId, string $toMeetingId, int $startAt=1): array {
		$own = [];
		foreach ($items as $item) {
			$data = $this->data(item: $item);
			if ($this->refId(ref: ($data['meeting'] ?? null)) === $fromMeetingId && trim((string)($data['title'] ?? '')) !== '') {
				$own[] = $data;
			}
		}

		usort($own, static fn (array $a, array $b): int => ((int)($a['orderNumber'] ?? 0) <=> (int)($b['orderNumber'] ?? 0)));

		$payloads = [];
		$order = $startAt;
		foreach ($own as $data) {
			$copy = ['meeting' => $toMeetingId, 'orderNumber' => $order, 'itemType' => 'informational'];
			foreach (self::KEPT_FIELDS as $field) {
				if (array_key_exists($field, $data) === true && $data[$field] !== null) {
					$copy[$field] = $data[$field];
				}
			}

			$payloads[] = $copy;
			$order++;
		}

		return $payloads;

	}//end payloads()

	/**
	 * An item as an array, from an entity or an array.
	 *
	 * @param mixed $item One item
	 *
	 * @return array<string, mixed>
	 */
	private function data(mixed $item): array {
		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			$item = $item->jsonSerialize();
		}

		if (is_array($item) === false) {
			return [];
		}

		return $item;

	}//end data()

	/**
	 * Read a reference that is a bare uuid or an expanded object.
	 *
	 * @param mixed $ref The reference
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
