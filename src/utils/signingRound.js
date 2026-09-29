/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The signing order of a record's signers (min-17). A record carries
 * `signers`: participant references, each with an `order` (1 signs first)
 * and a `signedAt` once signed. Older minutes hold bare participant ids;
 * they read as signers in the order they were added.
 *
 * @spec openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */

/**
 * The participant id of one signer entry.
 *
 * @param {string|object} entry A participant id or a signer object
 * @return {string}
 */
function participantOf(entry) {
	if (typeof entry === 'string') return entry
	return (entry && (entry.participant || entry.id || entry.uuid)) || ''
}

/**
 * The signers sorted on their order and renumbered 1..n. Entries without an
 * order keep the place they were added, after the ordered ones.
 *
 * @param {Array<string|object>} entries The record's signers
 * @return {Array<{participant: string, order: number, signedAt?: string}>}
 */
export function orderedSigners(entries) {
	const rows = (Array.isArray(entries) ? entries : [])
		.map((entry, index) => ({
			participant: participantOf(entry),
			order: Number.isFinite(entry?.order) ? entry.order : Infinity,
			signedAt: typeof entry === 'object' ? entry?.signedAt : undefined,
			index,
		}))
		.filter((row) => row.participant)
	rows.sort((a, b) => a.order - b.order || a.index - b.index)
	return rows.map((row, position) => {
		const signer = { participant: row.participant, order: position + 1 }
		if (row.signedAt) signer.signedAt = row.signedAt
		return signer
	})
}

/**
 * Add a signer at the end of the order.
 *
 * @param {Array<string|object>} entries The record's signers
 * @param {string} participant The participant to add
 * @return {Array<object>}
 */
export function addSigner(entries, participant) {
	const current = orderedSigners(entries)
	if (!participant || current.some((s) => s.participant === participant)) return current
	return current.concat([{ participant, order: current.length + 1 }])
}

/**
 * Move a signer up (delta -1) or down (delta 1) in the order.
 *
 * @param {Array<string|object>} entries The record's signers
 * @param {string} participant The signer to move
 * @param {number} delta -1 moves up, 1 moves down
 * @return {Array<object>}
 */
export function moveSigner(entries, participant, delta) {
	const current = orderedSigners(entries)
	const from = current.findIndex((s) => s.participant === participant)
	const to = from + delta
	if (from < 0 || to < 0 || to >= current.length) return current
	const next = current.slice()
	const [moved] = next.splice(from, 1)
	next.splice(to, 0, moved)
	return next.map((signer, position) => ({ ...signer, order: position + 1 }))
}

/**
 * The signing endpoint of a record, before generateUrl().
 *
 * @param {string} subjectType minutes, decision-list or motion
 * @param {string} subjectId The record's id
 * @param {'send'|'collect'} action Send it, or collect the signed copy
 * @return {string}
 */
export function signingUrl(subjectType, subjectId, action) {
	return `/apps/decidiq/api/signing/${encodeURIComponent(subjectType)}/${encodeURIComponent(subjectId)}/${action}`
}
