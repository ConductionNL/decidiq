// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Set up an ad hoc meeting on one page (meeting-ad-hoc-with-guests Task 3,
 * pla-20, board DcAdhocOverleg "Nieuw overleg"): the meeting without a body,
 * its agenda points, colleagues, papers, an optional Talk conversation and
 * the guests from outside. The organiser writes every object as themselves,
 * so the register's owner rule keeps the meeting theirs to edit.
 *
 * Guests are invited last: their invitation mail lists the agenda and links
 * to the papers, so both must exist first. Guests are only invited to a
 * meeting without a governing body (GuestInvitationService refuses others).
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */

import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { roomToken, talkLinksPath } from './videoCall.js'

/**
 * The fields the page still needs before Overleg aanmaken.
 *
 * Title and date are always required. A place is required unless the
 * meeting is held in Talk. An end time must lie after the start.
 *
 * @param {object} form The page form
 * @return {Array<string>} The keys of the missing or wrong fields
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export function missingFields(form) {
	const missing = []
	if (!String(form.title || '').trim()) missing.push('title')
	if (!form.start) missing.push('start')
	if (!String(form.location || '').trim() && !form.talk) missing.push('location')
	if (form.start && form.endTime) {
		const end = endDateOf(form.start, form.endTime)
		if (!end || end <= new Date(form.start)) missing.push('endTime')
	}
	return missing
}

/**
 * The end of the meeting: the start's day at the end time.
 *
 * @param {string} start The start as a datetime-local value
 * @param {string} endTime The end time as HH:MM
 * @return {Date|null} The end, or null without an end time
 */
function endDateOf(start, endTime) {
	if (!start || !endTime) return null
	const end = new Date(`${String(start).slice(0, 10)}T${endTime}`)
	return Number.isNaN(end.getTime()) ? null : end
}

/**
 * The meeting object: no governing body, a draft, not public.
 *
 * @param {object} form The page form
 * @return {object} The meeting payload
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export function meetingPayload(form) {
	const location = String(form.location || '').trim()
	let meetingMode = 'in-person'
	if (form.talk) {
		meetingMode = location ? 'hybrid' : 'digital'
	}
	const payload = {
		title: String(form.title || '').trim(),
		meetingType: 'regular',
		scheduledDate: new Date(form.start).toISOString(),
		meetingMode,
		lifecycle: 'draft',
		isPublic: false,
	}
	if (location) payload.location = location
	const end = endDateOf(form.start, form.endTime)
	if (end) payload.endDate = end.toISOString()
	return payload
}

/**
 * The agenda items, numbered in the order the organiser wrote them.
 *
 * @param {string} meetingId The meeting
 * @param {Array<string>} points The agenda point titles
 * @return {Array<object>} The agenda-item payloads
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export function agendaPayloads(meetingId, points) {
	return (points || [])
		.map((title) => String(title || '').trim())
		.filter((title) => title !== '')
		.map((title, index) => ({
			title,
			itemType: 'discussion',
			orderNumber: index + 1,
			meeting: meetingId,
		}))
}

/**
 * A colleague joins the meeting through a meeting-attendance record, the
 * same record a guest gets from GuestInvitationService.
 *
 * @param {string} meetingId The meeting
 * @param {Array<object>} colleagues The participant records picked
 * @return {Array<object>} The meeting-attendance payloads
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export function attendancePayloads(meetingId, colleagues) {
	return (colleagues || []).map((colleague) => ({
		meeting: meetingId,
		participant: String(colleague.id || colleague.uuid),
	}))
}

/**
 * Run one step after the meeting exists; a failure is recorded, not thrown.
 *
 * @param {Array<object>} failures The failures so far
 * @param {string} step The step name
 * @param {() => Promise<unknown>} run The step
 * @return {Promise<void>}
 */
async function attempt(failures, step, run) {
	try {
		await run()
	} catch (e) {
		failures.push({ step, detail: e?.message || '' })
	}
}

