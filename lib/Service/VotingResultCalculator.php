<?php
/**
 * Decidesk Voting Result Calculator
 *
 * The rule-aware result computation for a set of vote counts: threshold,
 * abstention handling and tie-break rules, in integer math only. Pure — it
 * touches no storage and no session.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

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
class VotingResultCalculator
{
    /**
     * Compute the rule-aware voting result for a set of counts.
     *
     * @param int                 $for     Weighted for-votes
     * @param int                 $against Weighted against-votes
     * @param int                 $abstain Weighted abstentions
     * @param array<string,mixed> $round   The voting round (rules + chairCastingVote are read from it)
     *
     * @return array{result: string, base: int, voteThreshold: string, abstentionHandling: string, tieBreakRule: string}
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function compute(int $for, int $against, int $abstain, array $round): array
    {
        // Fail closed: unknown stored values fall back to the strictest sane default.
        $threshold = $this->rule(
            value: ($round['voteThreshold'] ?? null),
            allowed: VotingService::VOTE_THRESHOLDS,
            fallback: 'simple-majority'
        );
        $abstMode  = $this->rule(
            value: ($round['abstentionHandling'] ?? null),
            allowed: VotingService::ABSTENTION_MODES,
            fallback: 'exclude'
        );
        $tieRule   = $this->rule(
            value: ($round['tieBreakRule'] ?? null),
            allowed: VotingService::TIE_BREAK_RULES,
            fallback: 'rejected'
        );

        $total = ($for + $against + $abstain);
        $base  = ($for + $against);
        if ($abstMode === 'count') {
            $base = $total;
        }

        $meta = [
            'base'               => $base,
            'voteThreshold'      => $threshold,
            'abstentionHandling' => $abstMode,
            'tieBreakRule'       => $tieRule,
        ];

        if ($total === 0) {
            return (['result' => 'invalid'] + $meta);
        }

        $tied = $this->tieOutcome(
            for: $for,
            against: $against,
            threshold: $threshold,
            tieRule: $tieRule,
            round: $round
        );
        if ($tied !== null) {
            return (['result' => $tied] + $meta);
        }

        if ($base === 0) {
            return (['result' => 'rejected'] + $meta);
        }

        if ($this->meetsThreshold(threshold: $threshold, for: $for, base: $base) === true) {
            return (['result' => 'adopted'] + $meta);
        }

        return (['result' => 'rejected'] + $meta);

    }//end compute()

    /**
     * A stored rule value, or the strictest sane default when unknown.
     *
     * @param mixed    $value    The stored rule value
     * @param string[] $allowed  The accepted values
     * @param string   $fallback The default when the stored value is unknown
     *
     * @return string The effective rule value
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function rule(mixed $value, array $allowed, string $fallback): string
    {
        if (in_array($value, $allowed, true) === true) {
            return (string) $value;
        }

        return $fallback;

    }//end rule()

    /**
     * The outcome of a classic tie deadlock, or null when the counts are not a
     * tie under the effective rules.
     *
     * A tie is only meaningful under simple majority: a vote that merely misses
     * half of a counted base (F != A) is plain 'rejected'.
     *
     * @param int                 $for       Weighted for-votes
     * @param int                 $against   Weighted against-votes
     * @param string              $threshold The effective majority threshold
     * @param string              $tieRule   The effective tie-break rule
     * @param array<string,mixed> $round     The voting round (chairCastingVote is read from it)
     *
     * @return string|null The tie outcome, or null when this is not a tie
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function tieOutcome(int $for, int $against, string $threshold, string $tieRule, array $round): ?string
    {
        if ($threshold !== 'simple-majority' || $for !== $against || $for <= 0) {
            return null;
        }

        if ($tieRule === 'revote') {
            return 'tied';
        }

        if ($tieRule !== 'chair-decides') {
            // Default 'rejected': a tied motion fails (legal status quo).
            return 'rejected';
        }

        $casting = ($round['chairCastingVote'] ?? null);
        if ($casting === 'for') {
            return 'adopted';
        }

        if ($casting === 'against') {
            return 'rejected';
        }

        return 'tied';

    }//end tieOutcome()

    /**
     * Whether the for-votes carry the round under the effective threshold.
     *
     * Integer math throughout — no float threshold comparisons.
     *
     * @param string $threshold The effective majority threshold
     * @param int    $for       Weighted for-votes
     * @param int    $base      The calculation base
     *
     * @return bool True when the threshold is met
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function meetsThreshold(string $threshold, int $for, int $base): bool
    {
        return match ($threshold) {
            'qualified-majority-two-thirds'     => (3 * $for) >= (2 * $base),
            'qualified-majority-three-quarters' => (4 * $for) >= (3 * $base),
            'unanimous'                         => $for === $base,
            default                             => (2 * $for) > $base,
        };

    }//end meetsThreshold()
}//end class
