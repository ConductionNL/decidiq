// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the change recommendations of a motion
 * (motion-change-recommendations): ordering, the stale-passage check and the
 * request that decides one. The server decides whether a recommendation may
 * be accepted; these only shape what the page shows.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
 */

/** The statuses a recommendation can have. */
export const RECOMMENDATION_STATUSES = Object.freeze([
	'pending',
	'accepted',
	'rejected',
])

/**
 * The recommendations oldest first, by creation time and then id, so the
 * list reads in the order they were made.
 *
 * @param {Array<object>} rows The recommendations.
 *
 * @return {Array<object>} A sorted copy.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
 */
export function oldestFirst(rows) {
	const created = (row) => String(row?.['@self']?.created || row?.created || '')
	return [...(rows || [])].filter(Boolean).sort((a, b) => {
		const byTime = created(a).localeCompare(created(b))
		return byTime !== 0
			? byTime
			: String(a.id || '').localeCompare(String(b.id || ''))
	})
}

/**
 * Whether a pending recommendation's passage no longer occurs in the motion
 * text. A decided one is never stale: its decision stands.
 *
 * @param {object} recommendation The recommendation.
 * @param {string} motionText The motion's current text.
 *
 * @return {boolean} True when it can no longer be accepted.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-005-a-recommendation-whose-passage-has-changed-cannot-be-accepted
 */
export function isPassageChanged(recommendation, motionText) {
	if ((recommendation?.status || 'pending') !== 'pending') return false
	const passage = String(recommendation?.targetPassage || '')
	return passage === '' || !String(motionText || '').includes(passage)
}

/**
 * The line range a recommendation names, as a short label value: "4" or "4-5".
 *
 * @param {object} recommendation The recommendation.
 *
 * @return {string} The range, or '' without line numbers.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
 */
export function lineRange(recommendation) {
	const from = Number(recommendation?.lineFrom)
	const to = Number(recommendation?.lineTo)
	if (!Number.isInteger(from) || from < 1) return ''
	if (!Number.isInteger(to) || to <= from) return String(from)
	return `${from}-${to}`
}

/**
 * The app-relative path that accepts or rejects a recommendation.
 *
 * @param {string} id The recommendation id.
 * @param {'accept'|'reject'} action What to do.
 *
 * @return {string} The path to pass to generateUrl().
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 */
export function decisionPath(id, action) {
	const verb = action === 'accept' ? 'accept' : 'reject'
	return `/apps/decidiq/api/change-recommendations/${encodeURIComponent(id)}/${verb}`
}

/**
 * The new recommendation the dialog's answer becomes, linked to the motion.
 *
 * @param {string} motionId The motion id.
 * @param {{lineFrom: number, lineTo: number, targetPassage: string, proposedText: string, reason: string}} answer The dialog's answer.
 *
 * @return {object} The object to save.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording
 */
export function newRecommendation(motionId, answer) {
	return {
		motion: motionId,
		lineFrom: Number(answer.lineFrom),
		lineTo: Number(answer.lineTo),
		targetPassage: String(answer.targetPassage || ''),
		proposedText: String(answer.proposedText || ''),
		reason: String(answer.reason || '').trim(),
		status: 'pending',
	}
}
