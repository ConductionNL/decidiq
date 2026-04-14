// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore, filesPlugin, auditTrailsPlugin, relationsPlugin } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'

/**
 * Object store with files, auditTrails, and relations plugins.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.3
 */
export const useObjectStore = createObjectStore('decidesk-objects', {
	plugins: [filesPlugin(), auditTrailsPlugin(), relationsPlugin()],
})

/**
 * Object types to register with OpenRegister.
 * Each entry maps a logical slug to its schema slug and register slug.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.2
 */
const OBJECT_TYPES = {
	'governance-body': { schema: 'governance-body', register: 'decidesk' },
	meeting: { schema: 'meeting', register: 'decidesk' },
	participant: { schema: 'participant', register: 'decidesk' },
	'agenda-item': { schema: 'agenda-item', register: 'decidesk' },
	motion: { schema: 'motion', register: 'decidesk' },
	amendment: { schema: 'amendment', register: 'decidesk' },
	'voting-round': { schema: 'voting-round', register: 'decidesk' },
	vote: { schema: 'vote', register: 'decidesk' },
	decision: { schema: 'decision', register: 'decidesk' },
	'action-item': { schema: 'action-item', register: 'decidesk' },
	minutes: { schema: 'minutes', register: 'decidesk' },
	'digital-document': { schema: 'digital-document', register: 'decidesk' },
	'monetary-amount': { schema: 'monetary-amount', register: 'decidesk' },
	offer: { schema: 'offer', register: 'decidesk' },
	order: { schema: 'order', register: 'decidesk' },
	product: { schema: 'product', register: 'decidesk' },
	report: { schema: 'report', register: 'decidesk' },
}

/**
 * Initialize all stores: fetch settings, then register entity types.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.2
 * @return {Promise<{settingsStore: object, objectStore: object}>}
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	if (settingsStore.hasOpenRegisters) {
		for (const [slug, { schema, register }] of Object.entries(OBJECT_TYPES)) {
			objectStore.registerObjectType(slug, schema, register)
		}
	}

	return { settingsStore, objectStore }
}

export { useSettingsStore }
