// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Decidiq store — thin wrapper around @conduction/nextcloud-vue's shared
// object store, plus the decidiq-specific settings store.
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
// Pinia store id `'decidiq-objects'` is unique to this app so that a
// future change which mounts both decidiq and an embedded openregister
// sidebar in the same Pinia tree can't collide on the default
// `'conduction-objects'` id.
//
// @spec openspec/specs/decidesk-store-migration/spec.md
// @spec openspec/specs/decidesk-store-migration/spec.md

import { createObjectStore, liveUpdatesPlugin } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { useSettingsStore } from './modules/settings.js'

/**
 * Shared object store for all decidiq OpenRegister CRUD.
 *
 * @type {import('pinia').StoreDefinition}
 */
export const useObjectStore = createObjectStore('decidiq-objects', {
	plugins: [liveUpdatesPlugin()],
	baseUrl: generateUrl('/apps/openregister/api/objects'),
})

// Register every logical type the app actually fetches/subscribes.
// Pre-migration this map only covered minutes/decision/action-item
// (the three from the p2-minutes-and-decisions change). LiveMeeting,
// AgendaBuilder, AmendmentList, VotingRoundPanel and GlobalSearch
// were silently fetching against unregistered types — the local
// store warned and returned [], the lib store throws (which is the
// right behaviour, surfaces typos at dev time).

/**
 * Every logical object type the app registers: the type, the settings key
 * that may override its schema slug, and the default slug.
 *
 * @type {Array<[string, string, string]>}
 */
const OBJECT_TYPES = [
	['minutes', 'minutesSchema', 'minutes'],
	['decision', 'decisionSchema', 'decision'],
	['action-item', 'actionItemSchema', 'action-item'],
	['meeting', 'meetingSchema', 'meeting'],
	['agenda-item', 'agendaItemSchema', 'agenda-item'],
	['participant', 'participantSchema', 'participant'],
	// ADR-005: motion and amendment are folded into the unified Decision
	// supertype (decisionType discriminator). The standalone Motion/Amendment
	// schemas were removed from decidesk_register.json, so the logical relation
	// types must resolve to the `decision` schema here too. Registering them to
	// the deleted 'motion'/'amendment' slugs would shadow the same remap in
	// useRelationStore.ensureRelationType (guarded by !objectTypeRegistry[type]),
	// causing the motion/amendment tabs to query dead schemas at runtime.
	['motion', 'decisionSchema', 'decision'],
	['amendment', 'decisionSchema', 'decision'],
	['voting-round', 'votingRoundSchema', 'voting-round'],
	['governance-body', 'governanceBodySchema', 'governance-body'],
	['vote', 'voteSchema', 'vote'],
	// meeting-efficiency: engagement records back the speaking-time
	// distribution on the GovernanceBodyEfficiencyTab analytics surface.
	['engagement-record', 'engagementRecordSchema', 'engagement-record'],
	// citizen-participation: public consultations, reactions, participatory
	// budgeting and advisory citizen votes (read/write via the object store;
	// lifecycle/intake/moderation/voting/publish go through the controller).
	['public-consultation', 'publicConsultationSchema', 'public-consultation'],
	['consultation-reaction', 'consultationReactionSchema', 'consultation-reaction'],
	['participatory-budget', 'participatoryBudgetSchema', 'participatory-budget'],
	['budget-proposal', 'budgetProposalSchema', 'budget-proposal'],
	['citizen-vote', 'citizenVoteSchema', 'citizen-vote'],
]

/**
 * Boot hook called from App.vue and AdminRoot.vue. Loads decidiq's
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
 * @spec openspec/specs/decidesk-store-migration/spec.md
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	const settings = settingsStore.getSettings || {}
	const register = settings.register || 'decidiq'

	for (const [type, settingKey, fallback] of OBJECT_TYPES) {
		objectStore.registerObjectType(
			type,
			settings[settingKey] || fallback,
			register,
		)
	}

	return { settingsStore, objectStore }
}

export { useSettingsStore }
