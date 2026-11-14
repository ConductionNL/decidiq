/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The entry sheet of a roll call round (board DcStemmenInvoeren): the chair or
 * the secretary records each member's choice by name. The server stores one
 * vote per member, marked as recorded, and refuses the whole list when any
 * entry is wrong, naming the members concerned.
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The choices a member's row can hold; anything else means "did not vote". */
export const NAMED_VOTE_VALUES = Object.freeze(['for', 'against', 'abstain'])

/** The voting method whose votes are recorded by name. */
export const ROLL_CALL = 'roll-call'

/**
 * Whether a round's votes are recorded by name instead of cast by members.
 *
 * @param {?object} round The voting round.
 * @return {boolean} True for an open or closed roll call round.
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-001-a-chair-opens-a-roll-call-round-whose-votes-are-recorded-by-name
 */
export function isRollCall(round) {
	return round?.votingMethod === ROLL_CALL
}

/**
 * The members listed on the sheet: the meeting's participants who have not left.
 *
 * @param {Array<object>} participants The participants: `{ id, displayName, party, leftAt }`.
 * @return {Array<object>} The active ones, in the order given.
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */
export function activeMembers(participants) {
	return (participants || []).filter(
		(member) => member && member.id && !member.leftAt,
	)
}

/**
 * The parties on the sheet, each once, in order of first appearance.
 *
 * @param {Array<object>} members The listed members.
 * @return {string[]} The party names; a member without one adds none.
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */
export function partiesOf(members) {
	const parties = []
	for (const member of members || []) {
		if (member.party && !parties.includes(member.party)) {
			parties.push(member.party)
		}
	}
	return parties
}

/**
 * Set one choice for every member of a party, leaving the others as they are.
 *
 * @param {Record<string, string>} choices The choice per participant id.
 * @param {Array<object>} members The listed members.
 * @param {string} party The party.
 * @param {string} value for, against, abstain, or empty for "did not vote".
 * @return {Record<string, string>} A new choice map.
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */
export function applyPartyChoice(choices, members, party, value) {
	const next = { ...(choices || {}) }
	const choice = NAMED_VOTE_VALUES.includes(value) ? value : ''
	for (const member of members || []) {
		if (party && member.party === party) {
			next[member.id] = choice
		}
	}
	return next
}

/**
 * The entries to send: one per member with a choice, in the order of the sheet.
 *
 * @param {Record<string, string>} choices The choice per participant id.
 * @param {Array<object>} members The listed members.
 * @return {Array<{participantId: string, value: string}>} The entries.
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */
export function namedVoteEntries(choices, members) {
	return (members || [])
		.filter((member) => NAMED_VOTE_VALUES.includes(choices?.[member.id]))
		.map((member) => ({
			participantId: member.id,
			value: choices[member.id],
		}))
}

/**
 * The names of the members a refusal is about, falling back to the id.
 *
 * @param {?object} refusal The server's answer: `{ message, participantIds }`.
 * @param {Array<object>} members The listed members.
 * @return {string[]} The names, in the order the server gave them.
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-003-the-recorded-list-is-refused-as-a-whole-when-any-entry-is-wrong
 */
export function refusedNames(refusal, members) {
	const ids = Array.isArray(refusal?.participantIds)
		? refusal.participantIds
		: []
	return ids.map(
		(id) =>
			(members || []).find((member) => member.id === id)?.displayName
			|| String(id),
	)
}

/**
 * Save the sheet of one roll call round.
 *
 * @param {string} roundId The voting round id.
 * @param {Array<{participantId: string, value: string}>} entries The entries.
 * @param {string} [heldAt] When the vote was held (ISO 8601); now when left out.
 * @return {Promise<object>} The server's answer: `{ votingRoundId, recorded }`.
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */
export async function recordNamedVotes(roundId, entries, heldAt) {
	const body = { entries }
	if (heldAt) body.heldAt = heldAt
	const { data } = await axios.post(
		generateUrl('/apps/decidiq/api/voting-rounds/{id}/named-votes', {
			id: roundId,
		}),
		body,
	)
	return data
}
