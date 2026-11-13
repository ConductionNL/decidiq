/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The phase of an agenda item (agenda-item-phase-is-declared, REQ-LIV-010):
 * forming a picture (beeldvorming), forming an opinion (oordeelsvorming),
 * deciding (besluitvorming). The phase lives in the declared `phase`; an item
 * from before the upgrade may still carry it in the undeclared `status`,
 * which is read as a fallback until MoveAgendaItemStatusToPhase has run. The
 * live meeting screen and the agenda item page read it through here, so the
 * two never disagree.
 *
 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md
 */

/** Every value AgendaItem.phase declares, in order. */
export const AGENDA_PHASES = ['voorstel', 'beeldvorming', 'oordeelsvorming', 'besluitvorming', 'completed']

/** The three stages the stage line draws; the labels are l10n keys. */
export const AGENDA_PHASE_STAGES = [
	{ id: 'beeldvorming', label: 'Beeldvorming' },
	{ id: 'oordeelsvorming', label: 'Oordeelsvorming' },
	{ id: 'besluitvorming', label: 'Besluitvorming' },
]

/** The phase after which an item cannot be advanced. */
export const AGENDA_PHASE_FINAL = 'completed'

/**
 * The phase an item is in: `phase`, else a phase held in the legacy
 * `status`, else beeldvorming.
 *
 * @param {object|null} item The agenda item.
 * @return {string} The phase.
 */
export function phaseOf(item) {
	for (const key of ['phase', 'status']) {
		const value = item?.[key]
		if (typeof value === 'string' && AGENDA_PHASES.includes(value)) {
			return value
		}
	}
	return 'beeldvorming'
}

/**
 * Whether an item has a phase at all: an informational item has none.
 *
 * @param {object|null} item The agenda item.
 * @return {boolean} True for a discussion or decision item.
 */
export function hasPhase(item) {
	return Boolean(item) && item.itemType !== 'informational'
}

/**
 * The index of the stage to mark current on the stage line: a proposal sits
 * before the first stage (0), a completed item past the last (3, every stage
 * done).
 *
 * @param {object|null} item The agenda item.
 * @return {number} The index.
 */
export function phaseStageIndex(item) {
	const phase = phaseOf(item)
	if (phase === AGENDA_PHASE_FINAL) {
		return AGENDA_PHASE_STAGES.length
	}
	const index = AGENDA_PHASE_STAGES.findIndex((stage) => stage.id === phase)
	return index === -1 ? 0 : index
}

/**
 * Whether the chair can still move the item to its next phase.
 *
 * @param {object|null} item The agenda item.
 * @return {boolean} True when it has a phase and is not completed.
 */
export function canAdvancePhase(item) {
	return hasPhase(item) && phaseOf(item) !== AGENDA_PHASE_FINAL
}
