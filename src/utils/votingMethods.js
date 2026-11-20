// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// capability-rows-get-requirements 3.3 (bod-14): a governance body may limit
// the voting methods its meetings use. The voting round panel offers only
// those; VotingRoundPreflight::assertMethodAllowed refuses any other on the
// server, so this list only keeps the chair from picking one that would be
// refused.

/**
 * Every voting method a round can use, in the order the panel lists them.
 *
 * @type {Array<string>}
 */
export const VOTING_METHODS = Object.freeze([
	'for-against-abstain',
	'show-of-hands',
	'weighted',
	'ranked-choice',
])

/**
 * The methods a body allows: its `allowedVotingMethods` in the panel's order,
 * or every method when the list is empty, missing or names none we know.
 *
 * @param {?object} body The governance body.
 * @return {Array<string>} The allowed methods, never empty.
 * @spec openspec/changes/capability-rows-get-requirements/specs/meeting-management/spec.md#requirement-a-body-limits-which-voting-methods-its-meetings-may-use
 */
export function allowedVotingMethods(body) {
	const declared = Array.isArray(body?.allowedVotingMethods)
		? body.allowedVotingMethods
		: []
	const allowed = VOTING_METHODS.filter((method) => declared.includes(method))
	return allowed.length > 0 ? allowed : [...VOTING_METHODS]
}

/**
 * The method a new round starts on: the current pick when it is allowed,
 * else the first allowed one.
 *
 * @param {string} current The current pick.
 * @param {Array<string>} allowed The allowed methods.
 * @return {string} The method.
 * @spec openspec/changes/capability-rows-get-requirements/specs/meeting-management/spec.md#requirement-a-body-limits-which-voting-methods-its-meetings-may-use
 */
export function startingVotingMethod(current, allowed) {
	return allowed.includes(current) ? current : allowed[0]
}

/**
 * The id of the body a meeting belongs to, from its `governanceBody`
 * reference (a uuid, or an object carrying one).
 *
 * @param {?object} meeting The meeting.
 * @return {string} The body id, or ''.
 * @spec openspec/changes/capability-rows-get-requirements/specs/meeting-management/spec.md#requirement-a-body-limits-which-voting-methods-its-meetings-may-use
 */
export function meetingBodyId(meeting) {
	const ref = meeting?.governanceBody
	if (typeof ref === 'string') {
		return ref
	}
	return String(ref?.id || ref?.uuid || '')
}
