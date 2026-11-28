<?php

/**
 * Step Threshold Recomputer
 *
 * Recomputes a step when an administrator changes its threshold, and records
 * the change as an action (REQ-DWP-002).
 *
 * The step's outcome is a function of its stages, so changing the threshold
 * changes the outcome without anybody granting anything again. Lowering a
 * share can close a step that was waiting: the stages still waiting close as
 * `skipped` with `closedByThreshold` and the route walks on. Raising it can
 * reopen a step the threshold closed: the stages it closed go back to active
 * and every later stage of the route back to pending, the same rewind a
 * withdrawn grant causes. Either way one `threshold-changed` action names the
 * old and the new value and who changed it, because a step whose outcome moved
 * with nothing to point at is a step nobody can explain.
 *
 * ONE STEP, ONE THRESHOLD. The changed stage's threshold is copied onto the
 * other stages of its step, so the next completion counts against the same
 * number whoever acts.
 *
 * WHY IT DOES NOT LOOP. Every stage this writes carries the new threshold and
 * a `thresholdResult` computed under it. An update whose new threshold equals
 * the one its cached result was computed under is the recomputer's own write,
 * or a save that changed nothing, and is left alone.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;

/**
 * Applies a threshold change to the step it belongs to.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 */
class StepThresholdRecomputer {
	/**
	 * Works out the step's outcome.
	 *
	 * @var StepThresholdSettler
	 */
	private readonly StepThresholdSettler $settler;

