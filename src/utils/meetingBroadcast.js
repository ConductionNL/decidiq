// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Broadcast widget on the meeting page (live-public-livestream, matrix
 * rows liv-06 liv-10 liv-19 liv-22): the routes BroadcastController serves,
 * the buttons each broadcast state offers, and what the widget reads back.
 * The states are the schema's x-openregister-lifecycle
 * (lib/Settings/register.d/120-meeting-broadcast.json), so a button offered
 * here is always a move OpenRegister accepts.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
 */
import { generateUrl } from '@nextcloud/router'

/** The buttons per broadcast state. No broadcast yet counts as planned. */
const ACTIONS = {
	planned: ['test', 'start'],
	testing: ['testResult', 'start'],
	live: ['pause', 'stop'],
	paused: ['resume', 'stop'],
	ended: [],
}

/** The path segment of each broadcast action route. */
const PATHS = {
	testResult: 'test-result',
	start: 'start',
	pause: 'pause',
	resume: 'resume',
	stop: 'stop',
}

/**
 * The address of the connection and broadcast status of a meeting.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
 */
export function statusUrl(meetingId) {
	return generateUrl(`/apps/decidiq/api/meetings/${meetingId}/broadcast`)
}

/**
 * The address that starts a test broadcast for a meeting.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
 */
export function testUrl(meetingId) {
	return generateUrl(`/apps/decidiq/api/meetings/${meetingId}/broadcast/test`)
}

/**
 * The address of an action on an existing broadcast.
 *
 * @param {string} broadcastId The broadcast.
 * @param {string} action testResult, start, pause, resume or stop.
 * @return {string} The URL.
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
 */
export function actionUrl(broadcastId, action) {
	return generateUrl(
		`/apps/decidiq/api/meeting-broadcasts/${broadcastId}/${PATHS[action]}`,
	)
}

/**
 * The buttons the widget shows: none without a connected service.
 *
 * @param {object|null} broadcast The meeting's broadcast, or null.
 * @param {boolean} connected Whether a streaming service is connected.
 * @return {Array<string>} The actions.
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
 */
export function actionsFor(broadcast, connected) {
	if (!connected) return []
	return [...(ACTIONS[broadcast?.lifecycle || 'planned'] || [])]
}

/**
 * The recorded test result, or null when no test was recorded.
 *
 * @param {object|null} broadcast The broadcast.
 * @return {{ok: boolean, note: string, by: string, at: string}|null} The result.
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
 */
export function testResultOf(broadcast) {
	if (!broadcast?.testResult) return null
	return {
		ok: broadcast.testResult === 'ok',
		note: broadcast.testNote || '',
		by: broadcast.testedBy || '',
		at: broadcast.testedAt || '',
	}
}

/**
 * What the service said about live captions, while the broadcast runs.
 *
 * @param {object|null} broadcast The broadcast.
 * @return {string} requested, unavailable or an empty string.
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-005-live-captions-come-from-the-streaming-service
 */
export function captionsNotice(broadcast) {
	if (!['live', 'paused'].includes(broadcast?.lifecycle)) return ''
	return broadcast.liveCaptions || ''
}