/**
 * Create the meeting and everything the page collected.
 *
 * Saving the meeting is the one step that throws: without it nothing else
 * can be written. Each later step is attempted, and what fails is returned
 * so the page can say what to finish on the meeting page.
 *
 * @param {object} form The page form
 * @param {object} api The calls: saveObject(slug, payload), uploadPapers(meetingId, files), createTalkRoom(meetingId, title, uids), inviteGuest(meetingId, guest)
 * @return {Promise<{meetingId: string, failures: Array<object>}>} The meeting and the failed steps
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export async function createAdhocMeeting(form, api) {
	const meeting = await api.saveObject('meeting', meetingPayload(form))
	const meetingId = String(
		meeting?.id || meeting?.uuid || meeting?.['@self']?.id || '',
	)
	if (!meetingId) {
		throw new Error('The meeting was saved without an id.')
	}
	const failures = []

	await attempt(failures, 'agenda', async () => {
		for (const item of agendaPayloads(meetingId, form.agenda)) {
			await api.saveObject('agenda-item', item)
		}
	})
	await attempt(failures, 'colleagues', async () => {
		for (const record of attendancePayloads(meetingId, form.colleagues)) {
			await api.saveObject('meeting-attendance', record)
		}
	})
	if ((form.papers || []).length > 0) {
		await attempt(failures, 'papers', () =>
			api.uploadPapers(meetingId, form.papers),
		)
	}
	if (form.talk) {
		const uids = (form.colleagues || [])
			.map((c) => String(c.nextcloudUserId || ''))
			.filter((uid) => uid !== '')
		await attempt(failures, 'talk', () =>
			api.createTalkRoom(meetingId, meeting.title || form.title, uids),
		)
	}
	for (const guest of form.guests || []) {
		await attempt(failures, 'guests', async () => {
			const result = await api.inviteGuest(meetingId, guest)
			if (result && result.mailed === false) {
				failures.push({ step: 'mail', detail: guest.email })
			}
		})
	}
	return { meetingId, failures }
}

/**
 * Upload the papers into the meeting's folder.
 *
 * @param {string} meetingId The meeting
 * @param {Array<File>} files The papers
 * @return {Promise<void>}
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export async function uploadPapers(meetingId, files) {
	const body = new FormData()
	for (const file of files) {
		body.append('files[]', file, file.name)
	}
	const response = await fetch(
		generateUrl(
			`/apps/openregister/api/objects/decidiq/meeting/${encodeURIComponent(meetingId)}/filesMultipart`,
		),
		{
			method: 'POST',
			headers: { requesttoken: window.OC?.requestToken },
			body,
		},
	)
	if (!response.ok) {
		const data = await response.json().catch(() => ({}))
		throw new Error(data.error || data.message || response.statusText)
	}
}

/**
 * A JSON request with the CSRF token; a refusal throws its message.
 *
 * @param {string} url The URL
 * @param {object} body The JSON body
 * @return {Promise<object>} The answer
 */
async function postJson(url, body) {
	const response = await fetch(url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
			requesttoken: window.OC?.requestToken,
		},
		body: JSON.stringify(body),
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data?.message || data?.error || response.statusText)
	}
	return data
}

/**
 * Create the meeting's Talk conversation through OpenRegister's Talk link
 * (the same route the Video call tab uses) and add the colleagues to it.
 * Guests are not added: they reach the papers through their link only.
 *
 * @param {string} meetingId The meeting
 * @param {string} title The conversation name
 * @param {Array<string>} uids The colleagues' Nextcloud user ids
 * @return {Promise<string>} The room token, or ''
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export async function createTalkRoom(meetingId, title, uids) {
	const link = await postJson(generateUrl(`${talkLinksPath(meetingId)}/new`), {
		roomName: title,
		roomType: 2,
	})
	const token = roomToken([link?.link || link])
	for (const uid of token ? uids : []) {
		try {
			await postJson(
				generateOcsUrl(`apps/spreed/api/v4/room/${token}/participants`),
				{ newParticipant: uid, source: 'users' },
			)
		} catch {
			// One colleague who cannot be added does not stop the others.
		}
	}
	return token
}
