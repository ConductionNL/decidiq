<?php

/**
 * Where a route's stage rows move when an action lands.
 *
 * Split out of `ApprovalRouteService` on 2026-09-19, when that class reached a
 * weighted method count of 83 against a threshold of 55 and phpmd refused it on
 * `development`. The number is the occasion, not the reason: the class had
 * grown two jobs. One answers "may this happen, and what is the route made of"
 * — the guard orchestration, instantiation from a template, the reads. The
 * other is a state machine over `decision-stage` rows: which stage is live,
 * which one becomes live next, and what a return rewinds. This class is the
 * second job and nothing else.
 *
 * WHY THE VERB HANDLERS TRAVELLED TOGETHER. `completeAndAdvance`, the two
 * return paths and their two helpers all read and write the same thing: the
 * `status`, `outcome` and `decidedAt` of a set of sibling stage rows. Splitting
 * anywhere inside that set would have left two classes patching the same three
 * fields, which is how an engine and its helper start to disagree about where a
 * route is. REQ-ARE-004 forbids a second engine, and this is not one: nothing
 * outside `ApprovalRouteService` constructs it, and every rule about whether an
 * action is ALLOWED stayed with the guard.
 *
 * WHAT DID NOT MOVE, DELIBERATELY. `appendAction` writes the action row, not a
 * stage, and the ordering promise in `record()` — stage first, action second —
 * is only legible with both calls side by side. `projectTasks` is a projection
 * onto a surface, explicitly not part of whether the route advances.
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
 * @spec openspec/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * Moves a route's stage rows when an action completes or returns one.
 *
 * @spec openspec/specs/approval-routes/spec.md
 * @spec openspec/specs/parafering-route-runtime/spec.md
 */
final class ApprovalRouteAdvancer {
	/**
	 * Actions that COMPLETE the active stage, mapped to the outcome they record.
	 *
	 * `returned` is deliberately absent: it does not complete a stage, it
	 * re-opens an earlier one or concludes the route back to its sender, and
	 * treating it as a completion is precisely the mistake `rejected` and
	 * `deferred` invite.
	 *
	 * Public because `ApprovalRouteService::record()` asks this class whether a
	 * verb completes a stage. One table, one owner: a second copy beside the
	 * caller is how the two would come to recognise different verbs.
	 *
	 * @var array<string, string>
	 */
	public const COMPLETING_ACTIONS = [
		'approved' => 'approved',
		'endorsed' => 'endorsed',
		'advised' => 'advised',
		'skipped' => 'skipped',
	];

