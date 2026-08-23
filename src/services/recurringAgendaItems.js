/**
 * Read-only client for the reusable "recurring" agenda-item templates.
 *
 * WHY THIS IS NOT `objectStore.fetchCollection('agenda-item', …)`
 * --------------------------------------------------------------
 * `@conduction/nextcloud-vue`'s object store keeps ONE collection slot per
 * registered type. `fetchCollection()` ends with
 *
 *     this.collections = { ...this.collections, [type]: results }
 *
 * so two differently-filtered reads of the same type race, and the last
 * response wins for every consumer of that type.
 *
 * AgendaBuilder is mounted by LiveMeeting for the chair only, and its
 * `created()` hook fired `agenda-item?isRecurring=true` immediately after
 * LiveMeeting's own `agenda-item?meeting=<id>`. The recurring response landed
 * second and replaced the meeting's agenda in the shared cache with templates
 * belonging to other meetings, so `LiveMeeting.allItems` filtered everything
 * away: the chair got an empty agenda and an empty "Activate item" list, while
 * a non-chair — who never mounts AgendaBuilder — saw the agenda correctly.
 *
 * The recurring templates are consumed by exactly one dialog and are never
 * shared, so this read is deliberately kept out of the store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useSettingsStore } from '../store/modules/settings.js'

/**
 * Fetch every agenda item flagged as a recurring template.
 *
 * Register and schema slugs are resolved from the decidiq settings exactly as
 * `initializeStores()` resolves them, so an instance that overrides either one
 * keeps working.
 *
 * @param {number} [limit] Maximum number of templates to return.
 * @return {Promise<object[]>} The recurring agenda items (never null).
 * @spec openspec/specs/agenda-management/spec.md
 */
export async function fetchRecurringAgendaItems(limit = 200) {
	const settings = useSettingsStore().getSettings || {}
	const register = settings.register || 'decidesk'
	const schema = settings.agendaItemSchema || 'agenda-item'

	const { data } = await axios.get(
		generateUrl(
			`/apps/openregister/api/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`,
		),
		// `_limit` is the page size; a bare `limit` is treated as a property
		// filter by OpenRegister and silently matches nothing.
		{ params: { isRecurring: true, _limit: limit } },
	)

	return data?.results ?? (Array.isArray(data) ? data : [])
}
