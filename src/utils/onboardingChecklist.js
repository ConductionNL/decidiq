// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The checklist of an onboarding or offboarding record
 * (bodies-onboarding-checklist-walkthrough, bod-08).
 *
 * The server marks a step (PUT /api/onboarding/{kind}/{id}/steps/{index}),
 * runs the membership effect of the installation and end steps, and moves the
 * record's lifecycle. These helpers only read the record and build the URL,
 * and mirror the server's skip rule so the page does not offer a button the
 * server refuses.
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */

/** The record kind per schema slug, as the route names it. */
export const KINDS = {
	'member-onboarding': 'onboarding',
	'member-offboarding': 'offboarding',
}

/** The step statuses that end a step. */
const DONE = ['completed', 'skipped']

/** The record states in which steps no longer change. */
const FINISHED = ['completed', 'lapsed']

/**
 * The route kind of a schema slug, or null when it is neither record.
 *
 * @param {string} schema The page's schema slug
 * @return {string|null} `onboarding`, `offboarding` or null
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
export function kindOf(schema) {
	return KINDS[schema] ?? null
}

/**
 * The route that marks one step.
 *
 * @param {string} kind `onboarding` or `offboarding`
 * @param {string} id The record id
 * @param {number} index The step's position
 * @return {string} The app-relative URL
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
export function stepPath(kind, id, index) {
	return `/apps/decidiq/api/onboarding/${encodeURIComponent(kind)}/${encodeURIComponent(id)}/steps/${Number(index)}`
}

/**
 * Whether a step is mandatory: as the server reads it, the default is yes and
 * an offboarding's revoke-groups step always is.
 *
 * @param {string} kind `onboarding` or `offboarding`
 * @param {object} step The step
 * @return {boolean}
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
export function isMandatory(kind, step) {
	if (kind === 'offboarding' && step?.stepType === 'revoke-groups') return true
	return step?.mandatory !== false
}

/**
 * The steps to show, in stored order, each with its index and the actions
 * still open on it.
 *
 * @param {string} kind `onboarding` or `offboarding`
 * @param {object|null} record The record
 * @return {Array<{index: number, title: string, status: string, mandatory: boolean, canComplete: boolean, canSkip: boolean}>}
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
export function checklistRows(kind, record) {
	const steps = Array.isArray(record?.steps) ? record.steps : []
	const finished = FINISHED.includes(record?.lifecycle)
	return steps
		.map((step, index) => ({ step, index }))
		.filter(({ step }) => step && typeof step === 'object')
		.map(({ step, index }) => {
			const status = typeof step.status === 'string' ? step.status : 'open'
			const mandatory = isMandatory(kind, step)
			const open = !finished && !DONE.includes(status)
			return {
				index,
				title: String(step.title ?? step.stepType ?? ''),
				status,
				mandatory,
				canComplete: open,
				canSkip: open && !mandatory,
			}
		})
}
