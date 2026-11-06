// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The steps of a planning cycle as the cycle page lists them
 * (planning-cycle-generate-from-template, pla-12): in sequence order, with
 * an overdue marker on a step whose delivery deadline has passed while it is
 * not adopted or completed (the same rule as the cycle's overdueStepCount).
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */

/**
 * Statuses after which a step is never overdue.
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */
export const TERMINAL_STATUSES = ['adopted', 'completed']

/**
 * A date as YYYY-MM-DD in local time.
 *
 * @param {Date} date The date.
 * @return {string} The day.
 */
function dayOf(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * Whether a step is overdue on the given day.
 *
 * @param {object} step A PlanningCycleStep.
 * @param {Date} [today] Today.
 * @return {boolean} True when its delivery deadline has passed and it is not done.
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */
export function isOverdue(step, today = new Date()) {
	const deadline = String(step?.deliveryDeadline || '').slice(0, 10)
	if (!deadline || TERMINAL_STATUSES.includes(step?.status)) return false
	return deadline < dayOf(today)
}

/**
 * The rows of the Steps widget, in sequence order.
 *
 * @param {Array<object>} steps The cycle's PlanningCycleSteps.
 * @param {Date} [today] Today.
 * @return {Array<object>} Rows with id, sequence, label, deadline, committee date, status and overdue.
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */
export function stepRows(steps, today = new Date()) {
	return (steps || [])
		.map((step) => ({
			id: step.id || step?.['@self']?.id || '',
			sequence: Number(step.sequence) || 0,
			label: step.label || step.stepType || '',
			deliveryDeadline: String(step.deliveryDeadline || '').slice(0, 10),
			committeeDate: String(step.committeeDate || '').slice(0, 10),
			status: step.status || 'planned',
			overdue: isOverdue(step, today),
		}))
		.sort((a, b) => a.sequence - b.sequence)
}
