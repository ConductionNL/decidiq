// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// urgency — the client side of the urgent decision procedure: what the
// decision detail banner shows, read from the decision and its route, and the
// two guarded action endpoints. The server decides who may act; these calls
// only report what it answered.
//
// @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md

import { generateUrl } from '@nextcloud/router'

/**
 * Stage states after which a ratification no longer waits.
 */
const TERMINAL_STAGE_STATES = ['decided', 'skipped']

/**
 * The banner state of a decision.
 *
 * `awaiting` follows the materialised `awaitingRatification` when the server
 * sent it, and otherwise derives it from the route the same way (an urgent
 * decision with a ratifying stage not yet decided or skipped).
 *
 * @param {object|null} decision The decision.
 * @param {Array<object>} stages Its route stages.
 * @return {{urgent: boolean, awaiting: boolean, stage: object|null, placementPending: boolean}} The state.
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-006-awaiting-ratification-indicators-and-dashboard-kpi
 */
export function urgencyState(decision, stages = []) {
	const urgent = decision?.isUrgent === true
	const stage = (stages || []).find((s) => s?.stageType === 'ratifying') || null
	const open = stage !== null && !TERMINAL_STAGE_STATES.includes(stage.status || 'pending')
	const awaiting = typeof decision?.awaitingRatification === 'boolean'
		? urgent && decision.awaitingRatification
		: urgent && open
	return {
		urgent,
		awaiting,
		stage,
		placementPending: awaiting && stage?.placementPending === true,
	}
}

/**
 * POST to one of the urgent procedure endpoints.
 *
 * @param {string} decisionId The decision.
 * @param {string} action `urgency` or `expedited-round`.
 * @param {object} body The JSON body.
 * @return {Promise<{ok: boolean, data: object}>} Whether it succeeded, and the answer.
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
 */
export async function postUrgentAction(decisionId, action, body) {
	const res = await fetch(
		generateUrl(`/apps/decidiq/api/decisions/${encodeURIComponent(decisionId)}/${action}`),
		{
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				requesttoken: globalThis.OC?.requestToken,
			},
			body: JSON.stringify(body),
		},
	)
	let data
	try {
		data = await res.json()
	} catch {
		data = null
	}
	return { ok: res.ok, data: data || {} }
}
