import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/**
 * Object types to register with OpenRegister.
 * Each entry maps a logical name to its schema slug and register slug.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
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

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
	})

	await settingsStore.fetchSettings()

	for (const [name, { schema, register }] of Object.entries(OBJECT_TYPES)) {
		objectStore.registerObjectType(name, schema, register)
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
