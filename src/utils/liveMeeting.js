// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the shared live meeting (live-meeting-shared-current-item):
 * the current agenda item lives on the meeting, members and the room screen
 * follow it, a live decision names its item, and speeches and questions carry
 * the item they were made on.
 *
 * @spec openspec/specs/agenda-live-management/spec.md
 */

import { matching, references } from './objectRelations.js'

/** How often a member's live screen and the room screen re-read the meeting. */
export const FOLLOW_INTERVAL_MS = 5000

/**
 * The agenda item the chair made current, as saved on the meeting.
 *
 * @param {?object} meeting The meeting object.
 *
 * @return {?string} The item id, or null when none is current.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
 */
export function sharedCurrentItemId(meeting) {
	const value = meeting?.currentAgendaItem
	return typeof value === 'string' && value !== '' ? value : null
}

/**
 * Whether the caller runs the meeting (chair, secretary or admin), as
 * answered by GET /api/meetings/{id}/my-roles.
 *
 * @param {?{chair: boolean, secretary: boolean, admin: boolean}} roles The server's answer.
 *
 * @return {boolean} True for the chair, the secretary or an admin.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-003-a-decision-is-recorded-when-it-is-taken
 */
export function runsTheMeeting(roles) {
	return Boolean(roles && (roles.chair || roles.secretary || roles.admin))
}

/**
 * Whether a voting round is open: opened and not yet closed. VotingRound
 * carries no status field; openedAt and closedAt are the state.
 *
 * @param {?object} round The voting round.
 *
 * @return {boolean} True while voting is open.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote
 */
export function isOpenRound(round) {
	return Boolean(round?.openedAt) && !round?.closedAt
}

/**
 * The open voting round on the current item: a round whose relations
 * reference the item, or one of the motions that reference the item.
 *
 * @param {Array<object>} rounds  Voting rounds.
 * @param {?object}       item    The current agenda item.
 * @param {Array<object>} motions Motions (only those referencing the item count).
 *
 * @return {?object} The open round, or null.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote
 */
export function openRoundFor(rounds, item, motions = []) {
	if (!item?.id) return null
	const targets = [item.id, ...matching(motions, item.id).map((motion) => motion.id)]
	return (rounds ?? []).find(
		(round) => isOpenRound(round) && targets.some((target) => references(round, target)),
	) ?? null
}

/**
 * The body posted to POST /api/meetings/{id}/live-decisions for the current item.
 *
 * @param {{title: string, text: string, outcome: string, decisionType: string}} form The dialog's fields.
 * @param {?string} itemId The current agenda item.
 *
 * @return {object} The request body.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-003-a-decision-is-recorded-when-it-is-taken
 */
export function liveDecisionBody(form, itemId) {
	const body = {
		title: String(form?.title ?? '').trim(),
		text: String(form?.text ?? '').trim(),
		outcome: form?.outcome || 'adopted',
		decisionType: form?.decisionType || 'resolution',
	}
	if (itemId) body.agendaItem = itemId
	return body
}

/**
 * The body posted to POST /api/engagement: a speech or a question on the
 * current item.
 *
 * @param {string} meetingId   The meeting.
 * @param {string} participant The participant.
 * @param {'speech'|'question'} eventType What happened.
 * @param {?string} itemId     The current agenda item.
 * @param {object} extra       Event details, such as duration.
 *
 * @return {object} The request body.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
 */
export function engagementBody(meetingId, participant, eventType, itemId, extra = {}) {
	const eventData = { ...extra }
	if (itemId) eventData.agendaItem = itemId
	return { meeting: meetingId, participant, eventType, eventData }
}

/**
 * Who spoke and who raised a question on one agenda item, read from the
 * meeting's engagement records (GET /api/engagement?meeting=).
 *
 * @param {Array<object>} records      Engagement records, one per participant.
 * @param {?string}       itemId       The agenda item.
 * @param {Array<object>} participants Participants, for their names.
 *
 * @return {Array<{participantId: string, name: string, kind: 'speech'|'question', duration: number}>} The contributions, speeches first per participant.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item
 */
export function contributionsOn(records, itemId, participants = []) {
	if (!itemId) return []
	const names = new Map(
		(participants ?? []).map((p) => [p.id, p.displayName || p.name || p.id]),
	)
	const result = []
	for (const record of records ?? []) {
		const participantId = record?.participant
		const name = names.get(participantId) ?? participantId
		for (const speech of record?.speeches ?? []) {
			if (speech?.agendaItem !== itemId) continue
			result.push({ participantId, name, kind: 'speech', duration: Number(speech.duration) || 0 })
		}
		for (const question of record?.questionsRaised ?? []) {
			if (question?.agendaItem !== itemId) continue
			result.push({ participantId, name, kind: 'question', duration: 0 })
		}
	}
	return result
}
