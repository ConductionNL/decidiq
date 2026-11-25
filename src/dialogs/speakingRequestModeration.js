/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * What the approve, reject and refer dialogs of a speaking request share
 * (inspreekrecht-plenair, REQ-INS-005): running the moderation call, reading
 * the server's refusal, refreshing the overview after it, and the meeting and
 * agenda item choices of a referral.
 */

import { dispatchAction } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Run a moderation call; on success refresh the page's lists.
 *
 * @param {() => Promise<unknown>} call The moderation call.
 * @param {string} fallback The message when the server gave none.
 * @return {Promise<string>} '' on success, else the message to show.
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export async function runModeration(call, fallback) {
	try {
		await call()
	} catch (e) {
		return e?.response?.data?.message || fallback
	}
	// The shared page refresh: every list on the page reads again.
	dispatchAction({ type: 'refresh' })
	return ''
}

/**
 * A text field's value as a positive whole number, or null when it is empty
 * or not one (the server then takes its default).
 *
 * @param {string|number|null} value The field's value.
 * @return {number|null} The number.
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export function optionalPositiveInt(value) {
	if (value === null || value === undefined || String(value).trim() === '') return null
	const number = Number(value)
	return Number.isInteger(number) && number > 0 ? number : null
}

/**
 * The speaker's name a request carries, or ''.
 *
 * @param {object|null} request The speaking request row.
 * @return {string} The name.
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export function speakerNameOf(request) {
	const name = request?.topic?.speakerName
	return typeof name === 'string' ? name.trim() : ''
}

/**
 * Rows of an OpenRegister list answer.
 *
 * @param {object|Array|null} data The answer.
 * @return {Array} The rows.
 */
function rowsOf(data) {
	if (Array.isArray(data)) return data
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * The upcoming meetings of a body a request can be referred to, soonest
 * first, without the meeting it is on now. Empty when they cannot be read.
 *
 * @param {string} bodyId The governance body; empty for every body.
 * @param {string} currentMeetingId The request's meeting now.
 * @param {number} [now] Current timestamp (ms).
 * @return {Promise<Array<{id: string, label: string}>>} The choices.
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export async function meetingOptions(bodyId, currentMeetingId, now = Date.now()) {
	const params = { _limit: 100 }
	if (bodyId) params.governanceBody = bodyId
	let rows
	try {
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/objects/{register}/{schema}', { register: 'decidiq', schema: 'meeting' }),
			{ params },
		)
		rows = rowsOf(data)
	} catch {
		return []
	}
	return rows
		.filter((row) => row?.id && row.id !== currentMeetingId)
		.filter((row) => !(Date.parse(row.scheduledDate ?? '') < now))
		.sort((a, b) => String(a.scheduledDate ?? '').localeCompare(String(b.scheduledDate ?? '')))
		.map((row) => ({
			id: row.id,
			label: [row.title || row.id, row.scheduledDate ? String(row.scheduledDate).slice(0, 10) : ''].filter(Boolean).join(' · '),
		}))
}

/**
 * The agenda items of a meeting, in agenda order. Empty when they cannot be read.
 *
 * @param {string} meetingId The meeting.
 * @return {Promise<Array<{id: string, label: string}>>} The choices.
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 */
export async function agendaItemOptions(meetingId) {
	if (!meetingId) return []
	let rows
	try {
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/objects/{register}/{schema}', { register: 'decidiq', schema: 'agenda-item' }),
			{ params: { meeting: meetingId, _limit: 200 } },
		)
		rows = rowsOf(data)
	} catch {
		return []
	}
	return rows
		.filter((row) => row?.id && row.meeting === meetingId)
		.sort((a, b) => (Number(a.orderNumber) || 0) - (Number(b.orderNumber) || 0))
		.map((row) => ({ id: row.id, label: [row.orderNumber, row.title || row.id].filter((v) => v !== undefined && v !== null && v !== '').join('. ') }))
}
