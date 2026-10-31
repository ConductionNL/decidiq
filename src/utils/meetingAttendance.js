// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Attendance per meeting (meeting-attendance-per-meeting, pla-09). One
// meeting-attendance object records one participant at one meeting, so
// marking Anna excused on 14 October leaves her 7 October record alone.
//
// @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting

/** The statuses the meeting-attendance schema accepts. */
export const ATTENDANCE_STATUSES = ['present', 'absent', 'excused', 'proxy']

/**
 * Read a reference that is a bare uuid or an expanded object.
 *
 * @param {string|object|null} ref The reference
 * @return {string} The uuid, or ''
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
export function refId(ref) {
	if (!ref) return ''
	if (typeof ref === 'string') return ref
	return String(ref.id || ref.uuid || '')
}

/**
 * The attendance record of one participant at one meeting.
 *
 * @param {Array<object>} records Attendance records
 * @param {string} meetingId The meeting UUID
 * @param {string} participantId The participant UUID
 * @return {object|null} The record, or null
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
export function recordFor(records, meetingId, participantId) {
	return (
		(records || []).find(
			(r) =>
				refId(r.meeting) === meetingId
				&& refId(r.participant) === participantId,
		) || null
	)
}

/**
 * The widget rows: the body's participants plus anyone with a record for
 * this meeting (a guest), each with this meeting's status and record.
 * Records of other meetings are ignored.
 *
 * @param {Array<object>} members The body's participants
 * @param {Array<object>} records Attendance records (any meeting)
 * @param {string} meetingId The meeting UUID
 * @param {Array<object>} everyone All participants, to name guests
 * @return {Array<object>} Rows with status and record
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
export function attendanceRows(members, records, meetingId, everyone = []) {
	const own = (records || []).filter((r) => refId(r.meeting) === meetingId)
	const byId = new Map()
	for (const p of members || []) byId.set(refId(p), p)
	for (const r of own) {
		const id = refId(r.participant)
		if (id && !byId.has(id)) {
			const known = (everyone || []).find((p) => refId(p) === id)
			byId.set(id, known || { id, displayName: id })
		}
	}
	return [...byId.values()].map((participant) => {
		const record = recordFor(own, meetingId, refId(participant))
		return {
			...participant,
			attendance: record ? record.status || '' : '',
			attendanceRecord: record,
		}
	})
}

/**
 * The object to save when a participant's status is set on this meeting:
 * the existing record updated, or a new one. Present stamps the arrival
 * time once.
 *
 * @param {object|null} existing The participant's record for this meeting
 * @param {object} input The new values
 * @param {string} input.meetingId The meeting UUID
 * @param {string} input.participantId The participant UUID
 * @param {string} input.status One of ATTENDANCE_STATUSES
 * @param {string} input.now ISO timestamp for the arrival
 * @return {object} The attendance object
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
export function attendancePayload(
	existing,
	{ meetingId, participantId, status, now },
) {
	if (!ATTENDANCE_STATUSES.includes(status)) {
		throw new Error(`Unknown attendance status: ${status}`)
	}
	const payload = existing
		? { ...existing }
		: { meeting: meetingId, participant: participantId }
	payload.meeting = refId(payload.meeting) || meetingId
	payload.participant = refId(payload.participant) || participantId
	payload.status = status
	if (status === 'present' && !payload.arrivedAt && now) {
		payload.arrivedAt = now
	}
	return payload
}

/**
 * The objects to save for "Everyone present": every row not yet present.
 *
 * @param {Array<object>} rows From attendanceRows()
 * @param {string} meetingId The meeting UUID
 * @param {string} now ISO timestamp for the arrival
 * @return {Array<object>} Attendance objects to save
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
export function everyonePresent(rows, meetingId, now) {
	return (rows || [])
		.filter((row) => row.attendance !== 'present')
		.map((row) =>
			attendancePayload(row.attendanceRecord, {
				meetingId,
				participantId: refId(row),
				status: 'present',
				now,
			}),
		)
}
