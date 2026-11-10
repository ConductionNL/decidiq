<?php

/**
 * Decidiq Voting Result Calculator
 *
 * The rule-aware result computation for a set of vote counts: threshold,
 * abstention handling and tie-break rules, in integer math only. Pure — it
 * touches no storage and no session.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Pure calculation of a voting round's outcome.
 *
 * Formula (F = for, A = against, B = abstain, all weighted; see the
 * voting-system spec delta for the legal worked examples):
 *
 * - base = F + A ('exclude', default) or F + A + B ('count' — abstentions
 *   make every threshold harder).
 * - T == 0 -> 'invalid'.
 * - Tie (simple-majority only, F == A and F > 0) -> tieBreakRule applies:
 *   'rejected' -> 'rejected' (motion fails, status quo); 'chair-decides' ->
 *   'tied' until the round carries a chairCastingVote; 'revote' -> 'tied'.
 * - base == 0 -> 'rejected' (nothing can carry; guards unanimous vacuity).
 * - simple-majority: adopted iff 2F > base (strict "50%+1").
 * - qualified-majority-two-thirds: adopted iff 3F >= 2*base.
 * - qualified-majority-three-quarters: adopted iff 4F >= 3*base.
 * - unanimous: adopted iff F == base.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingResultCalculator {
	/**
	 * The accepted values of each rule a round stores.
	 *
	 * @var array<string, string[]>
	 */
	private const RULE_VALUES = [
		'voteThreshold' => VotingService::VOTE_THRESHOLDS,
		'abstentionHandling' => VotingService::ABSTENTION_MODES,
		'tieBreakRule' => VotingService::TIE_BREAK_RULES,
	];

	/**
	 * The strictest sane default of each rule, for an unknown stored value.
	 *
	 * @var array<string, string>
	 */
	private const RULE_FALLBACKS = [
		'voteThreshold' => 'simple-majority',
		'abstentionHandling' => 'exclude',
		'tieBreakRule' => 'rejected',
	];

	/**
	 * Compute the rule-aware voting result for a set of counts.
	 *
	 * @param int $for Weighted for-votes
	 * @param int $against Weighted against-votes
	 * @param int $abstain Weighted abstentions
	 * @param array<string,mixed> $round The voting round (rules + chairCastingVote are read from it)
	 *
	 * @return array{result: string, base: int, voteThreshold: string, abstentionHandling: string, tieBreakRule: string}
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function compute(int $for, int $against, int $abstain, array $round): array {
		$rules = $this->rulesOf(round: $round);
		$tally = new VoteTally(votesFor: $for, votesAgainst: $against, abstentions: $abstain);

		$meta = [
			'base' => $tally->base(abstentionHandling: $rules->abstentionHandling),
			'voteThreshold' => $rules->threshold,
			'abstentionHandling' => $rules->abstentionHandling,
			'tieBreakRule' => $rules->tieBreakRule,
		];

		return (['result' => $this->resultOf(tally: $tally, rules: $rules)] + $meta);
	}//end compute()

	/**
	 * The effective rules of a round.
	 *
	 * Fail closed: unknown stored values fall back to the strictest sane default.
	 *
	 * @param array<string,mixed> $round The voting round
	 *
	 * @return EffectiveVoteRules The rules
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function rulesOf(array $round): EffectiveVoteRules {
		return new EffectiveVoteRules(
			threshold: $this->rule(round: $round, name: 'voteThreshold'),
			abstentionHandling: $this->rule(round: $round, name: 'abstentionHandling'),
			tieBreakRule: $this->rule(round: $round, name: 'tieBreakRule'),
			chairCastingVote: ($round['chairCastingVote'] ?? null),
		);
	}//end rulesOf()

	/**
	 * A stored rule value, or the strictest sane default when unknown.
	 *
	 * @param array<string,mixed> $round The voting round
	 * @param string $name The rule: voteThreshold, abstentionHandling or tieBreakRule
	 *
	 * @return string The effective rule value
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function rule(array $round, string $name): string {
		$value = ($round[$name] ?? null);
		if (in_array($value, self::RULE_VALUES[$name], true) === true) {
			return (string)$value;
		}

		return self::RULE_FALLBACKS[$name];
	}//end rule()

	/**
	 * The result of a tally under the effective rules.
	 *
	 * @param VoteTally $tally The weighted counts
	 * @param EffectiveVoteRules $rules The effective rules
	 *
	 * @return string invalid, tied, adopted or rejected
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function resultOf(VoteTally $tally, EffectiveVoteRules $rules): string {
		if ($tally->total() === 0) {
			return 'invalid';
		}

		$tied = $this->tieOutcome(tally: $tally, rules: $rules);
		if ($tied !== null) {
			return $tied;
		}

		if ($this->meetsThreshold(tally: $tally, rules: $rules) === true) {
			return 'adopted';
		}

		return 'rejected';
	}//end resultOf()

	/**
	 * The outcome of a classic tie deadlock, or null when the counts are not a
	 * tie under the effective rules.
	 *
	 * A tie is only meaningful under simple majority: a vote that merely misses
	 * half of a counted base (F != A) is plain 'rejected'.
	 *
	 * @param VoteTally $tally The weighted counts
	 * @param EffectiveVoteRules $rules The effective rules
	 *
	 * @return string|null The tie outcome, or null when this is not a tie
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function tieOutcome(VoteTally $tally, EffectiveVoteRules $rules): ?string {
		if ($rules->threshold !== 'simple-majority' || $tally->votesFor !== $tally->votesAgainst || $tally->votesFor <= 0) {
			return null;
		}

		return $this->tieBreak(rules: $rules);
	}//end tieOutcome()

	/**
	 * What a simple-majority tie comes to under the tie-break rule.
	 *
	 * @param EffectiveVoteRules $rules The effective rules
	 *
	 * @return string The tie outcome
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function tieBreak(EffectiveVoteRules $rules): string {
		if ($rules->tieBreakRule === 'revote') {
			return 'tied';
		}

		if ($rules->tieBreakRule !== 'chair-decides') {
			// Default 'rejected': a tied motion fails (legal status quo).
			return 'rejected';
		}

		return match ($rules->chairCastingVote) {
			'for' => 'adopted',
			'against' => 'rejected',
			default => 'tied',
		};
	}//end tieBreak()

	/**
	 * Whether the for-votes carry the round under the effective threshold.
	 *
	 * Nothing carries on an empty base (it guards unanimous vacuity). Integer
	 * math throughout — no float threshold comparisons.
	 *
	 * @param VoteTally $tally The weighted counts
	 * @param EffectiveVoteRules $rules The effective rules
	 *
	 * @return bool True when the threshold is met
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function meetsThreshold(VoteTally $tally, EffectiveVoteRules $rules): bool {
		$for = $tally->votesFor;
		$base = $tally->base(abstentionHandling: $rules->abstentionHandling);
		if ($base === 0) {
			return false;
		}

		return match ($rules->threshold) {
			'qualified-majority-two-thirds' => (3 * $for) >= (2 * $base),
			'qualified-majority-three-quarters' => (4 * $for) >= (3 * $base),
			'unanimous' => $for === $base,
			default => (2 * $for) > $base,
		};

	}//end meetsThreshold()
}//end class
