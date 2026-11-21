/**
 * The request that tells the room system who has the floor (platform-baseline-on-nextcloud,
 * REQ-PLB-006). The chair gives a member the floor in the speaker queue; the server sends the
 * member and their seat to the linked room system, so its cameras and microphones follow the
 * speaker. A public speaker is not a participant and has no seat, so nothing is sent for one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-006-the-rooms-cameras-and-microphones-follow-the-speaker
 */

import { isPublicSpeaker } from './speakingRequests.js'

/**
 * The floor request for a member, or null when there is nothing to send.
 *
 * @param {string} meetingId The meeting UUID.
 * @param {string} participantId The participant given the floor.
 * @return {{path: string, body: {participant: string}}|null} The request.
 * @spec openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-006-the-rooms-cameras-and-microphones-follow-the-speaker
 */
export function floorRequest(meetingId, participantId) {
	if (!meetingId || !participantId || isPublicSpeaker({ participantId })) {
		return null
	}

	return {
		path: `/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/room-system/floor`,
		body: { participant: participantId },
	}
}
