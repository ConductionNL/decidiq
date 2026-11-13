// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Registered other positions (nevenfuncties) as assistive context on the
 * conflict-of-interest surfaces (interests-and-integrity, REQ-INT-007,
 * design D6). Display only: nothing here declares, warns, blocks or scores.
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
 */

/** The lifecycle state of a position that has ended. */
const ENDED = 'ended'

/** Words shorter than this carry no subject ("de", "van", "en"). */
const MIN_WORD_LENGTH = 4

/**
 * The words of a text, lower-cased, of at least MIN_WORD_LENGTH letters.
 *
 * @param {string} text The text.
 * @return {Set<string>} The words.
 */
export function wordsOf(text) {
	return new Set(
		String(text || '')
			.toLocaleLowerCase()
			.split(/[^\p{L}\p{N}]+/u)
			.filter((word) => word.length >= MIN_WORD_LENGTH),
	)
}

/**
 * Whether a position's organisation or function shares a word with the
 * subject title: a plain case-insensitive word overlap (design D6).
 *
 * @param {object} position The ancillary position.
 * @param {string} subjectTitle The agenda item's or motion's title.
 * @return {boolean} True when it matches the subject.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
 */
export function matchesSubject(position, subjectTitle) {
	const subject = wordsOf(subjectTitle)
	if (subject.size === 0) return false
	const own = wordsOf(`${position?.organisation || ''} ${position?.role || ''}`)
	return [...own].some((word) => subject.has(word))
}

/**
 * A member's positions that have not ended, as the dialog lists them, each
 * marked when it matches the subject. Matches first, then by organisation.
 *
 * @param {Array<object>} positions The member's ancillary positions.
 * @param {string} subjectTitle The agenda item's or motion's title.
 * @return {Array<{id: string, organisation: string, role: string, matches: boolean}>} The rows.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
 */
export function ancillaryContextRows(positions, subjectTitle) {
	return (positions || [])
		.filter((position) => position && position.lifecycle !== ENDED)
		.map((position) => ({
			id: String(position.id || ''),
			organisation: position.organisation || '',
			role: position.role || '',
			matches: matchesSubject(position, subjectTitle),
		}))
		.sort(
			(a, b) =>
				Number(b.matches) - Number(a.matches)
				|| a.organisation.localeCompare(b.organisation),
		)
}

/**
 * Whether any of a person's positions has not ended, so the chair's panel
 * links the declaring member to the register.
 *
 * @param {Array<object>} positions The person's ancillary positions.
 * @return {boolean} True when there is one to link to.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
 */
export function hasActivePositions(positions) {
	return (positions || []).some(
		(position) => position && position.lifecycle !== ENDED,
	)
}
