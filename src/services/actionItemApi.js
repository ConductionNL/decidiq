/**
 * Action-item write API client.
 *
 * Action items are CalDAV VTODOs exposed as a READ-ONLY OpenRegister projection
 * (action-items-vtodo-deck-reconcile), so the generic object API rejects writes.
 * Reads still use useObjectStore (the projection); these wrappers handle the
 * VTODO create/update/delete via the decidesk action-item endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/specs/action-item-board-via-deck-leaf/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/decidesk/api/action-items'

/**
 * Create an action item (as a VTODO).
 *
 * @param {object} payload The action-item fields (title, assignee, dueDate, …).
 * @return {Promise<object>} The created action item.
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-2.x
 */
export async function createActionItem(payload) {
	const { data } = await axios.post(generateUrl(base), payload)
	return data
}

/**
 * Update an action item (located by its VTODO uid).
 *
 * @param {string} uid The action item's VTODO uid.
 * @param {object} changes The fields to change.
 * @return {Promise<object>} The updated action item.
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
 */
export async function updateActionItem(uid, changes) {
	const { data } = await axios.put(generateUrl(`${base}/${encodeURIComponent(uid)}`), changes)
	return data
}

/**
 * Delete an action item (located by its VTODO uid).
 *
 * @param {string} uid The action item's VTODO uid.
 * @return {Promise<object>} The delete result.
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
 */
export async function deleteActionItem(uid) {
	const { data } = await axios.delete(generateUrl(`${base}/${encodeURIComponent(uid)}`))
	return data
}
