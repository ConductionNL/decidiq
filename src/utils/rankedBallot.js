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

/**
 * The options of a ranked round as the server stores them: a key made from
 * each label (unique within the round), the label, and the Person picked for
 * the option when there is one. Rows without a label are left out.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
 * @param {Array<{label?: string, person?: string}>} rows The editor's rows
 * @return {Array<{key: string, label: string, person?: string}>} The options
 */
export function rankedOptionsFrom(rows) {
	const used = new Set()
	return (Array.isArray(rows) ? rows : [])
		.map((row) => ({
			label: String(row?.label || '').trim(),
			person: String(row?.person || '').trim(),
		}))
		.filter((row) => row.label !== '')
		.map((row, index) => {
			const base =
				row.label
					.toLowerCase()
					.normalize('NFKD')
					.replace(/[^a-z0-9]+/g, '-')
					.replace(/^-+|-+$/g, '') || `option-${index + 1}`
			let key = base
			let suffix = 2
			while (used.has(key)) {
				key = `${base}-${suffix}`
				suffix++
			}
			used.add(key)
			const option = { key, label: row.label }
			if (row.person !== '') option.person = row.person
			return option
		})
}

/**
 * Put a picked Person on an editor row. The row's label becomes the person's
 * name unless the chair already typed one; clearing the pick clears only the
 * reference, never a label the chair wrote.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
 * @param {{label?: string, person?: string}} row The editor row
 * @param {{id?: string, label?: string}|null} pick The picked person, or null
 * @return {{label: string, person: string}} The updated row
 */
export function pickPerson(row, pick) {
	const label = String(row?.label || '')
	if (!pick || !pick.id) {
		return { ...row, label, person: '' }
	}
	return {
		...row,
		person: String(pick.id),
		label: label.trim() !== '' ? label : String(pick.label || ''),
	}
}