	/**
	 * Shapes the recorded action.
	 *
	 * @var ApprovalThresholdCalculator
	 */
	private readonly ApprovalThresholdCalculator $calculator;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore   $store    Reads the step, writes the stages and the action.
	 * @param ApprovalRouteAdvancer $advancer Walks the route on, or rewinds it.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalRouteAdvancer $advancer,
	) {
		$this->settler    = new StepThresholdSettler(store: $store);
		$this->calculator = new ApprovalThresholdCalculator();
	}//end __construct()

	/**
	 * Apply a stage update to its step when the update changed the threshold.
	 *
	 * @param array<string, mixed> $before The stage as it was.
	 * @param array<string, mixed> $after  The stage as it now is.
	 * @param string               $actor  Who changed it.
	 *
	 * @return array<string, mixed>|null The recorded action, or null when the threshold did not change.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function stageChanged(array $before, array $after, string $actor): ?array {
		if (self::thresholdOf(stage: $before) === self::thresholdOf(stage: $after)
			|| self::alreadyComputed(stage: $after) === true
			|| trim((string)($after['decision'] ?? '')) === ''
		) {
			return null;
		}

		$stages = $this->stepContext(after: $after);
		$group  = $this->settler->groupOf(stages: $stages, stage: $after);
		$result = $this->resultUnder(after: $after, group: $group);

		$reopen = $this->reopens(group: $group, result: $result);
		$this->writeThreshold(group: $group, after: $after, result: $result, reopen: $reopen);
		$this->moveRoute(stages: $stages, group: $group, result: $result, reopen: $reopen);

		return $this->recordChange(before: $before, after: $after, actor: $actor);
	}//end stageChanged()

	/**
	 * The subject's stages, ordered, with the changed stage as it now is and
	 * its step under the new threshold.
	 *
	 * @param array<string, mixed> $after The changed stage.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function stepContext(array $after): array {
		$stages = [];
		$threshold = [
			'thresholdKind'  => ($after['thresholdKind'] ?? ApprovalThresholdCalculator::KIND_ALL),
			'thresholdValue' => ($after['thresholdValue'] ?? null),
		];
		foreach ($this->store->findAll(schema: 'decision-stage', filters: ['decision' => (string)$after['decision']]) as $stage) {
			if ((string)($stage['id'] ?? '') === (string)($after['id'] ?? '')) {
				$stage = $after;
			}

			if ((string)($stage['route'] ?? '') === (string)($after['route'] ?? '')
				&& (int)($stage['sequence'] ?? 0) === (int)($after['sequence'] ?? 0)
			) {
				// The whole step counts under the new threshold from now on.
				$stage = ($threshold + $stage);
			}

			$stages[] = $stage;
		}

		usort($stages, static fn (array $a, array $b): int => ((int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0)));

		return $stages;
	}//end stepContext()

	/**
	 * The step's outcome under the new threshold, with the inputs it came from.
	 *
	 * A threshold the calculator cannot read closes nothing: the step waits for
	 * everyone. The result is still cached under it, so the copies onto the
	 * other stages read as computed and do not each record the change again.
	 *
	 * @param array<string, mixed>             $after The changed stage.
	 * @param array<int, array<string, mixed>> $group The step's stages.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function resultUnder(array $after, array $group): array {
		$result = $this->settler->resultOf(stage: ($after + ['thresholdKind' => ApprovalThresholdCalculator::KIND_ALL]), group: $group);
		if ($result !== null) {
			return $result;
		}

		[$kind, $value] = self::thresholdOf(stage: $after);

		return [
			'outcome'        => ApprovalThresholdCalculator::OUTCOME_OPEN,
			'thresholdKind'  => $kind,
			'thresholdValue' => $value,
			'computedAt'     => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];
	}//end resultUnder()

	/**
	 * Whether the step reopens: the threshold had closed it and it no longer reaches it.
	 *
	 * @param array<int, array<string, mixed>> $group  The step's stages.
	 * @param array<string, mixed>             $result The step's outcome under the new threshold.
	 *
	 * @return bool True when the stages the threshold closed go back to active.
	 */
	private function reopens(array $group, array $result): bool {
		if ($result['outcome'] === ApprovalThresholdCalculator::OUTCOME_GRANTED || self::waiting(group: $group) !== []) {
			return false;
		}

		return self::closedByThreshold(group: $group) !== [];
	}//end reopens()

	/**
	 * Copy the new threshold and its result onto every stage of the step.
	 *
	 * @param array<int, array<string, mixed>> $group  The step's stages.
	 * @param array<string, mixed>             $after  The changed stage.
	 * @param array<string, mixed>             $result The step's outcome under the new threshold.
	 * @param bool                             $reopen Whether the stages the threshold closed reopen.
	 *
	 * @return void
	 */
	private function writeThreshold(array $group, array $after, array $result, bool $reopen): void {
		foreach ($group as $stage) {
			$patch = [
				'thresholdKind'   => ($after['thresholdKind'] ?? ApprovalThresholdCalculator::KIND_ALL),
				'thresholdValue'  => ($after['thresholdValue'] ?? null),
				'thresholdResult' => $result,
			];
			if ($reopen === true && ($stage['closedByThreshold'] ?? false) === true) {
				$patch['closedByThreshold'] = null;
			}

			$this->store->patch(schema: 'decision-stage', data: $patch, uuid: (string)$stage['id']);
		}
	}//end writeThreshold()

	/**
	 * Walk the route on from a step that is now granted, or rewind it to a step that reopened.
	 *
	 * A granted step still waiting closes through the engine's own completion:
	 * one waiting stage completes as skipped, and the settler closes the rest
	 * and walks on, exactly as when a grant reaches the threshold.
	 *
	 * @param array<int, array<string, mixed>> $stages The subject's stages, the step under the new threshold.
	 * @param array<int, array<string, mixed>> $group  The step's stages.
	 * @param array<string, mixed>             $result The step's outcome under the new threshold.
	 * @param bool                             $reopen Whether the stages the threshold closed reopen.
	 *
	 * @return void
	 */
	private function moveRoute(array $stages, array $group, array $result, bool $reopen): void {
		if ($reopen === true) {
			$this->advancer->reopenWithdrawnStages(stages: $stages, reopened: self::closedByThreshold(group: $group));

			return;
		}

		$waiting = self::waiting(group: $group);
		if ($result['outcome'] !== ApprovalThresholdCalculator::OUTCOME_GRANTED || $waiting === []) {
			return;
		}

		$this->advancer->completeWithOutcome(stages: $stages, active: $waiting[0], outcome: 'skipped', status: 'skipped');
		$this->store->patch(schema: 'decision-stage', data: ['closedByThreshold' => true], uuid: (string)$waiting[0]['id']);
	}//end moveRoute()

	/**
	 * The stages of the step still waiting for their approver.
	 *
	 * @param array<int, array<string, mixed>> $group The step's stages.
	 *
	 * @return array<int, array<string, mixed>> The active stages.
	 */
	private static function waiting(array $group): array {
		return array_values(array_filter($group, static fn (array $stage): bool => (string)($stage['status'] ?? '') === 'active'));
	}//end waiting()

	/**
	 * The stages of the step the threshold closed.
	 *
	 * @param array<int, array<string, mixed>> $group The step's stages.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private static function closedByThreshold(array $group): array {
		return array_values(array_filter($group, static fn (array $stage): bool => ($stage['closedByThreshold'] ?? false) === true));
	}//end closedByThreshold()

	/**
	 * Append the action that records the change.
	 *
	 * @param array<string, mixed> $before The stage as it was.
	 * @param array<string, mixed> $after  The stage as it now is.
	 * @param string               $actor  Who changed it.
	 *
	 * @return array<string, mixed> The stored action.
	 */
	private function recordChange(array $before, array $after, string $actor): array {
		$action = $this->calculator->recomputationAction(
			step: ['order' => (int)($after['sequence'] ?? 0)],
			before: self::numberOf(value: $before['thresholdValue'] ?? null),
			after: self::numberOf(value: $after['thresholdValue'] ?? null),
			actor: $actor,
		);

		return $this->store->save(
			schema: 'approval-action',
			object: $action + [
				'subject'       => (string)$after['decision'],
				'subjectSchema' => (string)($after['note'] ?? ''),
				'actorType'     => 'user',
				'comment'       => sprintf(
					'Threshold changed from %s to %s.',
					self::describe(stage: $before),
					self::describe(stage: $after)
				),
			],
		);
	}//end recordChange()

	/**
	 * A stage's threshold as one comparable value.
	 *
	 * @param array<string, mixed> $stage The stage, or a cached result.
	 *
	 * @return array{0: string, 1: float|null} The kind and the value.
	 */
	private static function thresholdOf(array $stage): array {
		return [
			(string)($stage['thresholdKind'] ?? ApprovalThresholdCalculator::KIND_ALL),
			self::numberOf(value: $stage['thresholdValue'] ?? null),
		];
	}//end thresholdOf()

	/**
	 * Whether the stage's cached result was already computed under its threshold.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return bool True when the recomputer, or nothing at all, changed it.
	 */
	private static function alreadyComputed(array $stage): bool {
		$cached = ($stage['thresholdResult'] ?? null);
		if (is_array($cached) === false) {
			return false;
		}

		return self::thresholdOf(stage: $cached) === self::thresholdOf(stage: $stage);
	}//end alreadyComputed()

	/**
	 * A threshold value as a number, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null The number.
	 */
	private static function numberOf(mixed $value): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end numberOf()

	/**
	 * A threshold in words, for the action's comment.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return string The threshold.
	 */
	private static function describe(array $stage): string {
		[$kind, $value] = self::thresholdOf(stage: $stage);
		if ($value === null) {
			return $kind;
		}

		return sprintf('%s %s', $kind, rtrim(rtrim(sprintf('%.4F', $value), '0'), '.'));
	}//end describe()
}//end class
