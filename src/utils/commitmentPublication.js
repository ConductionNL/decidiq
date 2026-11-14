// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Publishing, withdrawing and settling a commitment (toezeggingen-ingekomen-stukken,
 * Task 7). The public list reads the live commitment while its publication date
 * has passed and its depublication date has not, so publish and withdraw are
 * nothing more than setting those two dates. Settling moves the commitment to
 * its terminal `disposed` state with a note and optional evidence; the lifecycle
 * map on the schema still guards the transition server-side.
 *
 * Every change is a whole object (PUT): these helpers return the commitment with
 * the changed fields, never a partial patch, so no field is lost on save.
 *
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
 */

/** The state a commitment is settled from (the schema's only path to `disposed`). */
export const SETTLE_FROM = 'in-execution'

/** The terminal state a settled commitment ends in. */
export const SETTLED = 'disposed'

/** The longest settlement note accepted in the dialog. */
export const MAX_SETTLEMENT_NOTE_LENGTH = 4000

/**
 * The time of a stored date, or null when the value is not a date.
 *
 * @param {string|null|undefined} value The stored value
 * @return {number|null} Milliseconds since the epoch, or null
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
 */
function timeOf(value) {
	if (typeof value !== 'string' || value === '') return null
	const time = Date.parse(value)
	return Number.isNaN(time) ? null : time
}

/**
 * Where the commitment stands on the public list at a moment.
 *
 * - `unpublished`: no publication date, it was never published
 * - `scheduled`: the publication date lies in the future
 * - `published`: publicly readable now
 * - `withdrawn`: a depublication date has passed
 *
 * @param {object|null|undefined} commitment The commitment
 * @param {Date} [now] The moment to judge at
 * @return {'unpublished'|'scheduled'|'published'|'withdrawn'} The state
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
 */
export function publicationState(commitment, now = new Date()) {
	const at = now.getTime()
	const published = timeOf(commitment?.publicationDate)
	const withdrawn = timeOf(commitment?.depublicationDate)
	if (withdrawn !== null && withdrawn <= at) return 'withdrawn'
	if (published === null) return 'unpublished'
	return published <= at ? 'published' : 'scheduled'
}

/**
 * The commitment published from now on. A withdrawn commitment is published
 * again by leaving its depublication date out of the saved object.
 *
 * @param {object} commitment The commitment
 * @param {Date} [now] The moment of publication
 * @return {object} The whole commitment to save
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
 */
export function published(commitment, now = new Date()) {
	// eslint-disable-next-line no-unused-vars -- dropped on purpose: the schema types it as a date-time string, so it is left out rather than nulled
	const { depublicationDate, ...rest } = commitment
	return {
		...rest,
		publicationDate: now.toISOString(),
	}
}

/**
 * The commitment withdrawn from the public list from now on. The publication
 * date stays, so the record shows when it was public.
 *
 * @param {object} commitment The commitment
 * @param {Date} [now] The moment of withdrawal
 * @return {object} The whole commitment to save
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
 */
export function withdrawn(commitment, now = new Date()) {
	return {
		...commitment,
		depublicationDate: now.toISOString(),
	}
}

/**
 * Whether the commitment can be settled now.
 *
 * @param {object|null|undefined} commitment The commitment
 * @return {boolean} True in the state the schema settles from
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-002-lifecycle-is-declarative-with-four-states
 */
export function canSettle(commitment) {
	return commitment?.lifecycle === SETTLE_FROM
}

/**
 * Whether a settlement note is acceptable: some text, not too long.
 *
 * @param {string|null|undefined} note The note typed in the dialog
 * @return {boolean} True when it can be saved
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-002-lifecycle-is-declarative-with-four-states
 */
export function settlementNoteIsValid(note) {
	if (typeof note !== 'string') return false
	const trimmed = note.trim()
	return trimmed !== '' && trimmed.length <= MAX_SETTLEMENT_NOTE_LENGTH
}

/**
 * The commitment settled with its note and evidence. Evidence is optional; an
 * empty one leaves the stored evidence as it was.
 *
 * @param {object} commitment The commitment, in `in-execution`
 * @param {{note: string, evidence?: string}} settlement What the dialog gave
 * @return {object} The whole commitment to save
 * @throws {Error} When the commitment cannot be settled or the note is invalid
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-002-lifecycle-is-declarative-with-four-states
 */
export function settled(commitment, settlement) {
	if (!canSettle(commitment)) {
		throw new Error(`A commitment is settled from ${SETTLE_FROM}, not from ${commitment?.lifecycle}`)
	}
	if (!settlementNoteIsValid(settlement?.note)) {
		throw new Error('A settlement needs a note')
	}
	const evidence = typeof settlement.evidence === 'string' ? settlement.evidence.trim() : ''
	return {
		...commitment,
		lifecycle: SETTLED,
		settlementNotes: settlement.note.trim(),
		...(evidence !== '' ? { settlementEvidence: evidence } : {}),
	}
}
