// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The video call of a digital or hybrid meeting (meeting-video-call, pla-08).
// The Talk room is linked to the meeting through OpenRegister's Talk
// integration (/api/objects/{register}/{schema}/{id}/talk), the same link
// the meeting's Integrations page shows.
//
// @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call

/** Meeting modes that have a video call. */
export const VIDEO_MODES = ['digital', 'hybrid']

/**
 * Whether the meeting is held (partly) online.
 *
 * @param {object|null} meeting The meeting
 * @return {boolean} True for digital or hybrid
 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
 */
export function hasVideoCall(meeting) {
	return !!meeting && VIDEO_MODES.includes(meeting.meetingMode)
}

/**
 * OpenRegister's Talk links of the meeting.
 *
 * @param {string} meetingId The meeting UUID
 * @return {string} The app-relative path
 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
 */
export function talkLinksPath(meetingId) {
	return `/apps/openregister/api/objects/decidiq/meeting/${encodeURIComponent(meetingId)}/talk`
}

/**
 * The token of the first linked room.
 *
 * @param {Array<object>} rooms The linked rooms
 * @return {string} The room token, or ''
 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
 */
export function roomToken(rooms) {
	const room = (rooms || [])[0]
	return room ? String(room.roomToken || room.token || '') : ''
}

/**
 * The Talk page of a room.
 *
 * @param {string} token The room token
 * @return {string} The path for generateUrl
 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
 */
export function joinPath(token) {
	return `/call/${encodeURIComponent(token)}`
}

/**
 * The room token in a pasted Talk link, or a bare token.
 *
 * @param {string} input What the secretary pasted
 * @return {string} The token, or '' when there is none
 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
 */
export function tokenFromInput(input) {
	const text = String(input || '').trim()
	const fromLink = text.match(/\/call\/([A-Za-z0-9]+)/)
	if (fromLink) return fromLink[1]
	return /^[A-Za-z0-9]{4,32}$/.test(text) ? text : ''
}

/**
 * The Nextcloud users of the body's current members, to invite.
 *
 * @param {Array<object>} participants Participants
 * @param {string} bodyId The meeting's body
 * @return {Array<string>} Unique user ids
 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
 */
export function memberUids(participants, bodyId) {
	const refOf = (ref) =>
		ref && typeof ref === 'object' ? ref.id || ref.uuid : ref
	const uids = (participants || [])
		.filter((p) => refOf(p.governanceBody) === bodyId && !p.leftAt)
		.map((p) => String(p.nextcloudUserId || ''))
		.filter((uid) => uid !== '')
	return [...new Set(uids)]
}
