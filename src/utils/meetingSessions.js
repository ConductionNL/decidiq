// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * An evening's parallel sessions (planning-parallel-sessions). A session is a
 * meeting whose `parentMeeting` points at its evening; the server keeps the
 * shape (ParallelSessionListener), these helpers only arrange what was read.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */

/**
 * The id a reference holds: a uuid string or an expanded object.
 *
 * @param {string|object|null|undefined} value The stored reference.
 * @return {string|null} The id, or null.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 */
export function referenceId(value) {
	const id = value && typeof value === 'object' ? (value.id ?? value.uuid) : value
	return typeof id === 'string' && id !== '' ? id : null
}

/**
 * The evening a meeting is a session of.
 *
 * @param {object} meeting The meeting.
 * @return {string|null} The evening's id, or null for a meeting that is no session.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 */
export function parentOf(meeting) {
	return referenceId(meeting?.parentMeeting)
}

/**
 * Sessions in the order they start, then by title.
 *
 * @param {object} a A session.
 * @param {object} b Another session.
 * @return {number} Sort order.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
export function bySessionStart(a, b) {
	const at = Date.parse(a?.scheduledDate ?? '') || 0
	const bt = Date.parse(b?.scheduledDate ?? '') || 0
	return at - bt || String(a?.title ?? '').localeCompare(String(b?.title ?? ''))
}

/**
 * The meetings as the calendar shows them: every meeting that is no session,
 * each with its loaded sessions in `sessions`, and a session whose evening is
 * not loaded on its own. The input is not changed.
 *
 * @param {Array<object>} meetings The meetings read.
 * @return {Array<object>} Copies with a `sessions` array each.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */
export function groupSessions(meetings) {
	const list = Array.isArray(meetings) ? meetings : []
	const loaded = new Set(list.map((m) => m?.id).filter(Boolean))
	const sessionsOf = {}
	for (const meeting of list) {
		const parent = parentOf(meeting)
		if (parent !== null && parent !== meeting.id && loaded.has(parent)) {
			;(sessionsOf[parent] = sessionsOf[parent] || []).push(meeting)
		}
	}

	return list
		.filter((meeting) => {
			const parent = parentOf(meeting)
			return parent === null || parent === meeting.id || !loaded.has(parent)
		})
		.map((meeting) => ({
			...meeting,
			sessions: [...(sessionsOf[meeting.id] || [])].sort(bySessionStart),
		}))
}

/**
 * A session's line inside its evening: start time, title and room.
 *
 * @param {object} session The session.
 * @param {string} [locale] Locale for the time (defaults to the browser's).
 * @param {string} [timeZone] Time zone for the time (defaults to the browser's).
 * @return {string} The label.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */
export function sessionLabel(session, locale = undefined, timeZone = undefined) {
	const parts = []
	const start = Date.parse(session?.scheduledDate ?? '')
	if (!Number.isNaN(start)) {
		parts.push(
			new Intl.DateTimeFormat(locale, {
				hour: '2-digit',
				minute: '2-digit',
				hour12: false,
				timeZone,
			}).format(start),
		)
	}
	parts.push(String(session?.title ?? ''))
	if (session?.room) {
		parts.push(`(${session.room})`)
	}
	return parts.join(' ')
}
