<?php

/**
 * Committee Agenda Placement
 *
 * A route step of kind `committee` is decided by its governance body in a
 * meeting. When such a stage becomes active, its subject is placed as an
 * agenda item on the body's next regular meeting that is still open; while
 * the body has no such meeting the stage waits with `placementPending`, and
 * the item is placed when a regular meeting of that body is created. When the
 * body records its decision on that agenda item, the stage closes with the
 * body's outcome, the decision's date and the meeting it was taken in.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Places committee stages on their body's agenda and closes them from the body's decision.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */
class CommitteeAgendaPlacement {

	/**
	 * Meeting lifecycle states an agenda item can no longer be placed on.
	 *
	 * @var list<string>
	 */
	private const CLOSED_MEETING_STATES = ['closed', 'cancelled', 'archived', 'completed'];

	/**
	 * The body's outcome that lets the route walk on; any other outcome below ends it.
	 *
	 * @var string
	 */
	public const OUTCOME_ADOPTED = 'adopted';

	/**
	 * The body's outcome that ends the route at the committee step.
	 *
	 * @var string
	 */
	public const OUTCOME_REJECTED = 'rejected';

	/**
	 * Advances the route once the committee stage is decided.
	 *
	 * @var ApprovalRouteAdvancer
	 */
	private readonly ApprovalRouteAdvancer $advancer;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore         $store       Reads and writes stages, meetings and agenda items.
	 * @param ITimeFactory                $timeFactory The current time, for the "next" meeting.
	 * @param ApprovalStageActivator|null $activator   Resolves the next stage's actor rule when the route walks on.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ITimeFactory $timeFactory,
		?ApprovalStageActivator $activator = null,
	) {
		$this->advancer = new ApprovalRouteAdvancer(store: $store, activator: $activator);
	}//end __construct()

	/**
	 * Whether a stage is an active committee stage that is not yet on an agenda.
	 *
	 * A stage already waiting for a meeting (`placementPending`) is not asked
	 * again on every save; it is placed when the body's next meeting is created.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public static function awaitsPlacement(array $stage): bool {
		return ($stage['stepKind'] ?? '') === 'committee'
			&& ($stage['status'] ?? '') === 'active'
			&& self::ref(value: ($stage['committeeBody'] ?? null)) !== ''
			&& self::ref(value: ($stage['agendaItem'] ?? null)) === ''
			&& ($stage['placementPending'] ?? false) !== true;
	}//end awaitsPlacement()

	/**
	 * Place an active committee stage on its body's next regular meeting.
	 *
	 * @param array<string, mixed> $stage The committee stage.
	 *
	 * @return array<string, mixed>|null The agenda item, or null when the stage
	 *         is not awaiting placement or waits for the body's next meeting.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function placeStage(array $stage): ?array {
		if (self::awaitsPlacement(stage: $stage) === false) {
			return null;
		}

		$meeting = $this->nextMeeting(bodyId: self::ref(value: $stage['committeeBody']));
		if ($meeting === null) {
			$this->store->patch(schema: 'decision-stage', data: ['placementPending' => true], uuid: (string)$stage['id']);
			return null;
		}

		return $this->place(stage: $stage, meeting: $meeting);
	}//end placeStage()

	/**
	 * Place every committee stage waiting for this body on its newly created meeting.
	 *
	 * @param array<string, mixed> $meeting The meeting just created.
	 *
	 * @return int The number of stages placed.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function placePending(array $meeting): int {
		$body = self::ref(value: ($meeting['governanceBody'] ?? null));
		if ($body === '' || $this->takesItems(meeting: $meeting) === false) {
			return 0;
		}

		$waiting = $this->store->findAll(
			schema: 'decision-stage',
			filters: ['stepKind' => 'committee', 'committeeBody' => $body, 'placementPending' => true]
		);

		$placed = 0;
		foreach ($waiting as $stage) {
			if (($stage['status'] ?? '') !== 'active' || self::ref(value: ($stage['agendaItem'] ?? null)) !== '') {
				continue;
			}

			$this->place(stage: $stage, meeting: $meeting);
			$placed++;
		}

		return $placed;
	}//end placePending()

	/**
	 * Close the committee stage a body's decision answers.
	 *
	 * The decision answers the stage whose agenda item it was taken on. An
	 * adopted decision completes the stage and the route walks on; a rejected
	 * one ends the route at the committee step. Either way the stage keeps the
	 * outcome, the decision's date and the meeting it was taken in.
	 *
	 * @param array<string, mixed> $decision The body's decision.
	 *
	 * @return array<string, mixed>|null The closed stage, or null when the
	 *         decision answers no active committee stage.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function closeFromDecision(array $decision): ?array {
		$outcome = (string)($decision['outcome'] ?? '');
		$itemId  = self::ref(value: ($decision['agendaItem'] ?? null));
		if ($itemId === '' || in_array($outcome, [self::OUTCOME_ADOPTED, self::OUTCOME_REJECTED], true) === false) {
			return null;
		}

		$stage = $this->committeeStageOn(agendaItemId: $itemId);
		if ($stage === null) {
			return null;
		}

		$stages = $this->store->findAll(schema: 'decision-stage', filters: ['decision' => self::ref(value: ($stage['decision'] ?? null))]);
		usort($stages, static fn (array $a, array $b): int => ((int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0)));

		$this->settle(stages: $stages, stage: $stage, outcome: $outcome);

		return $this->store->patch(
			schema: 'decision-stage',
			data: [
				'decidedAt'        => $this->dateOf(decision: $decision),
				'decidedInMeeting' => $this->meetingOf(decision: $decision, agendaItemId: $itemId),
			],
			uuid: (string)$stage['id']
		);
	}//end closeFromDecision()

	/**
	 * Record the body's outcome on the stage: adopted walks the route on, rejected ends it here.
	 *
	 * @param array<int, array<string, mixed>> $stages  The subject's stages, in order.
	 * @param array<string, mixed>             $stage   The committee stage.
	 * @param string                           $outcome The body's outcome.
	 *
	 * @return void
	 */
	private function settle(array $stages, array $stage, string $outcome): void {
		if ($outcome === self::OUTCOME_ADOPTED) {
			$this->advancer->completeWithOutcome(stages: $stages, active: $stage, outcome: $outcome);
			return;
		}

		$this->advancer->concludeWithOutcome(stages: $stages, active: $stage, outcome: $outcome);
	}//end settle()

	/**
	 * The body's next regular meeting that still takes agenda items, or null.
	 *
	 * @param string $bodyId The committee body.
	 *
	 * @return array<string, mixed>|null The meeting.
	 */
	private function nextMeeting(string $bodyId): ?array {
		$open = array_filter(
			$this->store->findAll(schema: 'meeting', filters: ['governanceBody' => $bodyId, 'meetingType' => 'regular']),
			fn (array $meeting): bool => $this->takesItems(meeting: $meeting)
		);
		usort($open, static fn (array $a, array $b): int => (strtotime((string)$a['scheduledDate']) <=> strtotime((string)$b['scheduledDate'])));

		return ($open[0] ?? null);
	}//end nextMeeting()

	/**
	 * Whether a meeting takes new agenda items: regular, still open, in the future.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 *
	 * @return bool
	 */
	private function takesItems(array $meeting): bool {
		$open = ($meeting['meetingType'] ?? '') === 'regular'
			&& in_array((string)($meeting['lifecycle'] ?? ''), self::CLOSED_MEETING_STATES, true) === false;
		if ($open === false || (string)($meeting['scheduledDate'] ?? '') === '') {
			return false;
		}

		try {
			return new DateTimeImmutable((string)$meeting['scheduledDate']) > $this->timeFactory->getDateTime();
		} catch (Throwable) {
			return false;
		}
	}//end takesItems()

	/**
	 * Put the stage's subject on the meeting's agenda, last, and record it on the stage.
	 *
	 * @param array<string, mixed> $stage   The committee stage.
	 * @param array<string, mixed> $meeting The meeting.
	 *
	 * @return array<string, mixed> The agenda item.
	 */
	private function place(array $stage, array $meeting): array {
		$meetingId = (string)$meeting['id'];
		$order     = 0;
		foreach ($this->store->findAll(schema: 'agenda-item', filters: ['meeting' => $meetingId]) as $item) {
			$order = max($order, (int)($item['orderNumber'] ?? 0));
		}

		$subject = $this->store->find(schema: 'decision', uuid: self::ref(value: ($stage['decision'] ?? null)));
		$item    = $this->store->save(schema: 'agenda-item', object: [
			'title'       => (string)($subject['title'] ?? ($stage['label'] ?? '')),
			'description' => (string)($stage['label'] ?? ''),
			'itemType'    => 'decision',
			'orderNumber' => ($order + 1),
			'meeting'     => $meetingId,
		]);

		$this->store->patch(
			schema: 'decision-stage',
			data: ['agendaItem' => (string)$item['id'], 'placementPending' => false],
			uuid: (string)$stage['id']
		);

		return $item;
	}//end place()

	/**
	 * The active committee stage placed on an agenda item, or null.
	 *
	 * @param string $agendaItemId The agenda item.
	 *
	 * @return array<string, mixed>|null The stage.
	 */
	private function committeeStageOn(string $agendaItemId): ?array {
		foreach ($this->store->findAll(schema: 'decision-stage', filters: ['agendaItem' => $agendaItemId]) as $stage) {
			if (($stage['stepKind'] ?? '') === 'committee' && ($stage['status'] ?? '') === 'active') {
				return $stage;
			}
		}

		return null;
	}//end committeeStageOn()

	/**
	 * The date the body decided: the decision's own date, else now.
	 *
	 * @param array<string, mixed> $decision The body's decision.
	 *
	 * @return string An ATOM date-time.
	 */
	private function dateOf(array $decision): string {
		if (trim((string)($decision['decisionDate'] ?? '')) === '') {
			return $this->timeFactory->getDateTime()->format(DateTimeImmutable::ATOM);
		}

		try {
			return (new DateTimeImmutable((string)($decision['decisionDate'] ?? '')))->format(DateTimeImmutable::ATOM);
		} catch (Throwable) {
			return $this->timeFactory->getDateTime()->format(DateTimeImmutable::ATOM);
		}
	}//end dateOf()

	/**
	 * The meeting the body decided in: the decision's meeting, else the agenda item's.
	 *
	 * @param array<string, mixed> $decision     The body's decision.
	 * @param string               $agendaItemId The agenda item it was taken on.
	 *
	 * @return string|null The meeting's uuid, or null when neither names one.
	 */
	private function meetingOf(array $decision, string $agendaItemId): ?string {
		$meeting = self::ref(value: ($decision['meeting'] ?? null));
		if ($meeting === '') {
			$meeting = self::ref(value: ($this->store->find(schema: 'agenda-item', uuid: $agendaItemId)['meeting'] ?? null));
		}

		if ($meeting === '') {
			return null;
		}

		return $meeting;
	}//end meetingOf()

	/**
	 * The uuid a reference holds (a uuid or an expanded object), or ''.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string
	 */
	private static function ref(mixed $value): string {
		return (WrittenRoundStore::idOf(value: $value) ?? '');
	}//end ref()
}//end class
