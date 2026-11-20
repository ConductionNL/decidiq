<?php

/**
 * Speaking slots of a meeting, for the internal agenda and for the public.
 *
 * Internally, approved requests are slots in their order and registered ones
 * are pending; rejected ones do not appear. Publicly, only approved speakers
 * (and, after the meeting, those who spoke) appear, projected by the body's
 * public display setting from an allow-list, so contact details, the
 * rejection reason and pending or rejected requests can never reach a public
 * payload.
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
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Builds speaking slots from a meeting's speaking requests.
 *
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
 */
class SpeakingSlots {

	/**
	 * The key of the meeting-level block, for requests without an agenda item.
	 */
	public const MEETING = 'meeting';

	/**
	 * The states of an approved request, before and after the meeting.
	 */
	private const SLOT_STATES = ['approved', 'spoke', 'no-show'];

	/**
	 * The states shown publicly: approved, and spoke after the meeting.
	 */
	private const PUBLIC_STATES = ['approved', 'spoke'];

	/**
	 * The public display settings.
	 */
	public const DISPLAYS = ['count', 'first-name', 'speaker-name'];

	/**
	 * The internal agenda: per agenda item (and the meeting block), the
	 * slots in order and the pending requests.
	 *
	 * @param array<int, array<string, mixed>> $requests The meeting's speaking requests.
	 *
	 * @return array<string, array{slots: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>}>
	 *         Keyed by agenda item id, or `meeting` for meeting-level requests.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
	 */
	public function internal(array $requests): array {
		$agenda = [];
		foreach ($this->ordered(requests: $requests) as $request) {
			$state = (string)($request['lifecycle'] ?? '');
			$group = 'pending';
			if (in_array($state, self::SLOT_STATES, true) === true) {
				$group = 'slots';
			} else if ($state !== 'registered') {
				continue;
			}

			$key = $this->placeOf(request: $request);
			$agenda[$key] ??= ['slots' => [], 'pending' => []];
			$agenda[$key][$group][] = $this->internalEntry(request: $request);
		}

		return $agenda;
	}//end internal()

	/**
	 * The public agenda: per agenda item (and the meeting block), the
	 * approved speakers as the display setting allows.
	 *
	 * `count` gives only `{count}`; `first-name` adds each speaker's first
	 * name; `speaker-name` adds the speaker name, organisation and topic.
	 * An unknown setting reads as `count`, the most private one.
	 *
	 * @param array<int, array<string, mixed>> $requests The meeting's speaking requests.
	 * @param string                           $display  The body's public display setting.
	 *
	 * @return array<string, array<string, mixed>> Keyed by agenda item id, or `meeting`.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-008-anonymised-public-agenda-view-per-policy
	 */
	public function public(array $requests, string $display): array {
		if (in_array($display, self::DISPLAYS, true) === false) {
			$display = 'count';
		}

		$agenda = [];
		foreach ($this->ordered(requests: $requests) as $request) {
			if (in_array((string)($request['lifecycle'] ?? ''), self::PUBLIC_STATES, true) === false) {
				continue;
			}

			$key = $this->placeOf(request: $request);
			$agenda[$key] ??= ['count' => 0];
			$agenda[$key]['count']++;
			if ($display !== 'count') {
				$agenda[$key]['speakers'][] = $this->publicEntry(request: $request, display: $display);
			}
		}

		return $agenda;
	}//end public()

	/**
	 * The approved speakers of one agenda item as speaker queue entries, in
	 * order, each labelled as a public speaker with its time limit.
	 *
	 * @param array<int, array<string, mixed>> $requests     The meeting's speaking requests.
	 * @param string                           $agendaItemId The agenda item, or '' for the meeting block.
	 *
	 * @return array<int, array{requestId: string, kind: string, name: string, minutes: int, position: int}>
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
	 */
	public function queue(array $requests, string $agendaItemId): array {
		$place = $agendaItemId;
		if ($place === '') {
			$place = self::MEETING;
		}

		$queue = [];
		foreach ($this->ordered(requests: $requests) as $request) {
			if (($request['lifecycle'] ?? '') !== 'approved' || $this->placeOf(request: $request) !== $place) {
				continue;
			}

			$queue[] = [
				'requestId' => (string)($request['id'] ?? ''),
				'kind'      => 'public-speaker',
				'name'      => (string)($request['topic']['speakerName'] ?? ''),
				'minutes'   => (int)($request['allottedMinutes'] ?? 0),
				'position'  => (int)($request['position'] ?? 0),
			];
		}

		return $queue;
	}//end queue()

	/**
	 * The requests in slot order: by position, then by when they were made.
	 *
	 * @param array<int, array<string, mixed>> $requests The requests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ordered(array $requests): array {
		$rows = array_values(array_filter($requests, 'is_array'));
		usort(
			$rows,
			static function (array $one, array $two): int {
				$byPosition = ((int)($one['position'] ?? PHP_INT_MAX)) <=> ((int)($two['position'] ?? PHP_INT_MAX));
				if ($byPosition !== 0) {
					return $byPosition;
				}

				return ((string)($one['@self']['created'] ?? '')) <=> ((string)($two['@self']['created'] ?? ''));
			}
		);

		return $rows;
	}//end ordered()

	/**
	 * Where a request appears: its agenda item, or the meeting block.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return string
	 */
	private function placeOf(array $request): string {
		$item = trim((string)($request['agendaItem'] ?? ''));
		if ($item === '') {
			return self::MEETING;
		}

		return $item;
	}//end placeOf()

	/**
	 * A request as the internal agenda shows it.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed>
	 */
	private function internalEntry(array $request): array {
		$topic = (array)($request['topic'] ?? []);
		return [
			'id'                => (string)($request['id'] ?? ''),
			'status'            => (string)($request['lifecycle'] ?? ''),
			'speakerName'       => (string)($topic['speakerName'] ?? ''),
			'organisation'      => (string)($topic['organisation'] ?? ''),
			'topicText'         => (string)($topic['topicText'] ?? ''),
			'minutes'           => (int)($request['allottedMinutes'] ?? ($topic['requestedMinutes'] ?? 0)),
			'position'          => ($request['position'] ?? null),
			'hasContribution'   => trim((string)($request['contribution'] ?? '')) !== '',
			'transcriptSegment' => ($request['transcriptSegment'] ?? null),
		];
	}//end internalEntry()

	/**
	 * A request as the public sees it, built only from allowed fields.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string               $display first-name or speaker-name.
	 *
	 * @return array<string, string>
	 */
	private function publicEntry(array $request, string $display): array {
		$topic = (array)($request['topic'] ?? []);
		$name  = trim((string)($topic['speakerName'] ?? ''));
		if ($display === 'first-name') {
			return ['firstName' => (string)(preg_split('/\s+/', $name)[0] ?? '')];
		}

		return [
			'speakerName'  => $name,
			'organisation' => (string)($topic['organisation'] ?? ''),
			'topicText'    => (string)($topic['topicText'] ?? ''),
		];
	}//end publicEntry()
}//end class
