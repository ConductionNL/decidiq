<?php

/**
 * Open Approval Guard
 *
 * An approval that is still awaited is a work item, not an absence. This turns
 * that into two things: a refusal that NAMES the approval holding a subject up,
 * and the list of open approvals a person actually has to do.
 *
 * WHY THE REFUSAL NAMES THE ACTION AND ITS ASSIGNEE
 * -------------------------------------------------
 * "This decision cannot advance" sends somebody to ask three people whether it
 * was them. "Waiting on the endorsement at step 2, assigned to j.jansen, due
 * yesterday" is the same refusal and ends the question. The cost of the second
 * is one sentence.
 *
 * WHY AN UNASSIGNED OPEN ACTION STILL BLOCKS
 * -------------------------------------------
 * It blocks and says so. An approval nobody has been asked for is exactly how a
 * route sits still for a week with nothing to show for it, and treating it as
 * absent would let the subject walk past an approval that was meant to happen.
 *
 *
 * WHERE IT RUNS
 * -------------
 * ApprovalRouteService::record() has ApprovalRouteAdvancer ask
 * assertStepMayClose() before the completion that would close a step, and
 * writes stateForVerb() onto every action it appends, so a recorded act never reads as an open work item. Stored actions
 * from before that write are backfilled by ApprovalActionStateRepair.
 * taskListFor() still has no surface: nothing writes an open work item with an
 * assignee yet (task 1.3 of the-decision-as-a-walked-process).
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Blocks a subject on its open mandatory approvals, and lists them as work.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 */
final class OpenApprovalGuard {
	/**
	 * The state an approval is in while it is still awaited.
	 *
	 * @var string
	 */
	public const STATE_OPEN = 'open';

	/**
	 * The state of an action that let its step pass.
	 *
	 * @var string
	 */
	public const STATE_GRANTED = 'granted';

	/**
	 * The state of an action that turned its step down or sent it back.
	 *
	 * @var string
	 */
	public const STATE_REFUSED = 'refused';

	/**
	 * The state each recorded verb ends its action in.
	 *
	 * A skip and a lapse let the step pass without a signature, so they end
	 * granted, the same as StageLapsePolicy::lapseAction() writes for a lapse
	 * that does not reject. A return sends the subject back, so it ends refused.
	 *
	 * @var array<string, string>
	 */
	private const VERB_STATES = [
		'approved' => self::STATE_GRANTED,
		'endorsed' => self::STATE_GRANTED,
		'advised' => self::STATE_GRANTED,
		'skipped' => self::STATE_GRANTED,
		'lapsed' => self::STATE_GRANTED,
		'rejected' => self::STATE_REFUSED,
		'returned' => self::STATE_REFUSED,
	];

	/**
	 * The terminal state a recorded verb leaves its action in.
	 *
	 * @param string $verb The recorded verb (`approved`, `returned`, ...).
	 *
	 * @return string The state, or an empty string for a verb this does not know.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function stateForVerb(string $verb): string {
		return (self::VERB_STATES[$verb] ?? '');
	}//end stateForVerb()

	/**
	 * The open work items on a stage's step that wait on somebody other than the actor.
	 *
	 * A work item is an action with state `open` and no recorded verb: a row
	 * carrying a verb is the record of something somebody did, whatever its
	 * stored state says, so a row written before the state existed never
	 * blocks. The actor's own open items are not counted, because the act the
	 * actor is recording is what ends them.
	 *
	 * @param array<string, mixed>     $stage   The stage being completed (its `sequence` is the step).
	 * @param array<int, mixed>        $actions The subject's actions.
	 * @param string                   $actor   Who is acting.
	 *
	 * @return list<array<string, mixed>> The open items on that step waiting on others.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function othersOpenOnStep(array $stage, array $actions, string $actor): array {
		$others = [];
		foreach ($this->openOnStep(stage: $stage, actions: $actions) as $action) {
			if ((string)($action['assignee'] ?? '') !== $actor) {
				$others[] = $action;
			}
		}

		return $others;
	}//end othersOpenOnStep()

	/**
	 * The actor's own open work items on a stage's step, which the act ends.
	 *
	 * @param array<string, mixed> $stage   The stage being completed.
	 * @param array<int, mixed>    $actions The subject's actions.
	 * @param string               $actor   Who is acting.
	 *
	 * @return list<array<string, mixed>> The actor's open items on that step.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function ownOpenOnStep(array $stage, array $actions, string $actor): array {
		$own = [];
		foreach ($this->openOnStep(stage: $stage, actions: $actions) as $action) {
			if ((string)($action['assignee'] ?? '') === $actor) {
				$own[] = $action;
			}
		}

		return $own;
	}//end ownOpenOnStep()

	/**
	 * Refuse to close a stage's step while a mandatory open item waits on somebody else.
	 *
	 * @param array<string, mixed> $stage   The stage being completed.
	 * @param array<int, mixed>    $actions The subject's actions.
	 * @param string               $actor   Who is acting.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming each open item and its assignee.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function assertStepMayClose(array $stage, array $actions, string $actor): void {
		$this->assertCanAdvance(
			step: $stage,
			actions: $this->othersOpenOnStep(stage: $stage, actions: $actions, actor: $actor)
		);
	}//end assertStepMayClose()

	/**
	 * The open work items recorded against a stage's step.
	 *
	 * @param array<string, mixed> $stage   The stage.
	 * @param array<int, mixed>    $actions The subject's actions.
	 *
	 * @return list<array<string, mixed>> The open items on the stage's step.
	 */
	private function openOnStep(array $stage, array $actions): array {
		$step = (int)($stage['sequence'] ?? 0);
		$open = [];
		foreach ($actions as $action) {
			if (is_array($action) === false
				|| (string)($action['state'] ?? '') !== self::STATE_OPEN
				|| trim((string)($action['action'] ?? '')) !== ''
				|| (int)($action['step'] ?? 0) !== $step
			) {
				continue;
			}

			$open[] = $action;
		}

		return $open;
	}//end openOnStep()

