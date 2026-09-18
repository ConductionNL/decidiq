<?php

/**
 * Approval Threshold Calculator
 *
 * A step used to need every one of its approvers. Some steps do not: a board of
 * five where three is enough, a college where a simple majority decides. This
 * computes a step's outcome from the actions recorded against it and the
 * threshold the step declares.
 *
 * THE OUTCOME IS COMPUTED, NEVER STORED AND TRUSTED
 * --------------------------------------------------
 * Which is the whole reason changing a threshold works at all. Lowering a share
 * closes a step that was open, raising it reopens a step that was closed, and
 * neither needs anybody to grant anything again, because the grants never went
 * anywhere. A stored outcome would have to be migrated, and a migration that
 * silently disagreed with the actions beneath it is the kind of wrong nobody
 * finds.
 *
 * A REFUSAL IS NOT A MISSING GRANT
 * ---------------------------------
 * A step of five with a share of 0.6 needs three grants. If three of the five
 * refuse, three grants can no longer arrive, and the step is refused now rather
 * than open until the last two people are chased for approvals that cannot
 * change the answer.
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
 * Computes a step's outcome from its actions and its threshold.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 */
final class ApprovalThresholdCalculator {
	/**
	 * Every approver has to grant. The default, and what every stored route
	 * means today.
	 *
	 * @var string
	 */
	public const KIND_ALL = 'all';

	/**
	 * A fixed number of grants is enough.
	 *
	 * @var string
	 */
	public const KIND_COUNT = 'count';

	/**
	 * A share of the approvers, between 0 and 1.
	 *
	 * @var string
	 */
	public const KIND_SHARE = 'share';

	/**
	 * The kinds a step may declare.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [self::KIND_ALL, self::KIND_COUNT, self::KIND_SHARE];

	/**
	 * The step is still waiting.
	 *
	 * @var string
	 */
	public const OUTCOME_OPEN = 'open';

	/**
	 * Enough approvers granted.
	 *
	 * @var string
	 */
	public const OUTCOME_GRANTED = 'granted';

	/**
	 * Enough approvers refused that the threshold can no longer be reached.
	 *
	 * @var string
	 */
	public const OUTCOME_REFUSED = 'refused';

	/**
	 * How many grants a step needs.
	 *
	 * @param array<string, mixed> $step The step, with its threshold.
	 * @param int $approverCount How many approvers the step has.
	 *
	 * @return int The number of grants required, at least one.
	 *
	 * @throws InvalidArgumentException When the threshold is not one the engine knows.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function required(array $step, int $approverCount): int {
		$kind = (string)($step['thresholdKind'] ?? self::KIND_ALL);

		if (in_array($kind, self::KINDS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown thresholdKind "%s"; expected one of %s.', $kind, implode(', ', self::KINDS))
			);
		}

		if ($approverCount < 1) {
			return 1;
		}

		if ($kind === self::KIND_ALL) {
			return $approverCount;
		}

		$value = ($step['thresholdValue'] ?? null);
		if (is_numeric($value) === false) {
			throw new InvalidArgumentException(
				sprintf('A "%s" threshold needs a thresholdValue; without one the step can never close.', $kind)
			);
		}

		if ($kind === self::KIND_COUNT) {
			// More than everybody is not a threshold anybody can meet, so it is
			// capped rather than left as a step that is permanently open.
			return max(1, min($approverCount, (int)$value));
		}

		$share = (float)$value;
		if ($share <= 0.0 || $share > 1.0) {
			throw new InvalidArgumentException('A share threshold is a fraction above 0 and at most 1.');
		}

		return max(1, (int)ceil(($share * $approverCount)));
	}//end required()

	/**
	 * The step's outcome, computed from the actions recorded against it.
	 *
	 * @param array<string, mixed> $step The step, with its threshold.
	 * @param array<int, array<string, mixed>> $actions The actions recorded against it.
	 * @param int|null $approverCount How many approvers the step has; the action count when null.
	 *
	 * @return array{outcome: string, granted: int, refused: int, required: int, approvers: int} The outcome and its inputs.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function outcome(array $step, array $actions, ?int $approverCount = null): array {
		$tally = $this->tally(actions: $actions);
		$granted = $tally['granted'];
		$refused = $tally['refused'];

		$approvers = ($approverCount ?? max(count($actions), 1));
		$required = $this->required(step: $step, approverCount: $approvers);

		return [
			'outcome' => $this->verdict(granted: $granted, refused: $refused, approvers: $approvers, required: $required),
			'granted' => $granted,
			'refused' => $refused,
			'required' => $required,
			'approvers' => $approvers,
		];
	}//end outcome()

	/**
	 * Count the grants and the refusals that still stand.
	 *
	 * @param array<int, mixed> $actions The actions recorded against the step.
	 *
	 * @return array{granted: int, refused: int} The counts.
	 */
	private function tally(array $actions): array {
		$granted = 0;
		$refused = 0;

		foreach ($actions as $action) {
			if (is_array($action) === false) {
				continue;
			}

			// A withdrawn grant does not count. That is the point of withdrawing
			// it: the step has to be able to reopen.
			$state = (string)($action['state'] ?? '');
			if ($state === 'withdrawn') {
				continue;
			}

			if ($state === self::OUTCOME_GRANTED || in_array((string)($action['action'] ?? ''), ['approved', 'endorsed'], true) === true) {
				$granted++;
				continue;
			}

			if ($state === self::OUTCOME_REFUSED || (string)($action['action'] ?? '') === 'refused') {
				$refused++;
			}
		}

		return ['granted' => $granted, 'refused' => $refused];
	}//end tally()

	/**
	 * The verdict the counts add up to.
	 *
	 * @param int $granted How many grants stand.
	 * @param int $refused How many refusals stand.
	 * @param int $approvers How many people can answer.
	 * @param int $required How many grants the step needs.
	 *
	 * @return string The outcome.
	 */
	private function verdict(int $granted, int $refused, int $approvers, int $required): string {
		if ($granted >= $required) {
			return self::OUTCOME_GRANTED;
		}

		if (($approvers - $refused) < $required) {
			// Enough people have refused that the required grants can no longer
			// arrive. Deciding now beats chasing approvals that cannot change
			// the answer.
			return self::OUTCOME_REFUSED;
		}

		return self::OUTCOME_OPEN;
	}//end verdict()

	/**
	 * The action that records an administrator changing a threshold.
	 *
	 * A step whose outcome changed with no action to point at is a step nobody
	 * can explain, which is why REQ-DWP-002 asks for this rather than only for
	 * the recomputation.
	 *
	 * @param array<string, mixed> $step The step as it now stands.
	 * @param float|null $before The threshold value before the change.
	 * @param float|null $after The threshold value after it.
	 * @param string $actor Who changed it.
	 * @param string $recordedAt When, as an ISO-8601 instant; now when empty.
	 *
	 * @return array<string, mixed> The action to append.
	 *
	 * @throws InvalidArgumentException When nobody is named.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function recomputationAction(
		array $step,
		?float $before,
		?float $after,
		string $actor,
		string $recordedAt = '',
	): array {
		if (trim($actor) === '') {
			throw new InvalidArgumentException('A threshold change has to name the actor who made it.');
		}

		$stampedAt = $recordedAt;
		if ($stampedAt === '') {
			$stampedAt = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		}

		return [
			'step' => (int)($step['order'] ?? 0),
			'actor' => $actor,
			'action' => 'threshold-changed',
			'state' => 'granted',
			'thresholdBefore' => $before,
			'thresholdAfter' => $after,
			'recordedAt' => $stampedAt,
		];
	}//end recomputationAction()
}//end class
