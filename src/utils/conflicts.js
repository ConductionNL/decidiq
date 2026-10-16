// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Conflict-of-interest declarations on a motion or agenda item page, and the
// recusals that keep a member out of the vote (bodies-conflict-of-interest-recusal, bod-10).
//
// @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page

/** Actions taken on a declaration that keep the member out of the vote. */
export const RECUSALS = ['recused-from-vote', 'recused-from-discussion']

/** The endpoint a member declares through. */
export const DECLARE_PATH = '/apps/decidiq/api/conflicts'

/**
 * The body posted when a member declares from a page. No membershipId: the
 * server records the declaration for the logged-in member.
 *
 * @param {object} input The dialog values
 * @param {string} input.subjectId The motion or agenda item UUID
 * @param {string} input.declarationType One of the schema's declaration types
 * @param {string} input.description The reason, in the member's words
 * @param {boolean} input.recuseFromVote Whether she stays out of the vote
 * @return {object} The request body
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
 */
export function declarationPayload({
	subjectId,
	declarationType,
	description,
	recuseFromVote,
}) {
	return {
		agendaItemId: String(subjectId || ''),
		declarationType: declarationType || 'financial-interest',
		description: String(description || '').trim(),
		severity: 'material',
		recuseFromVote: recuseFromVote === true,
	}
}

/**
 * Read a reference that is a bare uuid or an expanded object.
 *
 * @param {string|object|null} ref The reference
 * @return {string} The uuid, or ''
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
 */
export function refId(ref) {
	if (!ref) return ''
	if (typeof ref === 'string') return ref
	return String(ref.id || ref.uuid || '')
}

/**
 * Whether a declaration keeps its member out of the vote.
 *
 * @param {object} declaration The conflict-of-interest object
 * @return {boolean} True for a recusal
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
 */
export function isRecusal(declaration) {
	return RECUSALS.includes(declaration?.actionTaken)
}

/**
 * The declarations on any of the given subjects, once each, newest first.
 *
 * @param {Array<object>} declarations Conflict-of-interest objects
 * @param {Array<string>} subjectIds Motion and agenda item UUIDs
 * @return {Array<object>} The matching declarations
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
 */
export function declarationsFor(declarations, subjectIds) {
	const subjects = new Set((subjectIds || []).filter(Boolean))
	const seen = new Set()
	return (declarations || [])
		.filter((d) => subjects.has(refId(d?.agendaItem)))
		.filter((d) => {
			const key =
				d.id || d.uuid || `${refId(d.boardMember)}|${d.declarationTimestamp}`
			if (seen.has(key)) return false
			seen.add(key)
			return true
		})
		.sort((a, b) =>
			String(b.declarationTimestamp || '').localeCompare(
				String(a.declarationTimestamp || ''),
			),
		)
}

/**
 * How many members are recused on any of the given subjects.
 *
 * @param {Array<object>} declarations Conflict-of-interest objects
 * @param {Array<string>} subjectIds Motion and agenda item UUIDs
 * @return {number} Distinct recused members
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
 */
export function recusedMemberCount(declarations, subjectIds) {
	const members = new Set(
		declarationsFor(declarations, subjectIds)
			.filter(isRecusal)
			.map((d) => refId(d.boardMember))
			.filter(Boolean),
	)
	return members.size
}

/**
 * The members who may vote: the meeting's participants minus the recused.
 *
 * @param {number} participantCount Participants of the meeting
 * @param {Array<object>} declarations Conflict-of-interest objects
 * @param {Array<string>} subjectIds Motion and agenda item UUIDs
 * @return {number} The eligible count, never below zero
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
 */
export function eligibleCount(participantCount, declarations, subjectIds) {
	return Math.max(
		0,
		(participantCount || 0) - recusedMemberCount(declarations, subjectIds),
	)
}