	/**
	 * The open mandatory actions standing on a step.
	 *
	 * @param array<string, mixed> $step The step.
	 * @param array<int, array<string, mixed>> $actions The actions recorded against it.
	 *
	 * @return array<int, array<string, mixed>> The open mandatory actions.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function blockingActions(array $step, array $actions): array {
		// An optional step cannot block: skipping it is already allowed, so an
		// open action on it is a suggestion, not a gate.
		if (($step['mandatory'] ?? true) !== true) {
			return [];
		}

		$blocking = [];
		foreach ($actions as $action) {
			if (is_array($action) === false) {
				continue;
			}

			if ((string)($action['state'] ?? '') !== self::STATE_OPEN) {
				continue;
			}

			$blocking[] = $action;
		}

		return $blocking;
	}//end blockingActions()

	/**
	 * Refuse to advance a subject whose current step has an open mandatory
	 * approval, naming the approval and its assignee.
	 *
	 * @param array<string, mixed> $step The current step.
	 * @param array<int, array<string, mixed>> $actions The actions recorded against it.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When an open mandatory action stands.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 *
	 * @orphan-auth exclude Dormant on purpose and cannot be called yet. The
	 * schema defaults an action's state to open and nothing ever writes a
	 * terminal value, so calling this today would block every subject on every
	 * action ever recorded. Reaching it needs appendAction() to write state
	 * plus a backfill migration for stored rows, scoped as item 4 of
	 * openspec/changes/the-decision-as-a-walked-process/reachability-scope.md
	 * and deliberately held back so the migration does not ride along with a
	 * guard. Covered in isolation by tests/Unit/Service/DecisionWalkedProcessTest.php
	 * at lines 336, 353 and 371, which is coverage of the method and not of any
	 * call site, because there is none.
	 */
	public function assertCanAdvance(array $step, array $actions): void {
		$blocking = $this->blockingActions(step: $step, actions: $actions);
		if ($blocking === []) {
			return;
		}

		$named = [];
		foreach ($blocking as $action) {
			$assignee = (string)($action['assignee'] ?? '');
			if ($assignee === '') {
				$named[] = sprintf('an approval at step %d that has not been assigned to anybody', (int)($action['step'] ?? 0));
				continue;
			}

			$named[] = sprintf('%s at step %d', $assignee, (int)($action['step'] ?? 0));
		}

		$howMany = sprintf('%d approvals', count($named));
		if (count($named) === 1) {
			$howMany = 'an approval';
		}

		throw new RuntimeException(
			sprintf(
				'This subject is waiting on %s: %s.',
				$howMany,
				implode(', ', $named)
			)
		);
	}//end assertCanAdvance()

	/**
	 * Whether a subject is blocked.
	 *
	 * @param array<string, mixed> $step The current step.
	 * @param array<int, array<string, mixed>> $actions The actions recorded against it.
	 *
	 * @return bool True when it cannot advance.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function isBlocked(array $step, array $actions): bool {
		return ($this->blockingActions(step: $step, actions: $actions) !== []);
	}//end isBlocked()

	/**
	 * One person's open approvals, as work items with their subject, step and
	 * due date.
	 *
	 * Sorted by due date with the undated last, because a task list ordered by
	 * insertion is a task list nobody reads past the top.
	 *
	 * @param string $assignee Whose list this is.
	 * @param array<int, array<string, mixed>> $actions Every action that might be theirs.
	 *
	 * @return array<int, array<string, mixed>> Their open approvals.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function taskListFor(string $assignee, array $actions): array {
		$mine = [];
		foreach ($actions as $action) {
			if (is_array($action) === false
				|| (string)($action['state'] ?? '') !== self::STATE_OPEN
				|| (string)($action['assignee'] ?? '') !== $assignee
			) {
				continue;
			}

			$mine[] = [
				'subject' => (string)($action['subject'] ?? ''),
				'subjectSchema' => (string)($action['subjectSchema'] ?? ''),
				'step' => (int)($action['step'] ?? 0),
				'dueAt' => (string)($action['dueAt'] ?? ''),
				'assignee' => $assignee,
			];
		}

		usort(
			$mine,
			static function (array $first, array $second): int {
				// An item with no due date sorts last rather than first, which
				// is where an empty string would otherwise put it.
				$dueOfFirst = $first['dueAt'];
				if ($dueOfFirst === '') {
					$dueOfFirst = '9999-12-31';
				}

				$dueOfSecond = $second['dueAt'];
				if ($dueOfSecond === '') {
					$dueOfSecond = '9999-12-31';
				}

				return ($dueOfFirst <=> $dueOfSecond);
			}
		);

		return $mine;
	}//end taskListFor()
}//end class
