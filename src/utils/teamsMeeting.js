// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Teams meeting of a digital or hybrid meeting
 * (platform-microsoft-365-calendar-and-teams, matrix row plt-12): the route
 * TeamsMeetingController serves, and what the meeting page and the live
 * meeting page read from `Meeting.teamsMeeting`. Pure helpers, so the
 * components stay thin and vitest covers the rules.
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */
import { generateUrl } from '@nextcloud/router'

/** Every Teams join address starts with this; the schema declares the same pattern. */
export const TEAMS_JOIN_PREFIX = 'https://teams.microsoft.com/'

/** The meeting modes that can carry a Teams meeting. */
const ONLINE_MODES = ['digital', 'hybrid']

/**
 * The address of the connection status and Teams meeting of a meeting; a POST creates one.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */
export function teamsMeetingUrl(meetingId) {
	return generateUrl(
		`/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/teams-meeting`,
	)
}

/**
 * Whether an address is a Teams join address.
 *
 * @param {string|null|undefined} value The address.
 * @return {boolean} True when it starts with https://teams.microsoft.com/.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */
export function isTeamsJoinUrl(value) {
	return typeof value === 'string' && value.startsWith(TEAMS_JOIN_PREFIX)
}

/**
 * The Teams join address of a meeting, or '' when it has none (or a stored one is not Teams).
 *
 * @param {object|null} meeting The meeting.
 * @return {string} The join address.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-005-members-join-the-teams-meeting-from-decidiq
 */
export function teamsJoinUrl(meeting) {
	const url = meeting?.teamsMeeting?.joinUrl
	return isTeamsJoinUrl(url) ? url : ''
}

/**
 * Whether a meeting is digital or hybrid, and so may get a Teams meeting.
 *
 * @param {object|null} meeting The meeting.
 * @return {boolean} True for a digital or hybrid meeting.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */
export function isOnlineMeeting(meeting) {
	return ONLINE_MODES.includes(meeting?.meetingMode)
}

/**
 * Whether the Create a Teams meeting button is offered: an online meeting
 * without a Teams meeting, a linked Microsoft 365 source, and a caller the
 * status route answered (only the chair, the secretary or an admin).
 *
 * @param {object|null} meeting The meeting.
 * @param {object|null} status The answer of the status route, null when refused.
 * @return {boolean} True when the button is shown.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */
export function canCreateTeams(meeting, status) {
	return (
		isOnlineMeeting(meeting)
		&& teamsJoinUrl(meeting) === ''
		&& status?.connected === true
	)
}
