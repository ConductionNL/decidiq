<?php

/**
 * Approval Step Threshold
 *
 * Closes a step once enough of its approvers have granted (REQ-DWP-002).
 *
 * A step is the group of stages that share a route and a sequence: one stage
 * per approver. Without a threshold the group walks on once every stage has
 * completed, which is what `thresholdKind = all` means and what every stored
 * route keeps doing. A stage that declares `count` or `share` asks
 * ApprovalThresholdCalculator after each completion. When the calculator says
 * `granted`, the stages still waiting in the group are closed as `skipped`,
 * marked `closedByThreshold`, so the route walks on without asking people
 * whose answer can no longer change it.
 *
 * THE OUTCOME IS CACHED WITH ITS INPUTS, NOT TRUSTED ON ITS OWN
 * --------------------------------------------------------------
 * Every stage of the group gets `thresholdResult`: the outcome, the grants and
 * refusals counted, the grants required, the approvers, and the threshold kind
 * and value it was computed under. A reader can see why the step stands where
 * it does, and a later recomputation can tell whether its inputs moved. The
 * actions and stage outcomes stay the source; the cache only records the last
 * computation.
 *
 * A threshold the calculator cannot read (a `share` without a value, a share
 * above 1) computes nothing: the step falls back to waiting for everyone, the
 * behaviour it would have had without a threshold, rather than closing on a
 * number nobody declared.
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
use InvalidArgumentException;

/**
 * Settles a step's threshold after one of its stages completes.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 */
final class StepThresholdSettler {
	/**
	 * Stage outcomes that count as a grant.
	 *
	 * @var array<int, string>
	 */
	private const GRANTING_OUTCOMES = ['approved', 'endorsed'];

	/**
	 * The calculator.
	 *
	 * @var ApprovalThresholdCalculator
	 */
	private readonly ApprovalThresholdCalculator $calculator;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Writes the closed stages and the cached result.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
		$this->calculator = new ApprovalThresholdCalculator();
	}//end __construct()

	/**
	 * Whether a stage closes at a threshold rather than when everybody answered.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return bool True for `count` and `share`.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public static function declares(array $stage): bool {
		$kind = (string)($stage['thresholdKind'] ?? ApprovalThresholdCalculator::KIND_ALL);

		return in_array($kind, [ApprovalThresholdCalculator::KIND_COUNT, ApprovalThresholdCalculator::KIND_SHARE], true);
	}//end declares()

	/**
	 * The stages of the step the given stage belongs to: same route, same sequence.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages of the subject.
	 * @param array<string, mixed>             $stage  One stage of the step.
	 *
	 * @return array<int, array<string, mixed>> The step's stages, the given one included.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public static function groupOf(array $stages, array $stage): array {
		$route    = (string)($stage['route'] ?? '');
		$sequence = (int)($stage['sequence'] ?? 0);

		return array_values(
			array_filter(
				$stages,
				static fn (array $row): bool => (string)($row['route'] ?? '') === $route && (int)($row['sequence'] ?? 0) === $sequence
			)
		);
	}//end groupOf()

	/**
	 * Settle the step after the given stage completed.
	 *
	 * @param array<int, array<string, mixed>> $stages The subject's stages, as read before the completion.
	 * @param array<string, mixed>             $active The stage that completed, with its new status and outcome.
	 *
	 * @return array<string, mixed>|null The computed result, or null when the step declares no usable threshold.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function settle(array $stages, array $active): ?array {
		if (self::declares(stage: $active) === false) {
			return null;
		}

		$group = [];
		foreach (self::groupOf(stages: $stages, stage: $active) as $stage) {
			if ((string)($stage['id'] ?? '') === (string)($active['id'] ?? '')) {
				$stage = $active;
			}

			$group[] = $stage;
		}

		$result = $this->resultOf(stage: $active, group: $group);
		if ($result === null) {
			return null;
		}

		$granted = ($result['outcome'] === ApprovalThresholdCalculator::OUTCOME_GRANTED);
		foreach ($group as $stage) {
			$patch = ['thresholdResult' => $result];
			if ($granted === true && (string)($stage['status'] ?? '') === 'active') {
				$patch += [
					'status'            => 'skipped',
					'outcome'           => 'skipped',
					'decidedAt'         => $result['computedAt'],
					'closedByThreshold' => true,
				];
			}

			$this->store->patch(schema: 'decision-stage', data: $patch, uuid: (string)$stage['id']);
		}

		return $result;
	}//end settle()

	/**
	 * The step's outcome, computed from its stages, with the inputs it came from.
	 *
	 * Each stage is one approver. A decided stage whose outcome is a grant counts
	 * as granted, a `returned` one as refused; every other stage has not granted.
	 *
	 * @param array<string, mixed>             $stage The stage whose threshold applies.
	 * @param array<int, array<string, mixed>> $group The step's stages.
	 *
	 * @return array<string, mixed>|null The result, or null when the threshold cannot be read.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function resultOf(array $stage, array $group): ?array {
		$actions = [];
		foreach ($group as $member) {
			$actions[] = self::actionOf(stage: $member);
		}

		try {
			$computed = $this->calculator->outcome(step: $stage, actions: $actions, approverCount: count($group));
		} catch (InvalidArgumentException) {
			return null;
		}

		$value = ($stage['thresholdValue'] ?? null);
		if (is_numeric($value) === true) {
			$value = (float)$value;
		}

		return $computed + [
			'thresholdKind'  => (string)$stage['thresholdKind'],
			'thresholdValue' => $value,
			'computedAt'     => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];
	}//end resultOf()

	/**
	 * One stage read as the action the calculator counts.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return array<string, string> The action.
	 */
	private static function actionOf(array $stage): array {
		$outcome = (string)($stage['outcome'] ?? '');
		if ((string)($stage['status'] ?? '') === 'decided' && in_array($outcome, self::GRANTING_OUTCOMES, true) === true) {
			return ['action' => $outcome, 'state' => ApprovalThresholdCalculator::OUTCOME_GRANTED];
		}

		if ($outcome === 'returned') {
			return ['action' => 'refused', 'state' => ApprovalThresholdCalculator::OUTCOME_REFUSED];
		}

		return ['action' => '', 'state' => 'open'];
	}//end actionOf()
}//end class
