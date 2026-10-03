// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A ranked ballot as the ordered option labels of its round, for the screens
 * that list votes (issue #1419, REQ-RPB-002): "1. Build a new clubhouse,
 * 2. Renovate the clubhouse, 3. Rent a hall". Returns '' for any vote that is
 * not a ranked ballot, so the caller keeps showing the value badge.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-rpb-002-screens-that-list-votes-show-a-ranked-ballot-as-its-ranking
 * @param {object} vote The vote
 * @param {object} round The vote's voting round
 * @return {string} The ranking in words, or ''
 */
export function rankingText(vote, round) {
	if (vote?.value !== 'ranked' || !Array.isArray(vote?.ranking)) {
		return ''
	}
	const labels = new Map(
		(round?.options || []).map((option) => [
			String(option.key),
			String(option.label || option.key),
		]),
	)
	return vote.ranking
		.map((key, index) => `${index + 1}. ${labels.get(String(key)) || key}`)
		.join(', ')
}

/**
 * Add `rankingText` to each ranked ballot of a round.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-rpb-002-screens-that-list-votes-show-a-ranked-ballot-as-its-ranking
 * @param {Array<object>} votes The round's votes
 * @param {object} round The round
 * @return {Array<object>} The votes, ranked ones with rankingText
 */
export function withRankingText(votes, round) {
	return votes.map((vote) => {
		const text = rankingText(vote, round)
		return text ? { ...vote, rankingText: text } : vote
	})
}
