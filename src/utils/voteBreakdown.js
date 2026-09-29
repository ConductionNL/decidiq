/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Rows for the result of a vote per member and per faction (vot-03), built
 * from GET /api/voting-rounds/{id}/breakdown. The server reads the voter
 * from the ballot's relations and the faction from the participant; a
 * secret round comes back with totals only.
 *
 * @spec openspec/changes/voting-results-by-faction-and-member/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */

import { rankingText } from './rankedBallot.js'

/**
 * The breakdown endpoint of a round, before generateUrl().
 *
 * @param {string} roundId The voting round id
 * @return {string}
 * @spec openspec/changes/voting-results-by-faction-and-member/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
export function breakdownUrl(roundId) {
	return `/apps/decidiq/api/voting-rounds/${encodeURIComponent(roundId)}/breakdown`
}

/**
 * One row per member who voted, named, with faction and vote.
 *
 * @param {object} breakdown The breakdown answer
 * @param {object} round The voting round (for ranked options)
 * @return {Array<object>}
 * @spec openspec/changes/voting-results-by-faction-and-member/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
export function memberRows(breakdown, round) {
	if (!breakdown || breakdown.secret) return []
	return (breakdown.members || []).map((member) => {
		const row = {
			id: `${round?.id}:${member.participant}`,
			voter: member.name,
			faction: member.faction || '',
			value: member.value,
			castBy: member.castBy || '',
			castAt: member.castAt || '',
		}
		const text = rankingText(member, round)
		if (text) row.rankingText = text
		return row
	})
}

/**
 * One row per faction with its for, against and abstain counts.
 *
 * @param {object} breakdown The breakdown answer
 * @return {Array<object>}
 * @spec openspec/changes/voting-results-by-faction-and-member/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
export function factionRows(breakdown) {
	if (!breakdown || breakdown.secret) return []
	return (breakdown.factions || []).map((faction) => ({
		id: faction.faction,
		faction: faction.faction,
		for: faction.for || 0,
		against: faction.against || 0,
		abstain: faction.abstain || 0,
	}))
}
