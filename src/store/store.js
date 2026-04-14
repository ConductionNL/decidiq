// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Store initialization — fetches settings and registers all object types.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.2
 */

import { useSettingsStore } from './modules/settings.js'
import { useGovernanceBodyStore } from './modules/governanceBody.js'
import { useMeetingStore } from './modules/meeting.js'
import { useParticipantStore } from './modules/participant.js'
import { useAgendaItemStore } from './modules/agendaItem.js'

/**
 * Object types to register with OpenRegister.
 * Each entry maps a store factory to its schema slug and register slug.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */
const OBJECT_TYPES = [
	{ store: useGovernanceBodyStore, name: 'governanceBody', schema: 'governance-body', register: 'decidesk' },
	{ store: useMeetingStore, name: 'meeting', schema: 'meeting', register: 'decidesk' },
	{ store: useParticipantStore, name: 'participant', schema: 'participant', register: 'decidesk' },
	{ store: useAgendaItemStore, name: 'agendaItem', schema: 'agenda-item', register: 'decidesk' },
]

export async function initializeStores() {
	const settingsStore = useSettingsStore()

	await settingsStore.fetchSettings()

	for (const { store, name, schema, register } of OBJECT_TYPES) {
		const instance = store()
		instance.registerObjectType(name, schema, register)
	}

	return { settingsStore }
}

export {
	useSettingsStore,
	useGovernanceBodyStore,
	useMeetingStore,
	useParticipantStore,
	useAgendaItemStore,
}
