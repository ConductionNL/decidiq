// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Publishing the agenda from the meeting page (agenda-publish-and-invite-members).
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
 */

/**
 * @param {string} meetingId Meeting uuid
 * @return {string} The path that publishes the agenda and invites the members
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
 */
export function publishAgendaPath(meetingId) {
	return `/apps/decidiq/api/agendas/${meetingId}/publish`
}

/**
 * When the current agenda was published, as "YYYY-MM-DD HH:mm" in the
 * viewer's time, or '' when it was not published.
 *
 * @param {object|null} meeting The meeting
 * @return {string}
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
 */
export function publishedOn(meeting) {
	const value = meeting?.agendaPublishedAt
	if (!value) return ''
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) return ''
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}
