// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'
import { useGovernanceBodyStore } from './modules/governanceBody.js'
import { useMeetingStore } from './modules/meeting.js'
import { useParticipantStore } from './modules/participant.js'
import { useAgendaItemStore } from './modules/agendaItem.js'

/**
 * Object types to register with OpenRegister on the default object store.
 * Core entity types (governanceBody, meeting, participant, agendaItem) are
 * excluded here — they are registered exclusively on their dedicated stores
 * (see ENTITY_STORES below) which carry the files, auditTrails, and relations
 * plugins required by the spec.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.2
 */
const OBJECT_TYPES = {
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
 * Core entity stores with their own schema/register config.
 * Each is registered exclusively on its dedicated store (not on the global
 * object store) so the plugins (files, auditTrails, relations) are active.
 */
const ENTITY_STORES = {
	governanceBody: { store: useGovernanceBodyStore, schema: 'governance-body', register: 'decidesk' },
	meeting: { store: useMeetingStore, schema: 'meeting', register: 'decidesk' },
	participant: { store: useParticipantStore, schema: 'participant', register: 'decidesk' },
	agendaItem: { store: useAgendaItemStore, schema: 'agenda-item', register: 'decidesk' },
}

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	// Register non-entity types on the default object store
	for (const [name, { schema, register }] of Object.entries(OBJECT_TYPES)) {
		objectStore.registerObjectType(name, schema, register)
	}

	// Register core entity types exclusively on their dedicated stores (with plugins)
	for (const [name, { store: storeFactory, schema, register }] of Object.entries(ENTITY_STORES)) {
		const store = storeFactory()
		store.registerObjectType(name, schema, register)
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
export { useGovernanceBodyStore } from './modules/governanceBody.js'
export { useMeetingStore } from './modules/meeting.js'
export { useParticipantStore } from './modules/participant.js'
export { useAgendaItemStore } from './modules/agendaItem.js'