	/**
	 * Refuses to close a step while an open approval waits on somebody else.
	 *
	 * Built here, not injected: a pure rule with no dependency, so neither
	 * this class's callers nor ApprovalRouteService's have to change.
	 *
	 * @var OpenApprovalGuard
	 */
	private readonly OpenApprovalGuard $openGuard;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes the stage rows.
	 * @param ApprovalStageActivator|null $activator Resolves a stage's actor rule
	 *        at the moment it becomes live. Nullable for the same reason it is
	 *        nullable on `ApprovalRouteService`: a missing activator means a step
	 *        naming a rule keeps the actor it was given, never that the rule
	 *        silently resolved.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ?ApprovalStageActivator $activator = null,
	) {
		$this->openGuard = new OpenApprovalGuard();
	}//end __construct()

	/**
	 * Refuse the completion that would close a step on which a mandatory open
	 * approval still waits on somebody other than the actor (REQ-DWP-001).
	 *
	 * Asked only when this completion ends the step: while a parallel sibling
	 * is still signing, the step stays where it is and nothing walks past the
	 * open approval. Validated BEFORE any write, so a refusal leaves no stage
	 * change and no action row, as every other refusal here does.
	 *
	 * @param string                           $subject The subject's uuid.
	 * @param array<int, array<string, mixed>> $stages  The subject's stages.
	 * @param array<string, mixed>             $active  The stage being completed.
	 * @param string                           $actor   Who is acting.
	 *
	 * @return array<int, array<string, mixed>> The subject's actions, read once for the close that follows.
	 *
	 * @throws RuntimeException Naming each open approval and its assignee.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function assertNoOpenApprovalHoldsTheStep(string $subject, array $stages, array $active, string $actor): array {
		$trail = $this->store->findAll(schema: 'approval-action', filters: ['subject' => $subject]);
		if ($this->groupStillSigning(stages: $stages, active: $active) === false) {
			$this->openGuard->assertStepMayClose(stage: $active, actions: $trail, actor: $actor);
		}

		return $trail;
	}//end assertNoOpenApprovalHoldsTheStep()

	/**
	 * End the actor's own open approvals on the step they just acted on.
	 *
	 * The act IS the approval the work item was waiting for, so it leaves the
	 * item in the state the act leaves its own row in, and the item drops off
	 * the actor's task list.
	 *
	 * @param array<int, array<string, mixed>> $trail  The subject's actions, read before the act.
	 * @param array<string, mixed>             $active The stage acted on.
	 * @param string                           $actor  Who acted.
	 * @param string                           $verb   What they did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function closeOwnOpenApprovals(array $trail, array $active, string $actor, string $verb): void {
		foreach ($this->openGuard->ownOpenOnStep(stage: $active, actions: $trail, actor: $actor) as $item) {
			$this->store->patch(
				schema: 'approval-action',
				data: ['state' => $this->openGuard->stateForVerb(verb: $verb)],
				uuid: (string)($item['id'] ?? ''),
			);
		}
	}//end closeOwnOpenApprovals()

	/**
	 * Every active stage — one ordinarily, several in a parallel group.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 *
	 * @return array<int, array<string, mixed>> The active stages.
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	public function activeStages(array $stages): array {
		$actives = [];
		foreach ($stages as $stage) {
			if ((string)($stage['status'] ?? '') === 'active') {
				$actives[] = $stage;
			}
		}

		return $actives;
	}//end activeStages()

	/**
	 * Who owns the subject a route hangs off.
	 *
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema slug.
	 *
	 * @return string The owner, or an empty string when the subject cannot be read.
	 *
	 * @spec openspec/specs/approval-routes/spec.md
	 */
	public function ownerOf(string $subject, string $subjectSchema): string {
		$object = $this->store->find(schema: $subjectSchema, uuid: $subject);
		if (is_array($object) === false) {
			return '';
		}

		$self = ($object['@self'] ?? []);
		$selfOwner = '';
		if (is_array($self) === true) {
			$selfOwner = (string)($self['owner'] ?? '');
		}

		return (string)($object['owner'] ?? $selfOwner);
	}//end ownerOf()

	/**
	 * Route a `returned` action to its meaning.
	 *
	 * Naming a step rewinds the route to it. Naming none concludes the route
	 * back to its sender: that is what dossiq's terugsturen has always meant —
	 * the voorstel goes back to the steller, and the approvers after this one
	 * are never asked.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed active stage.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	public function applyReturnVerb(array $action, array $stages, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		if ($target > 0) {
			$this->applyReturn(action: $action, stages: $stages, active: $active);

			return;
		}

		$this->applyTerminalReturn(stages: $stages, active: $active);
	}//end applyReturnVerb()

	/**
	 * Complete the addressed stage and make the next group active.
	 *
	 * A PARALLEL group advances only when its last live member completes: a
	 * group with a sibling still active stays where it is. When no later stage
	 * remains the route is finished, and NO stage is left active — a completed
	 * route that still shows an active stage would keep inviting actions on a
	 * decision already taken.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed stage.
	 * @param string $verb The action verb.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	public function completeAndAdvance(array $stages, array $active, string $verb): void {
		$status = 'decided';
		if ($verb === 'skipped') {
			$status = 'skipped';
		}

		$this->completeWithOutcome(stages: $stages, active: $active, outcome: self::COMPLETING_ACTIONS[$verb], status: $status);
	}//end completeAndAdvance()

	/**
	 * Complete the addressed stage with a given outcome and advance the route.
	 *
	 * The same walk as `completeAndAdvance()`, for a stage whose outcome is not
	 * an approval verb: an intake step completes with its admissibility verdict.
	 *
	 * @param array<int, array<string, mixed>> $stages  All stages, in order.
	 * @param array<string, mixed>             $active  The addressed stage.
	 * @param string                           $outcome The outcome the stage records.
	 * @param string                           $status  The status it ends in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function completeWithOutcome(array $stages, array $active, string $outcome, string $status = 'decided'): void {
		$this->store->patch(
			schema: 'decision-stage',
			data: [
				'status' => $status,
				'outcome' => $outcome,
				'decidedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
			uuid: (string)$active['id'],
		);

		if ($this->groupStillSigning(stages: $stages, active: $active) === true) {
			// A parallel sibling is still signing; the group is not done.
			return;
		}

		$nextSequence = $this->nextPendingSequence(stages: $stages, after: (int)$active['sequence']);
		if ($nextSequence === null) {
			return;
		}

		$this->activateSequence(stages: $stages, active: $active, sequence: $nextSequence);
	}//end completeWithOutcome()

	/**
	 * Make the pending stages of one sequence live.
	 *
	 * @param array<int, array<string, mixed>> $stages   All stages, in order.
	 * @param array<string, mixed>             $active   The stage that just completed.
	 * @param int                              $sequence The sequence to activate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	private function activateSequence(array $stages, array $active, int $sequence): void {
		$owner = $this->ownerOf(
			subject: (string)($active['decision'] ?? ''),
			subjectSchema: (string)($active['note'] ?? ''),
		);

		foreach ($stages as $stage) {
			if ((int)$stage['sequence'] === $sequence && (string)($stage['status'] ?? '') === 'pending') {
				// The rule on this step resolves NOW, against today's
				// organisation record, not against the one the route started
				// under. That is the whole reason the rule travelled onto the
				// stage instead of being resolved at instantiation.
				$patch = ($this->activator?->activationPatch(stage: $stage, subjectOwner: $owner) ?? ['status' => 'active']);
				$this->store->patch(schema: 'decision-stage', data: $patch, uuid: (string)$stage['id']);
			}
		}
	}//end activateSequence()

	/**
	 * Conclude the route back to its sender.
	 *
	 * The addressed stage records the `returned` outcome. Every OTHER stage
	 * that is still active or pending goes back to `pending` with its outcome
	 * cleared — never `skipped`, because nobody chose to skip it; the route
	 * simply ended before it. No stage is left active, which is the engine's
	 * own definition of a concluded route, and `finalOutcomeOf` then reads
	 * `returned` off the last decided stage.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The stage whose actor returned it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	private function applyTerminalReturn(array $stages, array $active): void {
		$this->concludeWithOutcome(stages: $stages, active: $active, outcome: 'returned');
	}//end applyTerminalReturn()

	/**
	 * Close the route at the addressed stage with a given outcome.
	 *
	 * The addressed stage records the outcome; every other stage still active
	 * or pending goes back to `pending` with its outcome cleared, so no stage
	 * is left active and no later step becomes live. A return to the sender
	 * closes with `returned`, an inadmissible intake with `ended-at-intake`.
	 *
	 * @param array<int, array<string, mixed>> $stages  All stages, in order.
	 * @param array<string, mixed>             $active  The stage the route ends at.
	 * @param string                           $outcome The outcome it records.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function concludeWithOutcome(array $stages, array $active, string $outcome): void {
		$this->store->patch(
			schema: 'decision-stage',
			data: [
				'status' => 'decided',
				'outcome' => $outcome,
				'decidedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
			uuid: (string)$active['id'],
		);

		foreach ($stages as $stage) {
			$status = (string)($stage['status'] ?? '');
			if ((string)$stage['id'] === (string)$active['id'] || ($status !== 'active' && $status !== 'pending')) {
				continue;
			}

			$this->store->patch(
				schema: 'decision-stage',
				data: ['status' => 'pending', 'outcome' => null, 'decidedAt' => null],
				uuid: (string)$stage['id'],
			);
		}
	}//end concludeWithOutcome()

	/**
	 * Re-open an earlier stage and reset everything after it.
	 *
	 * The outcomes of the reset stages are CLEARED, because a stage that is
	 * pending again while still showing an outcome reads as decided to every
	 * consumer that looks at the outcome rather than the status.
	 *
	 * The ApprovalActions are NOT touched. They are what happened; the stages
	 * are where the route is.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The active stage.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the target step is not before the active one.
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	private function applyReturn(array $action, array $stages, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		$activeSequence = (int)$active['sequence'];
		if ($target < 1 || $target >= $activeSequence) {
			throw new RuntimeException('A return must name a step BEFORE the active one.');
		}

		foreach ($stages as $stage) {
			$sequence = (int)$stage['sequence'];
			if ($sequence < $target) {
				continue;
			}

			$status = 'pending';
			if ($sequence === $target) {
				$status = 'active';
			}

			$this->store->patch(
				schema: 'decision-stage',
				data: ['status' => $status, 'outcome' => null, 'decidedAt' => null],
				uuid: (string)$stage['id'],
			);
		}
	}//end applyReturn()

	/**
	 * Whether the addressed stage's parallel group still has a live signer.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed stage.
	 *
	 * @return boolean True when a sibling at the same sequence is still active.
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	private function groupStillSigning(array $stages, array $active): bool {
		$sequence = (int)$active['sequence'];
		foreach ($stages as $stage) {
			$sibling = ((int)$stage['sequence'] === $sequence && (string)$stage['id'] !== (string)$active['id']);
			if ($sibling === true && (string)($stage['status'] ?? '') === 'active') {
				return true;
			}
		}

		return false;
	}//end groupStillSigning()

	/**
	 * The lowest pending sequence after the given one.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 * @param int $after The sequence to search past.
	 *
	 * @return int|null The next sequence, or null when none remains.
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	private function nextPendingSequence(array $stages, int $after): ?int {
		foreach ($stages as $stage) {
			if ((int)$stage['sequence'] > $after && (string)($stage['status'] ?? '') === 'pending') {
				return (int)$stage['sequence'];
			}
		}

		return null;
	}//end nextPendingSequence()
}//end class
