// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The steps of a meeting, for the step bar on the meeting page in the simple
 * structure (openspec/changes/simple-meeting-page).
 *
 * A meeting has six states. Four of them are a line: draft, convened, in
 * session, closed. The other two, paused and adjourned, are a break in a
 * meeting that is in session, so the bar keeps such a meeting on the third
 * step and says the break in words.
 *
 * No Vue in here, so the bar and its spec read one implementation. The one
 * request lives here too (`fetchOfferedSteps`), so the spec can watch it.
 *
 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { readStageAnswer, transitionsPath } from './meetingStages.js'

/** The four steps, in order. */
export const MEETING_STEPS = ['draft', 'scheduled', 'opened', 'closed']

/** The two states that are a break in a meeting that is in session. */
export const MEETING_BREAKS = ['paused', 'adjourned']

/**
 * The English source label of every state. The same words the Stage block
 * and the status pill use.
 */
export const MEETING_STATE_LABELS = {
	draft: 'Draft',
	scheduled: 'Convened',
	opened: 'In session',
	paused: 'Paused',
	adjourned: 'Adjourned',
	closed: 'Closed',
}

/**
 * The step the header's next-step button takes from each state, as the
 * server names it (MeetingService::TRANSITIONS). A closed meeting has none.
 */
export const MEETING_NEXT_ACTION = {
	draft: 'schedule',
	scheduled: 'open',
	opened: 'close',
	paused: 'resume',
	adjourned: 'open',
}

/**
 * The step of the bar a state stands on.
 *
 * @param {string} lifecycle The meeting's state.
 * @return {string} One of MEETING_STEPS, or '' for a state the bar does not know.
 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
 */
export function stepOf(lifecycle) {
	if (MEETING_BREAKS.includes(lifecycle)) {
		return 'opened'
	}
	return MEETING_STEPS.includes(lifecycle) ? lifecycle : ''
}

/**
 * The four steps, each done, current or upcoming.
 *
 * A meeting without a state, or with one the bar does not know, is on no
 * step: all four read as upcoming.
 *
 * @param {string} lifecycle The meeting's state.
 * @return {Array<{state: string, status: string}>} The steps.
 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-003-a-step-bar-shows-where-the-meeting-stands
 */
export function buildMeetingSteps(lifecycle) {
	const at = MEETING_STEPS.indexOf(stepOf(lifecycle))
	return MEETING_STEPS.map((state, index) => {
		let status = 'upcoming'
		if (at >= 0 && index < at) {
			status = 'done'
		} else if (index === at) {
			status = 'current'
		}
		return { state, status }
	})
}

/**
 * Whether the server offers this caller the step the header button takes.
 *
 * The answer comes from GET /api/meetings/{id}/transitions, the same answer
 * the Stage block draws its buttons from. A closed meeting has no next step,
 * so there is nothing to be refused: that reads as offered.
 *
 * @param {string} lifecycle The meeting's state.
 * @param {string[]} offered The steps the server offers the caller.
 * @return {boolean} False when the caller would be refused the next step.
 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
 */
export function nextStepIsOffered(lifecycle, offered) {
	const action = MEETING_NEXT_ACTION[lifecycle]
	if (action === undefined) {
		return true
	}
	return Array.isArray(offered) && offered.includes(action)
}

/**
 * Ask the server which steps it offers the reader on this meeting.
 *
 * The answer the Stage block draws its buttons from (GET
 * /api/meetings/{id}/transitions). It goes through `@nextcloud/axios`, which
 * carries the `requesttoken` header Nextcloud's CSRF check asks of a GET on
 * this app's routes. A bare `fetch` with an Accept header is answered 412
 * and the bar would never learn anything (found live, 06 Oct).
 *
 * @param {string|number} meetingId The meeting's id.
 * @return {Promise<string[]|null>} The offered steps, or null without a usable answer.
 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md#requirement-req-smp-004-the-page-says-who-takes-the-next-step
 */
export async function fetchOfferedSteps(meetingId) {
	if (!meetingId) {
		return null
	}
	try {
		const { data } = await axios.get(generateUrl(transitionsPath(meetingId)))
		return readStageAnswer(data).actions
	} catch {
		return null
	}
}
