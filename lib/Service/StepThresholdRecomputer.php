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
		$group  = StepThresholdSettler::groupOf(stages: $stages, stage: $after);
		$result = $this->settler->resultOf(stage: ($after + ['thresholdKind' => ApprovalThresholdCalculator::KIND_ALL]), group: $group);
		if ($result === null) {
			// A threshold the calculator cannot read closes nothing: the step
			// waits for everyone. The result is still cached under it, so the
			// copies onto the other stages read as computed and do not each
			// record the change again.
			[$kind, $value] = self::thresholdOf(stage: $after);
			$result = [
				'outcome' => ApprovalThresholdCalculator::OUTCOME_OPEN,
				'thresholdKind' => $kind,
				'thresholdValue' => $value,
				'computedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			];
		}

		$this->applyToStep(stages: $stages, group: $group, after: $after, result: $result);

		return $this->recordChange(before: $before, after: $after, actor: $actor);
	}//end stageChanged()

	/**
	 * The subject's stages, ordered, with the changed stage as it now is.
	 *
	 * @param array<string, mixed> $after The changed stage.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function stepContext(array $after): array {
		$stages = [];
		foreach ($this->store->findAll(schema: 'decision-stage', filters: ['decision' => (string)$after['decision']]) as $stage) {
			if ((string)($stage['id'] ?? '') === (string)($after['id'] ?? '')) {
				$stage = $after;
			}

			$stages[] = $stage;
		}

		usort($stages, static fn (array $a, array $b): int => ((int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0)));

		return $stages;
	}//end stepContext()

	/**
	 * Write the new threshold and its result onto the step, and move the route.
	 *
	 * @param array<int, array<string, mixed>> $stages The subject's stages.
	 * @param array<int, array<string, mixed>> $group  The step's stages.
	 * @param array<string, mixed>             $after  The changed stage.
	 * @param array<string, mixed>             $result The step's outcome under the new threshold.
	 *
	 * @return void
	 */
	private function applyToStep(array $stages, array $group, array $after, array $result): void {
		$granted  = (($result['outcome'] ?? '') === ApprovalThresholdCalculator::OUTCOME_GRANTED);
		$closed   = array_values(array_filter($group, static fn (array $s): bool => ($s['closedByThreshold'] ?? false) === true));
		$waiting  = array_values(array_filter($group, static fn (array $s): bool => (string)($s['status'] ?? '') === 'active'));
		$reopen   = ($granted === false && $closed !== [] && $waiting === []);
		$walkOn   = ($granted === true && $waiting !== []);
		$decided  = ($result['computedAt'] ?? null);

		foreach ($group as $stage) {
			$patch = [
				'thresholdKind'   => ($after['thresholdKind'] ?? ApprovalThresholdCalculator::KIND_ALL),
				'thresholdValue'  => ($after['thresholdValue'] ?? null),
				'thresholdResult' => $result,
			];
			if ($walkOn === true && (string)($stage['status'] ?? '') === 'active') {
				$patch += ['status' => 'skipped', 'outcome' => 'skipped', 'decidedAt' => $decided, 'closedByThreshold' => true];
			}

			if ($reopen === true && ($stage['closedByThreshold'] ?? false) === true) {
				$patch['closedByThreshold'] = null;
			}

			$this->store->patch(schema: 'decision-stage', data: $patch, uuid: (string)$stage['id']);
		}

		if ($walkOn === true) {
			$this->advancer->walkOnFrom(stages: $stages, stage: $after);
		}

		if ($reopen === true) {
			$this->advancer->reopenWithdrawnStages(stages: $stages, reopened: $closed);
		}
	}//end applyToStep()

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
