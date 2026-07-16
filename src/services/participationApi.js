/**
 * Citizen-participation ACTION API client.
 *
 * Thin wrappers over the decidesk participation action endpoints. Plain object
 * reads/writes use useObjectStore (the OpenRegister object API) per ADR-022 —
 * only the lifecycle/intake/moderation/voting/publication ACTIONS live here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/decidesk/api/participation'

/**
 * Transition a consultation to a new lifecycle status.
 *
 * @param {string} consultationId The consultation UUID.
 * @param {string} status The target status.
 * @return {Promise<object>} The updated consultation.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function transitionConsultation(consultationId, status) {
	const { data } = await axios.post(generateUrl(`${base}/consultations/${consultationId}/transition`), { status })
	return data
}

/**
 * Submit a reaction as the authenticated user.
 *
 * @param {string} consultationId The consultation UUID.
 * @param {string} body The reaction text.
 * @return {Promise<object>} The created reaction.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function submitReaction(consultationId, body) {
	const { data } = await axios.post(generateUrl(`${base}/consultations/${consultationId}/reactions`), { body })
	return data
}

/**
 * Approve a pending reaction.
 *
 * @param {string} reactionId The reaction UUID.
 * @param {string} reason Optional moderation note.
 * @return {Promise<object>} The updated reaction.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function approveReaction(reactionId, reason = '') {
	const { data } = await axios.post(generateUrl(`${base}/reactions/${reactionId}/approve`), { reason })
	return data
}

/**
 * Reject a pending reaction with a reason.
 *
 * @param {string} reactionId The reaction UUID.
 * @param {string} reason The rejection reason.
 * @return {Promise<object>} The updated reaction.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function rejectReaction(reactionId, reason) {
	const { data } = await axios.post(generateUrl(`${base}/reactions/${reactionId}/reject`), { reason })
	return data
}

/**
 * Publish a consultation's PII-free results summary.
 *
 * @param {string} consultationId The consultation UUID.
 * @param {string} staffResponse The staff response text.
 * @return {Promise<object>} The publication result.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function publishConsultationResults(consultationId, staffResponse = '') {
	const { data } = await axios.post(generateUrl(`${base}/consultations/${consultationId}/publish`), { staffResponse })
	return data
}

/**
 * Transition a participatory-budget round.
 *
 * @param {string} budgetId The round UUID.
 * @param {string} status The target status.
 * @return {Promise<object>} The updated round.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function transitionBudgetRound(budgetId, status) {
	const { data } = await axios.post(generateUrl(`${base}/budgets/${budgetId}/transition`), { status })
	return data
}

/**
 * Submit a budget proposal.
 *
 * @param {string} budgetId The round UUID.
 * @param {object} payload The proposal fields (title, description, amount, category).
 * @return {Promise<object>} The created proposal.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function submitProposal(budgetId, payload) {
	const { data } = await axios.post(generateUrl(`${base}/budgets/${budgetId}/proposals`), payload)
	return data
}

/**
 * Validate or reject a submitted proposal.
 *
 * @param {string} proposalId The proposal UUID.
 * @param {boolean} approve True to validate, false to reject.
 * @return {Promise<object>} The updated proposal.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function validateProposal(proposalId, approve) {
	const { data } = await axios.post(generateUrl(`${base}/proposals/${proposalId}/validate`), { approve })
	return data
}

/**
 * Cast an advisory vote on a validated proposal.
 *
 * @param {string} proposalId The proposal UUID.
 * @param {string} value 'voor' | 'tegen'.
 * @return {Promise<object>} The vote + updated tally.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function castAdvisoryVote(proposalId, value) {
	const { data } = await axios.post(generateUrl(`${base}/proposals/${proposalId}/vote`), { value })
	return data
}

/**
 * Publish a budget round's PII-free allocation results.
 *
 * @param {string} budgetId The round UUID.
 * @return {Promise<object>} The publication result.
 * @spec openspec/specs/citizen-participation/spec.md
 */
export async function publishBudgetResults(budgetId) {
	const { data } = await axios.post(generateUrl(`${base}/budgets/${budgetId}/publish`), {})
	return data
}
