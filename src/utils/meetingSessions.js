// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * An evening's parallel sessions (planning-parallel-sessions). A session is a
 * meeting whose `parentMeeting` points at its evening; the server keeps the
 * shape (ParallelSessionListener), these helpers only arrange what was read.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */

const EVENING_DATE =
	/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/
const ENTERED_TIME = /^\d{2}:\d{2}$/
const INHERITED_FIELDS = ['governanceBody', 'meetingMode', 'isPublic']
const ABSENT_VALUES = new Set([undefined, null, ''])

/**
 * The id a reference holds: a uuid string or an expanded object.
 *
 * @param {string|object|null|undefined} value The stored reference.
 * @return {string|null} The id, or null.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 */
export function referenceId(value) {
	const id = storedId(value)
	return typeof id === 'string' && id !== '' ? id : null
}

/**
 * What a reference stores as its id: the id (else uuid) of an expanded
 * object, or the value itself.
 *
 * @param {string|object|null|undefined} value The stored reference.
 * @return {string|number|boolean|null|undefined} The stored id, not yet checked.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 */
function storedId(value) {
	return value && typeof value === 'object' ? (value.id ?? value.uuid) : value
}

/**
 * The value when it is an array, else an empty list.
 *
 * @param {Array<object>|null|undefined} value The value to read as a list.
 * @return {Array<object>} The list.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function asList(value) {
	return Array.isArray(value) ? value : []
}

/**
 * A session's start in epoch ms, NaN when it has none.
 *
 * @param {object|null|undefined} session The session.
 * @return {number} The start, or NaN.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function parsedStart(session) {
	return Date.parse(textOf(session?.scheduledDate))
}

/**
 * A session's title as text.
 *
 * @param {object|null|undefined} session The session.
 * @return {string} The title, empty when it has none.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function titleOf(session) {
	return textOf(session?.title)
}

/**
 * A stored value as text, empty when it is null or undefined.
 *
 * @param {string|number|boolean|null|undefined} value The value.
 * @return {string} The text.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function textOf(value) {
	return String(value ?? '')
}

/**
 * The time of a start as hours and minutes on a 24-hour clock.
 *
 * @param {number} start The start in epoch ms.
 * @param {{locale?: string, timeZone?: string}} format Locale and time zone (each defaults to the browser's).
 * @return {string} The time.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */
function clockTime(start, format) {
	return new Intl.DateTimeFormat(format.locale, {
		hour: '2-digit',
		minute: '2-digit',
		hour12: false,
		timeZone: format.timeZone,
	}).format(start)
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
	const byStart = sortableStart(a) - sortableStart(b)
	return (
		(Number.isNaN(byStart) ? 0 : byStart) || titleOf(a).localeCompare(titleOf(b))
	)
}

/**
 * A session's start for sorting: a session without a start sorts last.
 *
 * @param {object|null|undefined} session The session.
 * @return {number} The start in epoch ms, or Infinity.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function sortableStart(session) {
	const start = parsedStart(session)
	return Number.isNaN(start) ? Infinity : start
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
	const list = asList(meetings)
	const loaded = new Set(list.map((m) => m?.id).filter(Boolean))
	const sessionsOf = {}
	for (const meeting of list) {
		const parent = loadedEvening(meeting, loaded)
		if (parent !== null) {
			;(sessionsOf[parent] = sessionsOf[parent] || []).push(meeting)
		}
	}

	return list
		.filter((meeting) => loadedEvening(meeting, loaded) === null)
		.map((meeting) => ({
			...meeting,
			sessions: [...(sessionsOf[meeting.id] || [])].sort(bySessionStart),
		}))
}

/**
 * The loaded evening a meeting is a session of, or null when it is no
 * session, points at itself or its evening is not loaded.
 *
 * @param {object} meeting The meeting.
 * @param {Set<string>} loaded Ids of the loaded meetings.
 * @return {string|null} The evening's id, or null.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */
function loadedEvening(meeting, loaded) {
	const parent = parentOf(meeting)
	return parent !== null && parent !== meeting.id && loaded.has(parent)
		? parent
		: null
}

/**
 * A session's line inside its evening: start time, title and room.
 *
 * @param {object} session The session.
 * @param {object} [format] How to show the time.
 * @param {string} [format.locale] Locale for the time (defaults to the browser's).
 * @param {string} [format.timeZone] Time zone for the time (defaults to the browser's).
 * @return {string} The label.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */
