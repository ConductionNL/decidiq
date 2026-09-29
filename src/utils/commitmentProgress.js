// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Progress entries on a commitment (followup-public-progress, fol-06).
 *
 * The server writes each entry as { date: 'YYYY-MM-DD', note } and the public
 * ORI commitments resource serves exactly those two keys.
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */

/** The longest note the server accepts (CommitmentProgressService). */
export const MAX_NOTE_LENGTH = 2000

/**
 * The route that adds a progress entry.
 *
 * @param {string} commitmentId The commitment id
 * @return {string} The app-relative URL
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */
export function progressPath(commitmentId) {
	return `/apps/decidiq/api/commitments/${encodeURIComponent(commitmentId)}/progress`
}

/**
 * Entries newest first; entries without a date or note are left out.
 *
 * @param {Array|undefined} entries The stored entries
 * @return {Array<{date: string, note: string}>} The entries to show
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */
export function newestFirst(entries) {
	if (!Array.isArray(entries)) return []
	return entries
		.filter((e) => e && typeof e.date === 'string' && typeof e.note === 'string')
		.map((e) => ({ date: e.date, note: e.note }))
		.sort((a, b) => b.date.localeCompare(a.date))
}

/**
 * Whether a note can be sent: it says something and is not too long.
 *
 * @param {string} note The note
 * @return {boolean}
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */
export function noteIsValid(note) {
	const text = String(note ?? '').trim()
	return text.length > 0 && text.length <= MAX_NOTE_LENGTH
}
