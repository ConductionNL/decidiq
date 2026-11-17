/**
 * Speaking request ACTION API client (inspreekrecht-plenair).
 *
 * Thin wrappers over decidiq's /api/speaking-requests/* and
 * /api/meetings/{id}/speaking-* endpoints. The server enforces the policy,
 * the deadline and who may moderate; these calls only carry the input and
 * return the server's answer. Plain object reads go through the OpenRegister
 * object API (ADR-022).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { toQueueEntries } from '../utils/speakingRequests.js'

const requests = '/apps/decidiq/api/speaking-requests'
const meetings = '/apps/decidiq/api/meetings'

/**
 * Approve a pending request, optionally with its minutes and order.
 *
 * @param {string} requestId The speaking request UUID.
 * @param {{allottedMinutes?: number|null, position?: number|null}} [values] Minutes and order; absent ones default on the server.
 * @return {Promise<object>} The approved request.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export async function approveSpeakingRequest(requestId, values = {}) {
	const body = {}
	if (Number.isFinite(values.allottedMinutes)) body.allottedMinutes = values.allottedMinutes
	if (Number.isFinite(values.position)) body.position = values.position
	const { data } = await axios.post(generateUrl(`${requests}/${encodeURIComponent(requestId)}/approve`), body)
	return data
}

/**
 * Reject a pending request with a reason.
 *
 * @param {string} requestId The speaking request UUID.
 * @param {string} rejectionReason Why it is rejected (required by the server).
 * @return {Promise<object>} The rejected request.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export async function rejectSpeakingRequest(requestId, rejectionReason) {
	const { data } = await axios.post(
		generateUrl(`${requests}/${encodeURIComponent(requestId)}/reject`),
		{ rejectionReason },
	)
	return data
}

/**
 * Refer a pending request to another meeting and, optionally, agenda item.
 *
 * @param {string} requestId The speaking request UUID.
 * @param {string} meeting The meeting it moves to.
 * @param {string} [agendaItem] The agenda item it moves to; empty for the meeting as a whole.
 * @return {Promise<object>} The referred request.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export async function referSpeakingRequest(requestId, meeting, agendaItem = '') {
	const body = { meeting }
	if (agendaItem) body.agendaItem = agendaItem
	const { data } = await axios.post(generateUrl(`${requests}/${encodeURIComponent(requestId)}/refer`), body)
	return data
}

/**
 * Record that an approved speaker spoke or did not show up.
 *
 * @param {string} requestId The speaking request UUID.
 * @param {'spoke'|'no-show'} outcome The outcome.
 * @return {Promise<object>} The updated request.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
 */
export async function markSpeakingOutcome(requestId, outcome) {
	const { data } = await axios.post(
		generateUrl(`${requests}/${encodeURIComponent(requestId)}/outcome`),
		{ outcome },
	)
	return data
}

/**
 * The internal speaker view of a meeting: per place (agenda item id, or
 * `meeting` for the meeting block) the approved slots and pending requests.
 *
 * @param {string} meetingId The meeting UUID.
 * @return {Promise<object>} `{ [place]: { slots: [], pending: [] } }`.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
 */
export async function fetchSpeakingSlots(meetingId) {
	const { data } = await axios.get(generateUrl(`${meetings}/${encodeURIComponent(meetingId)}/speaking-slots`))
	return data
}

/**
 * The approved speakers of one agenda item (or the meeting block), in order,
 * for the chair's speaker queue.
 *
 * @param {string} meetingId The meeting UUID.
 * @param {string|null} agendaItemId The current agenda item; empty for the meeting block.
 * @return {Promise<Array>} Queue rows `{ requestId, kind, name, minutes, position }`.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
 */
export async function fetchSpeakingQueue(meetingId, agendaItemId) {
	const { data } = await axios.get(
		generateUrl(`${meetings}/${encodeURIComponent(meetingId)}/speaking-queue`),
		{ params: { agendaItem: agendaItemId || '' } },
	)
	return Array.isArray(data) ? data : []
}

/**
 * Rows of an OpenRegister list answer (`{ results: [] }` or a bare array).
 *
 * @param {object|Array|null} data The answer.
 * @return {Array} The rows.
 */
function rowsOf(data) {
	if (Array.isArray(data)) return data
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * One cache per page load: the overview asks once per distinct meeting and
 * per distinct body, never once per row (REQ-INS-010 performance).
 */
const meetingStarts = new Map()
const bodyDeadlines = new Map()

/**
 * Forget the cached meeting starts and body deadlines (tests, and a page
 * that wants fresh values).
 *
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications
 */
export function clearDeadlineCache() {
	meetingStarts.clear()
	bodyDeadlines.clear()
}

/**
 * The scheduled start of a meeting, or null when it cannot be read.
 *
 * @param {string} meetingId The meeting UUID.
 * @return {Promise<string|null>} The meeting's scheduledDate.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications
 */
export function meetingStartOf(meetingId) {
	if (!meetingStarts.has(meetingId)) {
		meetingStarts.set(meetingId, axios.get(
			generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', { register: 'decidiq', schema: 'meeting', id: meetingId }),
		).then(({ data }) => data?.scheduledDate ?? null).catch(() => null))
	}
	return meetingStarts.get(meetingId)
}

/**
 * The registration deadline (hours before the start) of a body's speaking
 * policy, or null when the body has no policy or it cannot be read.
 *
 * @param {string} bodyId The governance body UUID.
 * @return {Promise<number|null>} The policy's registrationDeadlineHours.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications
 */
export function deadlineHoursOf(bodyId) {
	if (!bodyDeadlines.has(bodyId)) {
		bodyDeadlines.set(bodyId, axios.get(
			generateUrl('/apps/openregister/api/objects/{register}/{schema}', { register: 'decidiq', schema: 'speaking-policy' }),
			{ params: { governanceBody: bodyId, _limit: 1 } },
		).then(({ data }) => {
			const policy = rowsOf(data).find((row) => row?.governanceBody === bodyId)
			const hours = Number(policy?.registrationDeadlineHours)
			return policy && Number.isFinite(hours) ? hours : null
		}).catch(() => null))
	}
	return bodyDeadlines.get(bodyId)
}

/**
 * The preload for the chair's speaker queue when an agenda item becomes
 * current: `{ itemId, entries }`, or null when the meeting is not opened, no
 * item is current, or the speakers cannot be read (the queue then works as
 * it always did).
 *
 * @param {object|null} meeting The meeting (needs `id` and `lifecycle`).
 * @param {string|null} itemId The current agenda item.
 * @param {string} fallbackName The name of a speaker the request did not name.
 * @param {number} now Current timestamp (ms).
 * @return {Promise<{itemId: string, entries: Array}|null>} The preload.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
 */
export async function queuePreloadFor(meeting, itemId, fallbackName, now) {
	if (!meeting?.id || meeting.lifecycle !== 'opened' || !itemId) return null
	try {
		const rows = await fetchSpeakingQueue(meeting.id, itemId)
		return { itemId, entries: toQueueEntries(rows, now, fallbackName) }
	} catch {
		return null
	}
}
