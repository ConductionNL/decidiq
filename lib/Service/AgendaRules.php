<?php

/**
 * The pure rules of an agenda: reading objects as arrays, item ids, BOB
 * phase steps, what a publication records and agenda version snapshots.
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
 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTime;
use InvalidArgumentException;

/**
 * Agenda rules used by AgendaService; they hold no state.
 *
 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
 */
final class AgendaRules {

	/**
	 * Every value AgendaItem.phase declares, in order.
	 *
	 * @var list<string>
	 */
	public const PHASES = ['voorstel', 'beeldvorming', 'oordeelsvorming', 'besluitvorming', 'completed'];

	/**
	 * BOB phase transition map: current status → next status.
	 *
	 * @var array<string,string>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	private const BOB_PHASE_TRANSITIONS = [
		'voorstel' => 'beeldvorming',
		'beeldvorming' => 'oordeelsvorming',
		'oordeelsvorming' => 'besluitvorming',
		'besluitvorming' => 'completed',
	];

	/**
	 * A member gets at most one agenda change notice per meeting in this many seconds.
	 *
	 * @var int
	 */
	private const AGENDA_NOTICE_WINDOW_SECONDS = 300;

	/**
	 * Normalise an OpenRegister object to a plain PHP array.
	 *
	 * Handles both raw arrays and ObjectEntity instances returned by ObjectService.
	 *
	 * @param mixed $item The raw item from ObjectService
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (method_exists($item, 'getObject') === true) {
			return $item->getObject();
		}

		return (array)$item;
	}//end toArray()

	/**
	 * The current and next BOB phase of an agenda item.
	 *
	 * @param array<string, mixed> $itemData The agenda item
	 *
	 * @return array{0: string, 1: string} [current, next]
	 *
	 * @throws InvalidArgumentException When the item is informational or already at the final phase
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
	 */
	public function bobTransition(array $itemData): array {
		// Guard: informational items have no BOB phase.
		if (($itemData['itemType'] ?? null) === 'informational') {
			throw new InvalidArgumentException('Informational agenda items do not have a BOB phase.');
		}

		$currentStatus = $this->phaseOf(itemData: $itemData);
		$nextStatus = self::BOB_PHASE_TRANSITIONS[$currentStatus] ?? null;
		if ($nextStatus === null) {
			throw new InvalidArgumentException(
				"AgendaItem is already at final phase '{$currentStatus}' and cannot be advanced."
			);
		}

		return [$currentStatus, $nextStatus];
	}//end bobTransition()

	/**
	 * The phase an agenda item is in: the declared `phase`, else a phase still
	 * held in the undeclared `status` an item from before the upgrade carries,
	 * else `beeldvorming`.
	 *
	 * @param array<string, mixed> $itemData The agenda item
	 *
	 * @return string The phase
	 *
	 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
	 */
	public function phaseOf(array $itemData): string {
		foreach (['phase', 'status'] as $key) {
			$value = ($itemData[$key] ?? null);
			if (is_string($value) === true && in_array($value, self::PHASES, true) === true) {
				return $value;
			}
		}

		return 'beeldvorming';
	}//end phaseOf()

	/**
	 * The id of an agenda item: its `id`, its `@self.id` or its `uuid`.
	 *
	 * @param array<string, mixed> $itemData The agenda item
	 *
	 * @return mixed The id, or null when it has none
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function itemIdOf(array $itemData): mixed {
		return $itemData['id'] ?? ($itemData['@self']['id'] ?? ($itemData['uuid'] ?? null));
	}//end itemIdOf()

	/**
	 * The notice subject of a publication: a first publication is
	 * "published"; publishing again after a revision is "revised". Either way
	 * the meeting's lifecycle is left alone: publishing an agenda days ahead
	 * is not a meeting in session, and LiveDecisionService only records live
	 * decisions on an `opened` meeting (#1396). Publication has its own fields
	 * instead.
	 *
	 * @param array<string, mixed> $meetingData The full meeting
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function publicationSubject(array $meetingData): string {
		if ((int)($meetingData['agendaVersion'] ?? 0) > 0) {
			return 'agenda_revised';
		}

		return 'agenda_published';
	}//end publicationSubject()

	/**
	 * The fields a publication sets: the revision is closed, and the
	 * convocation goes out with the first publication; a republish keeps that
	 * date (publication eligibility reads it).
	 *
	 * @param array<string, mixed> $meetingData The full meeting
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function publicationChanges(array $meetingData): array {
		$changes = ['agendaUnderRevision' => false];
		if (empty($meetingData['convocationSentAt']) === true) {
			$changes['convocationSentAt'] = (new DateTime())->format(DATE_ATOM);
		}

		return $changes;
	}//end publicationChanges()

	/**
	 * Whether a notice time lies within the notice window.
	 *
	 * @param mixed $sentAt The notice time, '' when none
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function noticedRecently(mixed $sentAt): bool {
		$last = strtotime((string)$sentAt);
		return ($last !== false && (time() - $last) < self::AGENDA_NOTICE_WINDOW_SECONDS);
	}//end noticedRecently()

	/**
	 * The meeting with its next agenda version recorded.
	 *
	 * @param array<string, mixed> $meetingData The full meeting
	 * @param iterable<mixed>      $items       The meeting's agenda items
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function withNewAgendaVersion(array $meetingData, iterable $items): array {
		$now = (new DateTime())->format(DATE_ATOM);
		$version = ((int)($meetingData['agendaVersion'] ?? 0) + 1);

		$versions = $this->agendaVersionsOf(meetingData: $meetingData);
		$versions[] = ['version' => $version, 'publishedAt' => $now, 'items' => $this->agendaSnapshot(items: $items)];

		return array_merge(
			$meetingData,
			[
				'agendaPublishedAt' => $now,
				'agendaVersion' => $version,
				'agendaVersions' => $versions,
			]
		);
	}//end withNewAgendaVersion()

	/**
	 * The agenda versions a meeting recorded; none when the field is not a list.
	 *
	 * @param array<string, mixed> $meetingData The full meeting
	 *
	 * @return array<int, mixed>
	 */
	private function agendaVersionsOf(array $meetingData): array {
		$versions = $meetingData['agendaVersions'] ?? [];
		if (is_array($versions) === false) {
			return [];
		}

		return $versions;
	}//end agendaVersionsOf()

	/**
	 * The id, title and orderNumber of every agenda item, as a version records them.
	 *
	 * @param iterable<mixed> $items The meeting's agenda items
	 *
	 * @return array<int, array{id: string, title: string, orderNumber: mixed}>
	 */
	private function agendaSnapshot(iterable $items): array {
		$snapshot = [];
		foreach ($items as $item) {
			$itemData = $this->toArray(item: $item);
			$snapshot[] = [
				'id' => (string)($itemData['id'] ?? ($itemData['@self']['id'] ?? '')),
				'title' => (string)($itemData['title'] ?? ''),
				'orderNumber' => ($itemData['orderNumber'] ?? null),
			];
		}

		return $snapshot;
	}//end agendaSnapshot()
}//end class
