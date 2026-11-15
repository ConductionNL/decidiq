// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The deterministic order in which amendments are voted on, mirroring the
 * server's comparison: votingOrder ascending (unordered last), then
 * submittedAt, then id.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */

/**
 * The voting rank of an amendment; one without a usable votingOrder ranks
 * last.
 *
 * @param {object} amendment The amendment.
 * @return {number} The rank.
 */
function votingRank(amendment) {
	const order = amendment?.votingOrder
	if (order === null || order === undefined || order === '') {
		return Number.MAX_SAFE_INTEGER
	}
	return Number.isFinite(Number(order)) ? Number(order) : Number.MAX_SAFE_INTEGER
}

/**
 * An amendment field as sort text; a missing value sorts as ''.
 *
 * @param {object} amendment The amendment.
 * @param {string} field The field.
 * @return {string} The field as text.
 */
function sortText(amendment, field) {
	return String(amendment?.[field] || '')
}

/**
 * Compare two amendments for the voting order.
 *
 * @param {object} a An amendment.
 * @param {object} b Another amendment.
 * @return {number} Negative when a is voted on first.
 * @spec openspec/specs/motion-amendment/spec.md
 */
export function compareByVotingOrder(a, b) {
	const rankDifference = votingRank(a) - votingRank(b)
	if (rankDifference !== 0) return rankDifference
	const submittedA = sortText(a, 'submittedAt')
	const submittedB = sortText(b, 'submittedAt')
	if (submittedA !== submittedB) return submittedA < submittedB ? -1 : 1
	return sortText(a, 'id') < sortText(b, 'id') ? -1 : 1
}
