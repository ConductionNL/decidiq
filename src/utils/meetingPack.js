// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The meeting pack (vergaderbundel) section on the meeting page
// (meeting-pack-board-book, task 8). Pure helpers, so the widget stays thin
// and vitest can cover the rules.

import { generateRemoteUrl, generateUrl } from '@nextcloud/router'

/** States in which a compilation is under way. */
export const IN_FLIGHT = ['queued', 'running']

/**
 * The compile URL of a meeting's pack.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
 */
export function compileUrl(meetingId) {
	return generateUrl(`/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/board-book`)
}

/**
 * The status URL of a meeting's pack.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
 */
export function statusUrl(meetingId) {
	return `${compileUrl(meetingId)}/status`
}

/**
 * The OpenRegister list URL of a meeting's packs.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
 */
export function packsUrl(meetingId) {
	return generateUrl(
		`/apps/openregister/api/objects/decidiq/meeting-pack?meeting=${encodeURIComponent(meetingId)}&_limit=100`,
	)
}

/**
 * The download URL of a pack version for the signed-in user.
 *
 * The pack reaches an attendee as a share of the version's file, which
 * Nextcloud places in the root of their Files under its own name.
 *
 * @param {string} filePath The pack's file path.
 * @param {string} uid The signed-in user.
 * @return {string} The URL, or '' without a path or a user.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
 */
export function downloadUrl(filePath, uid) {
	const name = String(filePath || '').split('/').pop()
	if (!name || !uid) return ''
	return `${generateRemoteUrl('dav')}/files/${encodeURIComponent(uid)}/${encodeURIComponent(name)}`
}

/**
 * The meeting's packs, newest version first; rows of another meeting are dropped.
 *
 * @param {object[]} rows The MeetingPack objects read.
 * @param {string} meetingId The meeting.
 * @return {object[]} The versions.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
 */
export function versionsOf(rows, meetingId) {
	return (Array.isArray(rows) ? rows : [])
		.filter((row) => row && String(row.meeting?.id ?? row.meeting ?? '') === String(meetingId))
		.sort((a, b) => Number(b.version || 0) - Number(a.version || 0))
}

/**
 * Whether the compile action shows: chair, secretary or administrator only.
 *
 * @param {object|null} roles The caller's meeting roles.
 * @return {boolean} True when the caller may compile.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
 */
export function canCompile(roles) {
	return Boolean(roles && (roles.chair || roles.secretary || roles.admin))
}

/**
 * Whether the next compilation needs a change note: from version 2 on.
 *
 * @param {object[]} versions The versions, newest first.
 * @return {boolean} True when a note is required.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
 */
export function needsChangeNote(versions) {
	return Array.isArray(versions) && versions.length > 0
}

/**
 * Whether the latest pack is outdated: there is one and the server says the agenda moved.
 *
 * @param {object|null} status The status endpoint's answer.
 * @return {boolean} True to show "Vergaderbundel verouderd".
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
 */
export function isOutdated(status) {
	return Boolean(status && Number(status.latestVersion || 0) > 0 && status.outdated === true)
}

/**
 * Whether a compilation is queued or running.
 *
 * @param {object|null} status The status endpoint's answer.
 * @return {boolean} True while it is under way.
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
 */
export function isCompiling(status) {
	return Boolean(status && IN_FLIGHT.includes(status.state))
}
