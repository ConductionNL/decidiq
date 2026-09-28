// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The voting controls a user may use, as the server answers them
 * (voting-chair-close-and-amendment-rounds). The page never works this out
 * itself: GET /api/meetings/{meetingId}/voting-permissions answers through the
 * same VotingRoundGuard the endpoints enforce.
 *
 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-001-the-meetings-chair-and-secretary-see-the-voting-controls
 */

/** Every control hidden: the state before the answer arrives, and after an error. */
export const NO_VOTING_PERMISSIONS = Object.freeze({
	canOpen: false,
	canClose: false,
	canEnterTally: false,
	canCastChairVote: false,
})

/**
 * The app-relative path of the permissions read: per meeting, or the global
 * fallback for a subject without a meeting.
 *
 * @param {string} meetingId Meeting UUID, or ''.
 *
 * @return {string} The path to pass to generateUrl().
 *
 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
 */
export function votingPermissionsPath(meetingId) {
	if (!meetingId) return '/apps/decidiq/api/voting-permissions'
	return `/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/voting-permissions`
}

/**
 * Read the server's answer. Only a literal `true` grants a control, so a
 * missing or malformed answer hides it; the server refuses the call anyway.
 *
 * @param {?object} payload The JSON body of the permissions read.
 *
 * @return {{canOpen: boolean, canClose: boolean, canEnterTally: boolean, canCastChairVote: boolean}} The permissions.
 *
 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
 */
export function readVotingPermissions(payload) {
	const out = { ...NO_VOTING_PERMISSIONS }
	for (const key of Object.keys(out)) {
		out[key] = payload?.[key] === true
	}
	return out
}

/**
 * The subject part of the open-round request. The server names the subject id
 * `motionId` for both kinds and tells them apart by `subjectType`.
 *
 * @param {{subjectId: string, subjectType?: string, meetingId: string}} subject The subject of the round.
 *
 * @return {{motionId: string, subjectType: string, meetingId: string}} The body fields.
 *
 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page
 */
export function votingRoundBody({ subjectId, subjectType, meetingId }) {
	return {
		motionId: subjectId,
		subjectType: subjectType === 'amendment' ? 'amendment' : 'motion',
		meetingId,
	}
}

/**
 * The id a reference holds: a bare uuid, or an expanded object.
 *
 * @param {*} ref The stored reference.
 *
 * @return {string} The id, or ''.
 *
 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page
 */
function referenceId(ref) {
	if (!ref) return ''
	if (typeof ref === 'object') return String(ref.id || ref.uuid || '')
	return String(ref)
}

/**
 * The parent motion of an amendment: the ADR-005 `amends` link, or the
 * retired `parentMotion` on older records.
 *
 * @param {?object} amendment The amendment (a Decision of decisionType amendment).
 *
 * @return {string} The parent motion id, or ''.
 *
 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page
 */
export function parentMotionIdOf(amendment) {
	return referenceId(amendment?.amends ?? amendment?.parentMotion)
}

/**
 * The meeting a decision is linked to.
 *
 * @param {?object} decision A motion or other Decision.
 *
 * @return {string} The meeting id, or ''.
 *
 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page
 */
export function meetingIdOf(decision) {
	return referenceId(decision?.meeting)
}
