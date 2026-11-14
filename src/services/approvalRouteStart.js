// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Send a proposal along an approval route from its page
// (routes-send-proposal-along-route, REQ-RSPR-001).
//
// The routes are read from OpenRegister; starting one posts to decidiq's own
// controller, which checks that the caller may reach the decision before it
// writes a stage. The engine's refusal is shown as it comes back.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The schema slug a decision's route runs against.
 *
 * @type {string}
 */
export const DECISION_SCHEMA = 'decision'

/**
 * The rows of an OpenRegister list answer, whatever shape it came in.
 *
 * @param {object} data The response body.
 * @return {object[]} The rows.
 */
function rowsOf(data) {
	if (Array.isArray(data)) return data
	if (data && Array.isArray(data.results)) return data.results
	return []
}

/**
 * The active approval routes for a kind of subject, the default first, then by name.
 *
 * @param {string} subjectType The kind of subject, e.g. `decision`.
 * @return {Promise<object[]>} The routes.
 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
 */
export async function activeRoutesFor(subjectType) {
	const res = await axios.get(
		generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
			register: 'decidiq',
			schema: 'approval-route',
		}),
		{ params: { subjectType, _limit: 100 } },
	)
	return sortRoutes(
		rowsOf(res && res.data).filter(
			(route) => route && route.subjectType === subjectType && route.active === true,
		),
	)
}

/**
 * Routes with the default first, then by name.
 *
 * @param {object[]} routes The routes.
 * @return {object[]} A sorted copy.
 */
export function sortRoutes(routes) {
	return [...routes].sort((left, right) => {
		if (Boolean(left.isDefault) !== Boolean(right.isDefault)) {
			return left.isDefault ? -1 : 1
		}
		return String(left.name || '').localeCompare(String(right.name || ''))
	})
}

/**
 * Start a route on a decision.
 *
 * @param {object} route The route row, as read.
 * @param {string} decisionId The decision's UUID.
 * @return {Promise<object[]>} The stages the engine created.
 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
 */
export async function sendAlongRoute(route, decisionId) {
	const res = await axios.post(
		generateUrl('/apps/decidiq/api/approval-routes/instantiate'),
		{ route, subject: String(decisionId), subjectSchema: DECISION_SCHEMA },
	)
	const data = (res && res.data) || {}
	return Array.isArray(data.stages) ? data.stages : []
}

/**
 * The sentence to show when starting a route failed: the engine's own reason when it gave one.
 *
 * @param {Error} error The failure.
 * @param {string} fallback The sentence without a reason.
 * @return {string} The sentence.
 */
export function failureMessage(error, fallback) {
	const message = error && error.response && error.response.data && error.response.data.message
	return typeof message === 'string' && message !== '' ? message : fallback
}
