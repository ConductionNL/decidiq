// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
//
// Per ADR-022 ("Apps consume OpenRegister abstractions over local
// duplication") the canonical generic object store is the one shipped
// in @conduction/nextcloud-vue. Decidesk used to maintain its own
// fork at src/store/modules/object.js — that file has been removed.
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'
import { useMinutesStore } from './modules/minutes.js'
import { useDecisionStore } from './modules/decisions.js'
import { useActionItemStore } from './modules/actionItems.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	// The canonical store hardcodes its schema endpoint to
	// `/apps/openregister/api/schemas/{slug}` via prefixUrl, so only the
	// objects baseUrl needs configuring.
	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
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
