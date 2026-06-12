/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure helpers for the configurable voting rules (voting-system spec):
 * threshold / abstention-handling / tie-break option lists and the
 * calculation-base formula mirrored from VotingService::computeResult().
 *
 * @spec openspec/specs/voting-system/spec.md
 */

/** Majority thresholds — mirrors VotingService::VOTE_THRESHOLDS. */
export const VOTE_THRESHOLDS = [
	'simple-majority',
	'qualified-majority-two-thirds',
	'qualified-majority-three-quarters',
	'unanimous',
]

/** Abstention-handling modes — mirrors VotingService::ABSTENTION_MODES. */
export const ABSTENTION_MODES = ['exclude', 'count']

/** Tie-break rules — mirrors VotingService::TIE_BREAK_RULES. */
export const TIE_BREAK_RULES = ['rejected', 'chair-decides', 'revote']

/**
 * Compute the calculation base a threshold is evaluated against.
 *
 * base = for + against, plus abstentions when abstentionHandling === 'count'
 * (counting abstentions makes every threshold harder).
 *
 * @spec openspec/specs/voting-system/spec.md
 * @param {object} round Round-like object with vote counts + abstentionHandling
 * @param {number} [round.votesFor] Weighted for-votes
 * @param {number} [round.votesAgainst] Weighted against-votes
 * @param {number} [round.votesAbstain] Weighted abstentions
 * @param {string} [round.abstentionHandling] 'exclude' (default) or 'count'
 * @return {number} The computed base
 */
export function computeBase(round) {
	const votesFor = round?.votesFor || 0
	const votesAgainst = round?.votesAgainst || 0
	const votesAbstain = round?.votesAbstain || 0
	const base = votesFor + votesAgainst
	if ((round?.abstentionHandling || 'exclude') === 'count') {
		return base + votesAbstain
	}
	return base
}

/**
 * Resolve the effective rules of a round, applying the documented defaults
 * for rounds created before voting-rules-v1 (simple majority, abstentions
 * excluded, tie = motion fails).
 *
 * @spec openspec/specs/voting-system/spec.md
 * @param {object} round Round-like object
 * @return {{voteThreshold: string, abstentionHandling: string, tieBreakRule: string}} Effective rules
 */
export function effectiveRules(round) {
	const voteThreshold = VOTE_THRESHOLDS.includes(round?.voteThreshold)
		? round.voteThreshold
		: 'simple-majority'
	const abstentionHandling = ABSTENTION_MODES.includes(round?.abstentionHandling)
		? round.abstentionHandling
		: 'exclude'
	const tieBreakRule = TIE_BREAK_RULES.includes(round?.tieBreakRule)
		? round.tieBreakRule
		: 'rejected'
	return { voteThreshold, abstentionHandling, tieBreakRule }
}

/**
 * Human-readable label maps for the rule enums. Keys are passed through the
 * caller-supplied translate function so the component owns the i18n domain.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @param {Function} t Translate function (text) => translated text (app domain pre-bound)
 * @return {{voteThreshold: object, abstentionHandling: object, tieBreakRule: object}} Label maps keyed by enum value
 */
export function ruleLabels(t) {
	return {
		voteThreshold: {
			'simple-majority': t('Simple majority (50%+1)'),
			'qualified-majority-two-thirds': t('Qualified majority (2/3)'),
			'qualified-majority-three-quarters': t('Qualified majority (3/4)'),
			unanimous: t('Unanimous'),
		},
		abstentionHandling: {
			exclude: t('Abstentions excluded from base'),
			count: t('Abstentions count toward base'),
		},
		tieBreakRule: {
			rejected: t('Tie: motion fails'),
			'chair-decides': t('Tie: chair decides'),
			revote: t('Tie: revote (once)'),
		},
	}
}
