// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useMinutesStore } from './modules/minutes.js'
import { useDecisionStore } from './modules/decisions.js'
import { useActionItemStore } from './modules/actionItems.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	const settings = settingsStore.getSettings
	const register = settings.register || 'decidesk'

	// Register Minutes, Decision, and ActionItem object types.
	// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
	objectStore.registerObjectType('minutes', settings.minutesSchema || 'minutes', register)
	objectStore.registerObjectType('decision', settings.decisionSchema || 'decision', register)
	objectStore.registerObjectType('action-item', settings.actionItemSchema || 'action-item', register)

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore, useMinutesStore, useDecisionStore, useActionItemStore }
