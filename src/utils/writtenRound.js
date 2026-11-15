/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A decision put to a body in writing (voting-written-resolution): who may
 * open it, what each member answered, and the requests behind the widget.
 * The server decides every refusal; these helpers only shape what the page
 * shows.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The answers a member can give; `objection` is "discuss this in a meeting". */
export const WRITTEN_ANSWERS = ['for', 'against', 'abstain', 'objection']

/** The membership roles that make up a body's signatory scope. */
export const PRESIDING_ROLES = ['chair', 'vice-chair', 'secretary']

/** The lifecycle states a decision can be put in writing from. */
export const OPEN_FROM = ['proposed', 'deliberating']

/**
 * The id a reference holds: a uuid string or an expanded object.
 *
 * @param {string|object|null|undefined} value The stored reference.
 * @return {string} The id, or ''.
 */
export function refId(value) {
	if (value && typeof value === 'object') {
		return String(value.id || value.uuid || '')
	}
	return value === null || value === undefined ? '' : String(value)
}

/**
 * Whether a membership is active on a day.
 *
 * @param {object} membership The membership.
 * @param {string} today The day, YYYY-MM-DD.
 * @return {boolean} True when started and not ended.
 */
export function isActiveOn(membership, today) {
	const start = String(membership?.startDate || '').slice(0, 10)
	const end = String(membership?.endDate || '').slice(0, 10)
	return (start === '' || start <= today) && (end === '' || end >= today)
}

/**
 * The bodies a person presides over today: the ids of the bodies of their
 * active chair, vice-chair and secretary memberships.
 *
 * @param {Array<object>} memberships The person's memberships.
 * @param {string} today The day, YYYY-MM-DD.
 * @return {Array<string>} The body ids, each once.
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */
export function presidingBodyIds(memberships, today) {
	const ids = (memberships || [])
		.filter((m) => PRESIDING_ROLES.includes(m?.role) && isActiveOn(m, today))
		.map((m) => refId(m.governanceBody))
		.filter((id) => id !== '')
	return [...new Set(ids)]
}

/**
 * Whether a decision can be put in writing: no meeting, proposed or deliberating.
 *
 * @param {object|null} decision The decision.
 * @return {boolean} True when it can.
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */
export function canPutInWriting(decision) {
	return Boolean(decision) && refId(decision.meeting) === '' && OPEN_FROM.includes(decision.lifecycle)
}

/**
 * The latest written round of a list, by its opening time.
 *
 * @param {Array<object>} rounds The rounds.
 * @return {object|null} The round, or null.
 */
export function latestWrittenRound(rounds) {
	const written = (rounds || []).filter((r) => r?.procedure === 'written')
	written.sort((a, b) => String(b.openedAt || '').localeCompare(String(a.openedAt || '')))
	return written[0] || null
}

/**
 * Whether a round is still open.
 *
 * @param {object|null} round The round.
 * @return {boolean} True while it has no close time.
 */
export function isOpen(round) {
	return Boolean(round) && !round.closedAt
}

/**
 * Whether an open round's deadline has passed: it waits for the deadline close.
 *
 * @param {object|null} round The round.
 * @param {Date} now Now.
 * @return {boolean} True when open and past its deadline.
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure
 */
export function waitsToClose(round, now) {
	if (!isOpen(round) || !round.votingDeadline) return false
	return new Date(round.votingDeadline).getTime() <= now.getTime()
}

/**
 * Each entitled member with their answer and its time, in electorate order.
 * A member who did not answer has `value` null.
 *
 * @param {object} round The round with its electorate.
 * @param {Array<object>} votes The round's votes.
 * @param {Record<string, string>} names Person names by person id.
 * @return {Array<{membership: string, userId: string, name: string, value: string|null, castAt: string|null}>} The rows.
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure
 */
export function recordRows(round, votes, names) {
	const byMembership = {}
	for (const vote of votes || []) {
		const membership = refId(vote?.membership)
		if (membership === '') continue
		const earlier = byMembership[membership]
		if (!earlier || String(vote.castAt || '') >= String(earlier.castAt || '')) {
			byMembership[membership] = vote
		}
	}
	return (round?.electorate || []).map((member) => {
		const vote = byMembership[member.membership]
		return {
			membership: member.membership,
			userId: member.userId,
			name: (names || {})[member.person] || member.userId || member.membership,
			value: vote ? vote.value : null,
			castAt: vote ? vote.castAt || null : null,
		}
	})
}

/**
 * How many of the electorate answered.
 *
 * @param {Array<{value: string|null}>} rows The record rows.
 * @return {{answered: number, total: number}} The progress.
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
 */
export function progressOf(rows) {
	return {
		answered: (rows || []).filter((row) => row.value !== null && row.value !== undefined).length,
		total: (rows || []).length,
	}
}

/**
 * Put a decision to a body in writing.
 *
 * @param {object} request decisionId, governanceBodyId, votingDeadline, writtenRule, voteThreshold.
 * @return {Promise<object>} The stored round.
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */
export async function openWrittenRound(request) {
	const { data } = await axios.post(generateUrl('/apps/decidiq/api/voting-rounds'), {
		...request,
		procedure: 'written',
	})
	return data
}

/**
 * Answer a written round as the signed-in member.
 *
 * @param {string} roundId The round.
 * @param {string} value One of WRITTEN_ANSWERS.
 * @return {Promise<{vote: object, round: object}>} The stored vote and the round after it.
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
 */
export async function answerWrittenRound(roundId, value) {
	const { data } = await axios.post(
		generateUrl('/apps/decidiq/api/voting-rounds/{id}/cast', { id: roundId }),
		{ value },
	)
	return data
}

/**
 * The server's message of a refused request, or the fallback.
 *
 * @param {Error|object} error The axios error.
 * @param {string} fallback The fallback text.
 * @return {string} The message.
 */
export function refusalOf(error, fallback) {
	return error?.response?.data?.message || error?.message || fallback
}
