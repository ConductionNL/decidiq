/**
 * Store initialization for Decidesk.
 *
 * Registers all 17 entity types with the object store so Vue views
 * can query OpenRegister for each schema.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
 */
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/**
 * All Decidesk entity types mapped to their OpenRegister schema slugs.
 * Register slug is always 'decidesk'.
 */
const ENTITY_TYPES = {
	governanceBody: 'governance-body',
	participant: 'participant',
	meeting: 'meeting',
	agendaItem: 'agenda-item',
	motion: 'motion',
	amendment: 'amendment',
	votingRound: 'voting-round',
	vote: 'vote',
	decision: 'decision',
	actionItem: 'action-item',
	minutes: 'minutes',
	digitalDocument: 'digital-document',
	monetaryAmount: 'monetary-amount',
	offer: 'offer',
	order: 'order',
	product: 'product',
	report: 'report',
}

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	Object.entries(ENTITY_TYPES).forEach(([type, schemaSlug]) => {
		objectStore.registerObjectType(type, schemaSlug, 'decidesk')
	})

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
