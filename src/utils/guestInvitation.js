// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Invite a guest from outside to an ad hoc meeting (meeting-ad-hoc-with-
 * guests, pla-20, board DcAdhocOverleg). The guest is created, listed and
 * mailed server side (GuestInvitationController), so the organiser's
 * rights and the share of the papers are checked in one place.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The app-relative path of the guest route of a meeting.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The path.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export function guestPath(meetingId) {
	return `/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/guests`
}

/**
 * Whether a participant is a guest from outside: role guest and no
 * Nextcloud account.
 *
 * @param {object} participant A participant row.
 * @return {boolean} True for a guest.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export function isGuest(participant) {
	return participant?.role === 'guest' && !participant?.nextcloudUserId
}

/**
 * Invite a guest; a refusal throws its message.
 *
 * @param {string} meetingId The meeting.
 * @param {object} guest The guest.
 * @param {string} guest.name The guest's name.
 * @param {string} guest.email The guest's email.
 * @return {Promise<object>} The created guest ({ participant, attendance, mailed }).
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
export async function inviteGuest(meetingId, { name, email }) {
	const response = await fetch(generateUrl(guestPath(meetingId)), {
		method: 'POST',
		headers: {
			requesttoken: window.OC?.requestToken,
			'Content-Type': 'application/json',
		},
		body: JSON.stringify({
			name: (name || '').trim(),
			email: (email || '').trim(),
		}),
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.message || response.statusText)
	}
	return data
}
