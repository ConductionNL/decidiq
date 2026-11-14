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
 * Who sits on a participant's seat now: the substitution in force, if any,
 * and the participant holding the seat.
 */
class SeatHolding {

	/**
	 * @param {object|null} substitution The substitution in force, or null.
	 * @param {object} holder The participant holding the seat.
	 */
	constructor(substitution, holder) {
		this.substitution = substitution
		this.holder = holder
	}

}

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
	const activeByOutgoing = activeSubstitutionsByOutgoing(seats)

	return participants
		.filter((p) => VOTING_ROLES.includes(p.role))
		.map((p) => {
			const substitution = activeByOutgoing[p.id] ?? null
			const holder = substitution ? substituteHolder(substitution, byId) : p
			return seatRow(p, new SeatHolding(substitution, holder))
		})
		.sort(bySeatThenName)
}

/**
 * The substitutions still in force, by the participant they replace.
 *
 * @param {object} seats The seats read.
 * @return {Object<string, object>} The substitutions by outgoing participant.
 */
function activeSubstitutionsByOutgoing(seats) {
	const active = (seats?.substitutions ?? []).filter((s) => !s.endedAt)
	return Object.fromEntries(active.map((s) => [s.outgoingParticipant, s]))
}

/**
 * The participant a substitution brings in, or a bare stand-in when the
 * seats read does not list them.
 *
 * @param {object} substitution The substitution.
 * @param {Object<string, object>} byId The participants, by id.
 * @return {object} The holder.
 */
function substituteHolder(substitution, byId) {
	return (
		byId[substitution.incomingParticipant] ?? {
			id: substitution.incomingParticipant,
			displayName: '',
		}
	)
}

/**
 * One seat row of the panel.
 *
 * @param {object} p The voting participant whose seat it is.
 * @param {SeatHolding} holding Who sits on it now.
 * @return {object} The row.
 */
function seatRow(p, holding) {
	const { substitution, holder } = holding
	return {
		seat: seatNumberOf(p, substitution),
		id: holder.id,
		name: holder.displayName,
		party: partyOf(p, substitution),
		role: p.role,
		substitution,
		substituteFor: substitution ? p.displayName : '',
		canSwap: p.role === 'member' && !substitution,
	}
}

/**
 * The seat number a row shows: the substitution's, else the participant's.
 *
 * @param {object} p The voting participant.
 * @param {object|null} substitution The substitution in force, or null.
 * @return {number|null} The seat number.
 */
function seatNumberOf(p, substitution) {
	return substitution?.seatNumber ?? p.seatNumber ?? null
}

/**
 * The party a row shows: the substitution's, else the participant's.
 *
 * @param {object} p The voting participant.
 * @param {object|null} substitution The substitution in force, or null.
 * @return {string} The party.
 */
function partyOf(p, substitution) {
	return substitution?.party ?? p.party ?? ''
}

/**
 * Orders rows by seat number, unnumbered seats last, then by name.
 *
 * @param {object} a A row.
 * @param {object} b Another row.
 * @return {number} The comparison.
 */
function bySeatThenName(a, b) {
	return (
		(a.seat ?? Number.MAX_SAFE_INTEGER) - (b.seat ?? Number.MAX_SAFE_INTEGER)
		|| a.name.localeCompare(b.name)
	)
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
	const holding = new Set(
		(seats?.substitutions ?? [])
			.filter((s) => !s.endedAt)
			.map((s) => s.incomingParticipant),
	)
	return (seats?.participants ?? [])
		.filter((p) => !VOTING_ROLES.includes(p.role) && !holding.has(p.id))
		.map((p) => ({
			id: p.id,
			label: p.party ? `${p.displayName} (${p.party})` : p.displayName,
		}))
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
	const response = await fetch(
		generateUrl(
			`/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/${tail}`,
		),
		{
			method,
			headers: {
				requesttoken: window.OC?.requestToken,
				'Content-Type': 'application/json',
			},
			body: body === null ? undefined : JSON.stringify(body),
		},
	)
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.message || response.statusText)
	}
	return data
}
