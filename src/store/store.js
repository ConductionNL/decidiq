// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
//
// Per ADR-022 ("Apps consume OpenRegister abstractions over local
// duplication") the canonical generic object store is the one shipped
// in @conduction/nextcloud-vue. Decidesk used to maintain its own
// per-schema CRUD stores at src/store/modules/{minutes,decisions,
// meetings,actionItems}.js — those have been deleted in favour of
// useObjectStore for every CRUD path.
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'

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

	// Register every object type referenced by views/components. The
	// settings-overridable trio (minutes/decision/action-item) keeps
	// its schema-name override; the rest default to the type slug.
	// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
	objectStore.registerObjectType('minutes', settings.minutesSchema || 'minutes', register)
	objectStore.registerObjectType('decision', settings.decisionSchema || 'decision', register)
	objectStore.registerObjectType('action-item', settings.actionItemSchema || 'action-item', register)
	objectStore.registerObjectType('meeting', 'meeting', register)
	objectStore.registerObjectType('agenda-item', 'agenda-item', register)
	objectStore.registerObjectType('motion', 'motion', register)
	objectStore.registerObjectType('amendment', 'amendment', register)
	objectStore.registerObjectType('governance-body', 'governance-body', register)
	objectStore.registerObjectType('participant', 'participant', register)

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
