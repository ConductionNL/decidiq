// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The member turnover run: an incoming roster (the member-import CSV) is
// diffed on the server against the body's active memberships; the
// secretariat deselects what should not happen and confirms. Nothing is
// created before the confirmation, and the server checks every confirmed
// suggestion again. @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import

import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * The roster rows the server matches on, from parsed member-CSV rows.
 *
 * @param {Array<{name: string, email: string, role: string}>} rows Parsed CSV rows.
 * @return {Array<{name: string, email: string, role: string}>} Rows with an email.
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
export function rosterRows(rows) {
	return (rows || [])
		.filter((row) => (row.email || '').trim() !== '')
		.map((row) => ({
			name: (row.name || '').trim(),
			email: row.email.trim(),
			role: (row.role || '').trim(),
		}))
}

/**
 * Every suggestion selected, as the dialog starts.
 *
 * @param {{joining: Array<{key: string}>, leaving: Array<{membershipId: string}>}} suggestions The server's suggestions.
 * @return {{joining: string[], leaving: string[]}} The selected keys.
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
export function selectAll(suggestions) {
	return {
		joining: (suggestions?.joining || []).map((s) => s.key),
		leaving: (suggestions?.leaving || []).map((s) => s.membershipId),
	}
}

/**
 * The confirmation request body.
 *
 * @param {Array<object>} rows The roster rows that were diffed.
 * @param {{joining: string[], leaving: string[]}} selection The selected suggestions.
 * @param {{batch: string, endDate: string, endReason?: string}} options The batch label and the leavers' end date.
 * @return {object} The request body.
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
export function confirmPayload(rows, selection, options) {
	return {
		rows,
		joining: [...selection.joining],
		leaving: [...selection.leaving],
		batch: (options.batch || '').trim(),
		endDate: selection.leaving.length > 0 ? options.endDate || '' : '',
		endReason: (options.endReason || '').trim(),
	}
}

/**
 * Whether the confirmation can be sent: something selected, a batch label,
 * and an end date when anyone leaves.
 *
 * @param {{joining: string[], leaving: string[]}} selection The selected suggestions.
 * @param {{batch: string, endDate: string}} options The batch label and end date.
 * @return {boolean} True when the request is complete.
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
export function canConfirm(selection, options) {
	const count = selection.joining.length + selection.leaving.length
	if (count === 0 || (options.batch || '').trim() === '') {
		return false
	}
	return selection.leaving.length === 0 || /^\d{4}-\d{2}-\d{2}$/.test(options.endDate || '')
}

/**
 * POST to one of the turnover endpoints of a body.
 *
 * @param {string} bodyId The governance body id.
 * @param {string} action `suggestions` or `confirm`.
 * @param {object} body The request body.
 * @return {Promise<object>} The response data.
 * @throws {Error} With the server's message on a non-2xx answer.
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
export async function postTurnover(bodyId, action, body) {
	const response = await fetch(
		generateUrl('/apps/decidiq/api/governance-bodies/{bodyId}/turnover/{action}', { bodyId, action }),
		{
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: getRequestToken() },
			body: JSON.stringify(body),
		},
	)
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data?.message || String(response.status))
	}
	return data
}
