// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The response and decision steps of a works-council consultation
 * (works-council-consultation task 6). Pure helpers: what the page offers in
 * which state, the decision outcomes per request type, the suspension term a
 * decision contrary to the advice starts, and the save that records the
 * decision with the move to decision-received.
 *
 * The suspension term itself is derived by OpenRegister on save
 * (x-openregister-calculations: decisionDate + 1 month); suspensionUntil()
 * only shows the secretary that date before saving, with the same rule.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 */

/** The decision outcomes per request type, as the schema names them. */
export const DECISION_OUTCOMES = {
	adviceRequest: ['in-line-with-advice', 'contrary-to-advice', 'not-pursued'],
	'consent-request': ['in-line-with-consent', 'not-pursued'],
}

/** The states in which the response is adopted, as the document service reads them. */
const ADOPTED = ['adopted', 'sent', 'decision-received', 'completed']

/**
 * The route that writes the response document.
 *
 * @param {string} id The traject id
 * @return {string} The app-relative URL
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 */
export function responseDocumentPath(id) {
	return `/apps/decidiq/api/works-council-consultations/${encodeURIComponent(id)}/response-document`
}

/**
 * The decision outcomes a request of this type can have.
 *
 * @param {string} type The request type
 * @return {string[]}
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 */
export function decisionOutcomes(type) {
	return DECISION_OUTCOMES[type] ?? []
}

/**
 * The end of the suspension term a decision starts: one month after the
 * decision date, only for an advice request decided contrary to the advice.
 * A day that the next month lacks becomes that month's last day.
 *
 * @param {string} type The request type
 * @param {string} outcome The decision outcome
 * @param {string} date The decision date, YYYY-MM-DD
 * @return {string|null} YYYY-MM-DD, or null when no term runs
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 */
export function suspensionUntil(type, outcome, date) {
	if (type !== 'adviceRequest' || outcome !== 'contrary-to-advice') return null
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(date ?? ''))
	if (!match) return null
	const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
	const nextYear = month === 12 ? year + 1 : year
	const nextMonth = month === 12 ? 1 : month + 1
	const lastDay = new Date(Date.UTC(nextYear, nextMonth, 0)).getUTCDate()
	const pad = (n) => String(n).padStart(2, '0')
	return `${nextYear}-${pad(nextMonth)}-${pad(Math.min(day, lastDay))}`
}

/**
 * What the page offers on a traject in its current state.
 *
 * @param {object|null} traject The traject
 * @return {{recordDecision: boolean, writeDocument: boolean, constituencyConsultation: string|null, relatedDecision: string|null}}
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 */
export function nextSteps(traject) {
	const lifecycle = traject?.lifecycle
	return {
		recordDecision: lifecycle === 'sent',
		writeDocument: ADOPTED.includes(lifecycle) && Boolean(traject?.responseOutcome),
		constituencyConsultation: traject?.constituencyConsultation || null,
		relatedDecision: traject?.relatedDecision || null,
	}
}

/**
 * The traject as saved when the director's decision is recorded: every field
 * kept, the three decision fields set, and the move to decision-received.
 *
 * @param {object} traject The traject as read
 * @param {{outcome: string, text: string, date: string}} decision The decision
 * @return {object} The object to save
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 */
export function decisionPayload(traject, decision) {
	const payload = { ...traject }
	delete payload['@self']
	delete payload.suspensionTo
	return {
		...payload,
		decisionOutcome: decision.outcome,
		decisionText: String(decision.text ?? '').trim() || null,
		decisionDate: decision.date,
		lifecycle: 'decision-received',
	}
}

/**
 * Whether a decision can be saved: an outcome the type allows and a date.
 *
 * @param {string} type The request type
 * @param {{outcome: string, date: string}} decision The decision
 * @return {boolean}
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 */
export function decisionIsComplete(type, decision) {
	return decisionOutcomes(type).includes(decision?.outcome) && /^\d{4}-\d{2}-\d{2}$/.test(String(decision?.date ?? ''))
}
