// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Decidesk store — thin wrapper around @conduction/nextcloud-vue's shared
// object store, plus the decidesk-specific settings store.
//
// The custom Pinia object store that previously lived in
// src/store/modules/object.js (with `fetchObjects`, no `subscribe`,
// no `fetchObject`, no `getSchema`) was a divergence from the rest of
// the Conduction app suite and the source of issue #162: LiveMeeting,
// AmendmentList, AgendaBuilder, VotingRoundPanel and GlobalSearch all
// expected lib semantics, called methods the local store never
// implemented, and silently failed at runtime via try/catch.
//
// The lib's `createObjectStore` provides the full CRUD surface plus a
// plugin system. We register `liveUpdatesPlugin()` here so that
// `subscribe(type, id?)` / `unsubscribe(handle)` are real methods in
// LiveMeeting; no other plugins are needed at this point — files /
// audit-trails / relations live behind the per-tab CnObjectSidebar
// integration which uses the lib's own default store id.
//
// Pinia store id `'decidesk-objects'` is unique to this app so that a
// future change which mounts both decidesk and an embedded openregister
// sidebar in the same Pinia tree can't collide on the default
// `'conduction-objects'` id.
//
// @spec openspec/changes/decidesk-store-migration/specs/decidesk-store-migration/spec.md#REQ-DSM-1
// @spec openspec/changes/decidesk-store-migration/specs/decidesk-store-migration/spec.md#REQ-DSM-2

import { generateUrl } from '@nextcloud/router'
import { createObjectStore, liveUpdatesPlugin } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'

/**
 * Shared object store for all decidesk OpenRegister CRUD.
 *
 * @type {import('pinia').StoreDefinition}
 */
export const useObjectStore = createObjectStore('decidesk-objects', {
	plugins: [liveUpdatesPlugin()],
	baseUrl: generateUrl('/apps/openregister/api/objects'),
})

/**
 * Boot hook called from App.vue and AdminRoot.vue. Loads decidesk's
 * settings (register slug, schema slug overrides, isAdmin flag), then
 * registers every logical object type the consumer Vue files use
 * against the shared lib store.
 *
 * Called twice (once from each entry-point Vue) is harmless — Pinia's
 * `defineStore` is idempotent, settings.fetchSettings() is safe to
 * re-await, and `registerObjectType()` overwrites with the same
 * payload.
 *
 * @return {Promise<{settingsStore: object, objectStore: object}>}
 *
 * @spec openspec/changes/decidesk-store-migration/specs/decidesk-store-migration/spec.md#REQ-DSM-3
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	const settings = settingsStore.getSettings || {}
	const register = settings.register || 'decidesk'

	// Register every logical type the app actually fetches/subscribes.
	// Pre-migration this map only covered minutes/decision/action-item
	// (the three from the p2-minutes-and-decisions change). LiveMeeting,
	// AgendaBuilder, AmendmentList, VotingRoundPanel and GlobalSearch
	// were silently fetching against unregistered types — the local
	// store warned and returned [], the lib store throws (which is the
	// right behaviour, surfaces typos at dev time).
	const types = [
		['minutes', settings.minutesSchema || 'minutes'],
		['decision', settings.decisionSchema || 'decision'],
		['action-item', settings.actionItemSchema || 'action-item'],
		['meeting', settings.meetingSchema || 'meeting'],
		['agenda-item', settings.agendaItemSchema || 'agenda-item'],
		['participant', settings.participantSchema || 'participant'],
		['motion', settings.motionSchema || 'motion'],
		['amendment', settings.amendmentSchema || 'amendment'],
		['voting-round', settings.votingRoundSchema || 'voting-round'],
		['governance-body', settings.governanceBodySchema || 'governance-body'],
		['vote', settings.voteSchema || 'vote'],
	]

	for (const [type, schema] of types) {
		objectStore.registerObjectType(type, schema, register)
	}

	return { settingsStore, objectStore }
}

export { useSettingsStore }
