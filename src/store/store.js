// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'
import { useGovernanceBodyStore } from './modules/governanceBody.js'
import { useMeetingStore } from './modules/meeting.js'
import { useParticipantStore } from './modules/participant.js'
import { useAgendaItemStore } from './modules/agendaItem.js'

/**
 * Object types to register with OpenRegister.
 * Each entry maps a logical name to its schema slug, register slug, and target store.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.2
 */
const OBJECT_TYPES = {
	governanceBody: { schema: 'governance-body', register: 'decidesk' },
	meeting: { schema: 'meeting', register: 'decidesk' },
	participant: { schema: 'participant', register: 'decidesk' },
	agendaItem: { schema: 'agenda-item', register: 'decidesk' },
	motion: { schema: 'motion', register: 'decidesk' },
	amendment: { schema: 'amendment', register: 'decidesk' },
	votingRound: { schema: 'voting-round', register: 'decidesk' },
	vote: { schema: 'vote', register: 'decidesk' },
	decision: { schema: 'decision', register: 'decidesk' },
	actionItem: { schema: 'action-item', register: 'decidesk' },
	minutes: { schema: 'minutes', register: 'decidesk' },
	digitalDocument: { schema: 'digital-document', register: 'decidesk' },
	monetaryAmount: { schema: 'monetary-amount', register: 'decidesk' },
	offer: { schema: 'offer', register: 'decidesk' },
	order: { schema: 'order', register: 'decidesk' },
	product: { schema: 'product', register: 'decidesk' },
	report: { schema: 'report', register: 'decidesk' },
}

/**
 * Entity stores mapped by their type key for targeted registration.
 */
const ENTITY_STORES = {
	governanceBody: useGovernanceBodyStore,
	meeting: useMeetingStore,
	participant: useParticipantStore,
	agendaItem: useAgendaItemStore,
}

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	// Register all types on the default object store
	for (const [name, { schema, register }] of Object.entries(OBJECT_TYPES)) {
		objectStore.registerObjectType(name, schema, register)
	}

	// Also register the 4 core entities on their dedicated stores (with plugins)
	for (const [name, storeFactory] of Object.entries(ENTITY_STORES)) {
		const store = storeFactory()
		const { schema, register } = OBJECT_TYPES[name]
		store.registerObjectType(name, schema, register)
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
export { useGovernanceBodyStore } from './modules/governanceBody.js'
export { useMeetingStore } from './modules/meeting.js'
export { useParticipantStore } from './modules/participant.js'
export { useAgendaItemStore } from './modules/agendaItem.js'
