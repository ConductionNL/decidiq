// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The seats of a live meeting and the mandate swap (bodies-substitute-mandate-swap).
 * The substitution schema keeps its writes closed on the object API, so a swap
 * and its end go through MandateSubstitutionController, which checks that the
 * caller presides and that no vote is open.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
 */

import { generateUrl } from '@nextcloud/router'

const VOTING_ROLES = ['chair', 'vice-chair', 'secretary', 'member']

/**
 * The seat rows of the panel, in seat order: each voting participant, and on
 * a seat a substitute holds now, the substitute with the member they replace.
 *
 * @param {object} seats The seats read: { participants, substitutions }.
 * @return {Array<object>} Rows { seat, id, name, party, role, substitution, substituteFor, canSwap }.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
 */
export function seatRows(seats) {
	const participants = seats?.participants ?? []
	const byId = Object.fromEntries(participants.map((p) => [p.id, p]))
	const active = (seats?.substitutions ?? []).filter((s) => !s.endedAt)
	const activeByOutgoing = Object.fromEntries(active.map((s) => [s.outgoingParticipant, s]))

	return participants
		.filter((p) => VOTING_ROLES.includes(p.role))
		.map((p) => {
			const substitution = activeByOutgoing[p.id] ?? null
			const holder = substitution ? (byId[substitution.incomingParticipant] ?? { id: substitution.incomingParticipant, displayName: '' }) : p
			return {
				seat: substitution?.seatNumber ?? p.seatNumber ?? null,
				id: holder.id,
				name: holder.displayName,
				party: substitution?.party ?? p.party ?? '',
				role: p.role,
				substitution,
				substituteFor: substitution ? p.displayName : '',
				canSwap: p.role === 'member' && !substitution,
			}
		})
		.sort((a, b) => (a.seat ?? Number.MAX_SAFE_INTEGER) - (b.seat ?? Number.MAX_SAFE_INTEGER) || a.name.localeCompare(b.name))
}

/**
 * Who may take a member's seat: a participant of the body without a voting
 * role who does not already hold a seat as a substitute.
 *
 * @param {object} seats The seats read: { participants, substitutions }.
 * @return {Array<object>} Options { id, label }.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
 */
export function substituteOptions(seats) {
	const holding = new Set((seats?.substitutions ?? []).filter((s) => !s.endedAt).map((s) => s.incomingParticipant))
	return (seats?.participants ?? [])
		.filter((p) => !VOTING_ROLES.includes(p.role) && !holding.has(p.id))
		.map((p) => ({ id: p.id, label: p.party ? `${p.displayName} (${p.party})` : p.displayName }))
}

/**
 * One request to a seats route; a refusal throws its message.
 *
 * @param {string} method The HTTP method.
 * @param {string} meetingId The meeting.
 * @param {string} tail The path after the meeting: 'seats', 'substitutions' or 'substitutions/{id}/end'.
 * @param {object|null} body The JSON body.
 * @return {Promise<object>} The response body.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member
 */
export async function seatsRequest(method, meetingId, tail, body = null) {
	const response = await fetch(generateUrl(`/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/${tail}`), {
		method,
		headers: {
			requesttoken: window.OC?.requestToken,
			'Content-Type': 'application/json',
		},
		body: body === null ? undefined : JSON.stringify(body),
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.message || response.statusText)
	}
	return data
}
