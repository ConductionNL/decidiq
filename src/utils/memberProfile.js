/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Helpers for a person's profile page (bod-05, vot-18): the route, the
 * voting record endpoint, initials in place of a missing photo, memberships
 * split into current and earlier, and the rows of the voting record.
 *
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md
 */

/**
 * The route of a person's profile page.
 *
 * @param {string} personId The person id
 * @return {string}
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile
 */
export function profilePath(personId) {
	return `/people/${personId}`
}

/**
 * The voting record endpoint of a person, before generateUrl().
 *
 * @param {string} personId The person id
 * @return {string}
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
export function votingRecordUrl(personId) {
	return `/apps/decidiq/api/people/${encodeURIComponent(personId)}/voting-record`
}

/**
 * Up to two initials: the first letter of the first and of the last word.
 *
 * @param {string} name The person's name
 * @return {string}
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page
 */
export function initials(name) {
	const words = String(name || '')
		.split(/\s+/)
		.map((word) => word.replace(/[^\p{L}]/gu, ''))
		.filter((word) => word !== '')
	if (words.length === 0) return '?'
	const first = words[0][0].toUpperCase()
	if (words.length === 1) return first
	return first + words[words.length - 1][0].toUpperCase()
}

/**
 * A person's memberships, current first (newest start first), earlier apart.
 *
 * @param {Array<object>} memberships The person's memberships
 * @param {object} bodiesById The bodies by id
 * @param {Date} now The moment that decides current or earlier
 * @return {{current: Array<object>, earlier: Array<object>}}
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-002-a-membership-carries-the-members-portfolio
 */
export function membershipSections(memberships, bodiesById, now = new Date()) {
	const rows = (memberships || []).map((membership) => {
		const body = bodiesById?.[membership.governanceBody] || {}
		return {
			id: membership.id,
			bodyId: membership.governanceBody || '',
			bodyName: body.name || '',
			role: membership.label || membership.role || '',
			party: membership.party || '',
			portfolio: (membership.portfolio || []).join(', '),
			startDate: membership.startDate || '',
			endDate: membership.endDate || '',
		}
	})
	rows.sort((a, b) => String(b.startDate).localeCompare(String(a.startDate)))
	const ended = (row) => row.endDate !== '' && new Date(row.endDate) < now
	return {
		current: rows.filter((row) => !ended(row)),
		earlier: rows.filter(ended),
	}
}

/**
 * Table rows for the voting record answer, in the server's order (newest first).
 *
 * @param {object|null} answer The GET voting-record answer
 * @return {Array<object>}
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
export function recordRows(answer) {
	return (answer?.votes || []).map((vote) => ({
		id: vote.vote,
		date: vote.date || '',
		decisionId: vote.decision?.id || '',
		decision: vote.decision?.title || '',
		choice: vote.choice,
		result: vote.result || '',
		party: vote.party || '',
	}))
}

/**
 * The person queries that find a participant's person, strongest first.
 *
 * @param {object} participant The participant
 * @return {Array<object>}
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile
 */
export function participantPersonQueries(participant) {
	const queries = []
	if (participant?.nextcloudUserId) {
		queries.push({ nextcloudUserId: participant.nextcloudUserId })
	}
	if (participant?.email) queries.push({ email: participant.email })
	return queries
}
