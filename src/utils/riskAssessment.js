// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the approver reads of a decision's risk score: the band, the score and
 * the properties that produced it. The server writes `riskAssessment` on every
 * save (DecisionRiskScoreListener); this only shapes it for the page. Pure, so
 * a unit test runs it; the widget is DecisionApprovalTab.vue.
 */

/**
 * A number as the page prints it, or null when it is not one.
 *
 * @param {number|string|boolean|null|undefined} value The value.
 * @return {number|null}
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */
function asNumber(value) {
	if (value === null || value === undefined || value === '' || typeof value === 'boolean') {
		return null
	}
	const number = Number(value)
	return Number.isFinite(number) ? number : null
}

/**
 * The basis rows of an assessment: each property the score was computed
 * from, with the value read and the points it added. A row without a
 * property name is left out; a missing value reads as empty text.
 *
 * @param {Array} basis The assessment's `basis`.
 * @return {Array<{property: string, value: string, points: number}>}
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */
export function riskBasisRows(basis) {
	if (!Array.isArray(basis)) {
		return []
	}
	return basis
		.filter((row) => row && typeof row === 'object' && typeof row.property === 'string' && row.property !== '')
		.map((row) => ({
			property: row.property,
			value: row.value === null || row.value === undefined ? '' : String(row.value),
			points: asNumber(row.points) ?? 0,
		}))
}

/**
 * The risk summary the approver sees, or null when the decision carries no
 * score (its type declares no expression, or it has not been saved since).
 *
 * @param {object|null} decision The decision object.
 * @return {{band: string, score: number, basis: Array}|null}
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */
export function riskSummary(decision) {
	const assessment = decision?.riskAssessment
	if (!assessment || typeof assessment !== 'object' || Array.isArray(assessment)) {
		return null
	}
	const score = asNumber(assessment.score)
	if (score === null) {
		return null
	}
	return {
		band: typeof assessment.band === 'string' ? assessment.band : '',
		score,
		basis: riskBasisRows(assessment.basis),
	}
}
