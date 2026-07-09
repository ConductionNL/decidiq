/**
 * Board self-evaluation ACTION API client.
 *
 * Thin wrappers over the decidesk board-evaluation action endpoints. Plain
 * object reads/writes (creating a BoardEvaluation, listing cycles, editing an
 * EvaluationTemplate) use useObjectStore (the OpenRegister object API) per
 * ADR-022 — only the anonymous-respond / close / publish / report ACTIONS,
 * which carry server-side side-effects (anonymity token derivation, scoring,
 * publication, document generation), live here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/decidesk/api/board-evaluations'

/**
 * Submit the authenticated user's anonymous response to an evaluation cycle.
 * The server derives the participant identity from the session and never
 * persists it on the response content.
 *
 * @param {string} evaluationId The BoardEvaluation UUID.
 * @param {Array<object>} answers Each: {questionId, dimension, likertValue?, freeText?}.
 * @return {Promise<object>} The created (anonymous) response.
 * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
 */
export async function respondToEvaluation(evaluationId, answers) {
	const { data } = await axios.post(generateUrl(`${base}/${evaluationId}/respond`), { answers })
	return data
}

/**
 * Close an open evaluation cycle: computes and materialises the score summary.
 *
 * @param {string} evaluationId The BoardEvaluation UUID.
 * @return {Promise<object>} The updated (closed) evaluation.
 * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
 */
export async function closeEvaluation(evaluationId) {
	const { data } = await axios.post(generateUrl(`${base}/${evaluationId}/close`))
	return data
}

/**
 * Publish a closed evaluation's aggregate summary through the existing
 * publication stack. Raw responses are never published.
 *
 * @param {string} evaluationId The BoardEvaluation UUID.
 * @return {Promise<object>} The publication result.
 * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
 */
export async function publishEvaluation(evaluationId) {
	const { data } = await axios.post(generateUrl(`${base}/${evaluationId}/publish`))
	return data
}

/**
 * Generate the evaluation report document (markdown canonical, Docudesk PDF
 * where present) via the existing minutes/document generation path.
 *
 * @param {string} evaluationId The BoardEvaluation UUID.
 * @return {Promise<object>} {path, format, docudesk, note?}.
 * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
 */
export async function generateEvaluationReport(evaluationId) {
	const { data } = await axios.post(generateUrl(`${base}/${evaluationId}/report`))
	return data
}