export function sessionLabel(session, format = {}) {
	const parts = []
	const start = parsedStart(session)
	if (!Number.isNaN(start)) {
		parts.push(clockTime(start, format))
	}
	parts.push(titleOf(session))
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
	return asList(meetings)
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
	const context = {
		agendaItems: withDefault(related.agendaItems, {}),
		broadcasts: withDefault(related.broadcasts, {}),
		chairs: withDefault(related.chairs, {}),
		locale: related.locale,
		timeZone: related.timeZone,
	}
	return [...asList(sessions)]
		.sort(bySessionStart)
		.map((session) => sessionColumn(session, context))
}

/**
 * The value, or the fallback when it is undefined.
 *
 * @param {object|undefined} value The value.
 * @param {object} fallback The fallback.
 * @return {object} The value or the fallback.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function withDefault(value, fallback) {
	return value === undefined ? fallback : value
}

/**
 * The column of one session.
 *
 * @param {object} session The session.
 * @param {object} context The related records with the locale and time zone, as sessionColumns reads them.
 * @return {object} The column.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function sessionColumn(session, context) {
	const start = parsedStart(session)
	const chair = context.chairs[referenceId(session.chair)]
	return {
		id: session.id,
		title: textOf(session.title),
		room: textOf(session.room),
		chair: chairName(chair),
		time: Number.isNaN(start) ? '' : clockTime(start, context),
		lifecycle: textOf(session.lifecycle),
		firstItems: firstItems(context.agendaItems[session.id]),
		live: context.broadcasts[session.id]?.lifecycle ?? null,
	}
}

/**
 * A chair's name as text.
 *
 * @param {object|null|undefined} chair The participant.
 * @return {string} The display name, else the name, else empty.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function chairName(chair) {
	return textOf(chair?.displayName ?? chair?.name)
}

/**
 * The first three agenda items by order number; an item without a number
 * counts as 0.
 *
 * @param {Array<object>|null|undefined} items The session's agenda items.
 * @return {Array<object>} At most three items.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function firstItems(items) {
	return [...(items ?? [])]
		.sort((a, b) => (Number(a.orderNumber) || 0) - (Number(b.orderNumber) || 0))
		.slice(0, 3)
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
	const session = { title: textOf(entered?.title).trim() }
	if (entered?.room) {
		session.room = String(entered.room).trim()
	}
	session.parentMeeting = evening.id
	const scheduledDate = sessionDate(evening, entered)
	if (scheduledDate !== null) {
		session.scheduledDate = scheduledDate
	}
	copyInherited(session, evening)
	return session
}

/**
 * Give a new session the body, mode and publicity its evening has.
 *
 * @param {object} session The new session, changed in place.
 * @param {object} evening The evening.
 * @return {void}
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function copyInherited(session, evening) {
	for (const field of INHERITED_FIELDS) {
		const value = inheritedValue(evening, field)
		if (!ABSENT_VALUES.has(value)) {
			session[field] = value
		}
	}
}

/**
 * A session's start: the evening's date at the time entered, in the
 * evening's notation of the time zone; null when either is not usable.
 *
 * @param {object} evening The evening.
 * @param {object|null|undefined} entered What the secretariat entered.
 * @return {string|null} The start, or null.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function sessionDate(evening, entered) {
	const match = EVENING_DATE.exec(textOf(evening.scheduledDate))
	const time = enteredTime(entered)
	if (match === null || time === null) {
		return null
	}
	return `${match[1]}T${time}:00${match[2] ?? ''}`
}

/**
 * The time entered as HH:MM, or null.
 *
 * @param {object|null|undefined} entered What the secretariat entered.
 * @return {string|null} The time, or null.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function enteredTime(entered) {
	return ENTERED_TIME.test(textOf(entered?.time)) ? entered.time : null
}

/**
 * The value a new session takes over from its evening for one field; the
 * body is stored as its id.
 *
 * @param {object} evening The evening.
 * @param {string} field The field.
 * @return {string|boolean|null|undefined} The value.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
function inheritedValue(evening, field) {
	return field === 'governanceBody' ? referenceId(evening[field]) : evening[field]
}
