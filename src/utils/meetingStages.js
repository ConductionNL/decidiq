// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The stage buttons on the meeting page (meeting-stage-buttons-and-cost).
 *
 * The server says which steps the caller may take (GET .../transitions) and
 * applies one through the guarded lifecycle (POST .../lifecycle). This module
 * holds the paths and reads the answers, so the widget cannot show a step the
 * server did not offer.
 *
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
 */

/** Every step the meeting state machine knows, in the order the buttons show. */
export const STAGE_ACTIONS = [
	'schedule',
	'open',
	'pause',
	'resume',
	'adjourn',
	'close',
]

/**
 * @param {string} meetingId Meeting uuid
 * @return {string} The path of the steps the caller may take
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
 */
export function transitionsPath(meetingId) {
	return `/apps/decidiq/api/meetings/${meetingId}/transitions`
}

/**
 * @param {string} meetingId Meeting uuid
 * @return {string} The path that applies a step
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
 */
export function lifecyclePath(meetingId) {
	return `/apps/decidiq/api/meetings/${meetingId}/lifecycle`
}

/**
 * Read the server's answer. Only known steps are kept, in button order; a
 * missing or malformed answer offers no step.
 *
 * @param {object|null} answer The GET .../transitions body
 * @return {{lifecycle: string, actions: string[]}}
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
 */
export function readStageAnswer(answer) {
	const offered = Array.isArray(answer?.actions) ? answer.actions : []
	return {
		lifecycle: typeof answer?.lifecycle === 'string' ? answer.lifecycle : '',
		actions: STAGE_ACTIONS.filter((action) => offered.includes(action)),
	}
}

/**
 * The recorded cost of a meeting, or null when none was recorded.
 *
 * @param {object|null} meeting The meeting object
 * @return {number|null}
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
 */
export function recordedCost(meeting) {
	const cost = Number(meeting?.meetingCost)
	return meeting?.meetingCost !== null
		&& meeting?.meetingCost !== undefined
		&& meeting?.meetingCost !== ''
		&& Number.isFinite(cost)
		? cost
		: null
}
