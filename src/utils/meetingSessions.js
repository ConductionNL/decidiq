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
 * Sessions in the order they start, then by title; a session without a start
 * comes last.
 *
 * @param {object} a A session.
 * @param {object} b Another session.
 * @return {number} Sort order.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
export function bySessionStart(a, b) {
	const at = Date.parse(a?.scheduledDate ?? '')
	const bt = Date.parse(b?.scheduledDate ?? '')
	const byStart =
		(Number.isNaN(at) ? Infinity : at) - (Number.isNaN(bt) ? Infinity : bt)
	return (
		(Number.isNaN(byStart) ? 0 : byStart)
		|| String(a?.title ?? '').localeCompare(String(b?.title ?? ''))
	)
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

/**
 * The other sessions of the same evening as this session.
 *
 * @param {object} meeting The session (a meeting that is no session has none).
 * @param {Array<object>} meetings The sessions read.
 * @return {Array<object>} The siblings, in the order they start.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
export function siblingsOf(meeting, meetings) {
	const parent = parentOf(meeting)
	if (parent === null) {
		return []
	}
	return (Array.isArray(meetings) ? meetings : [])
		.filter((m) => m?.id !== meeting.id && parentOf(m) === parent)
		.sort(bySessionStart)
}

/**
 * One column per session for the evening's page.
 *
 * @param {Array<object>} sessions The evening's sessions.
 * @param {object} [related] What was read beside them.
 * @param {object} [related.agendaItems] Session id to its agenda items.
 * @param {object} [related.broadcasts] Session id to its broadcast.
 * @param {object} [related.chairs] Participant id to the participant.
 * @param {string} [related.locale] Locale for the time.
 * @param {string} [related.timeZone] Time zone for the time.
 * @return {Array<object>} Columns { id, title, room, chair, time, lifecycle, firstItems, live }.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
export function sessionColumns(sessions, related = {}) {
	const {
		agendaItems = {},
		broadcasts = {},
		chairs = {},
		locale,
		timeZone,
	} = related
	return [...(Array.isArray(sessions) ? sessions : [])]
		.sort(bySessionStart)
		.map((session) => {
			const start = Date.parse(session.scheduledDate ?? '')
			const chair = chairs[referenceId(session.chair)] ?? null
			return {
				id: session.id,
				title: String(session.title ?? ''),
				room: String(session.room ?? ''),
				chair: String(chair?.displayName ?? chair?.name ?? ''),
				time: Number.isNaN(start)
					? ''
					: new Intl.DateTimeFormat(locale, {
							hour: '2-digit',
							minute: '2-digit',
							hour12: false,
							timeZone,
						}).format(start),
				lifecycle: String(session.lifecycle ?? ''),
				firstItems: [...(agendaItems[session.id] ?? [])]
					.sort(
						(a, b) =>
							(Number(a.orderNumber) || 0)
							- (Number(b.orderNumber) || 0),
					)
					.slice(0, 3),
				live: broadcasts[session.id]?.lifecycle ?? null,
			}
		})
}

/**
 * A new session of an evening: the evening, its date at the time entered (in
 * the evening's own notation of the time zone), and its body, mode and
 * publicity. The server fills the same defaults and checks the times.
 *
 * @param {object} evening The evening.
 * @param {object} entered What the secretariat entered: { title, room, time (HH:MM) }.
 * @return {object} The meeting to save.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
export function newSession(evening, entered) {
	const session = { title: String(entered?.title ?? '').trim() }
	if (entered?.room) {
		session.room = String(entered.room).trim()
	}
	session.parentMeeting = evening.id
	const match =
		/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/.exec(
			String(evening.scheduledDate ?? ''),
		)
	if (match && /^\d{2}:\d{2}$/.test(String(entered?.time ?? ''))) {
		session.scheduledDate = `${match[1]}T${entered.time}:00${match[2] ?? ''}`
	}
	for (const field of ['governanceBody', 'meetingMode', 'isPublic']) {
		const value =
			field === 'governanceBody' ? referenceId(evening[field]) : evening[field]
		if (value !== undefined && value !== null && value !== '') {
			session[field] = value
		}
	}
	return session
}
