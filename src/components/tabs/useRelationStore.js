// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// useRelationStore — small helper that wires the lib's useObjectStore
// to the decidesk register/schema map for cross-schema relation tabs.
//
// Tabs mounted inside `CnObjectSidebar` need to fetch child objects
// (e.g. participants under a meeting, agenda-items under a meeting).
// CnObjectSidebar forwards `register` (parent register) but not the
// child schema slug, so each tab registers its child object type with
// the lib's useObjectStore on first use.
//
// Schema slugs are sourced from the settings store (settings.<x>Schema
// fields, populated server-side from lib/Settings/decidesk_register.json).
// Falls back to the literal slug when the settings store hasn't loaded.

// Use the decidesk-specific store instance (id 'decidesk-objects'),
// not the lib's default 'conduction-objects' store. initializeStores()
// in src/store/store.js registers all object types on this instance,
// so the relation tabs share state with the rest of the app.
import { useObjectStore, useSettingsStore } from '../../store/store.js'

/**
 * Logical type slugs used inside the decidesk tab components.
 * Each maps to the settings key holding the OpenRegister schema slug.
 */
const TYPE_TO_SETTINGS_KEY = {
	'governance-body': 'governanceBodySchema',
	meeting: 'meetingSchema',
	participant: 'participantSchema',
	'agenda-item': 'agendaItemSchema',
	// ADR-005: motion and amendment are folded into the unified Decision
	// supertype (decisionType discriminator). The logical relation types are
	// kept so existing tab components keep working, but both now resolve to
	// the `decision` schema; callers add a `decisionType` filter to narrow.
	motion: 'decisionSchema',
	amendment: 'decisionSchema',
	'voting-round': 'votingRoundSchema',
	vote: 'voteSchema',
	decision: 'decisionSchema',
	'action-item': 'actionItemSchema',
	minutes: 'minutesSchema',
	'engagement-record': 'engagementRecordSchema',
	// C6 decision-detail-fullpicture: the route tab reads a Decision's `route`
	// relation, which resolves to DecisionStage objects (slug `decision-stage`),
	// and resolves each stage's decision-maker name from a Person or
	// GovernanceBody. These settings keys are not in SettingsService::CONFIG_KEYS,
	// so ensureRelationType falls back to the literal logical-type slug — which
	// matches the schema slug in decidesk_register.json (decision-stage / person /
	// governance-body). The mappings are kept explicit for documentation.
	'decision-stage': 'decisionStageSchema',
	person: 'personSchema',
	// publish-decisions-via-opencatalogi: the publication overview + detail
	// actions read PublicationRecord objects via the OR object API. No
	// settings key exists, so this falls back to the literal slug
	// `publication-record`, matching the schema slug in decidesk_register.json.
	'publication-record': 'publicationRecordSchema',
}

/**
 * Ensure a logical type is registered with the shared object store.
 *
 * @param {string} type Logical type slug (key of TYPE_TO_SETTINGS_KEY).
 * @return {object} The lib's object store instance, with the type
 *                  registered (idempotent).
 */
export function ensureRelationType(type) {
	const objectStore = useObjectStore()
	const settingsStore = useSettingsStore()
	const settings = settingsStore.getSettings || {}
	const register = settings.register || 'decidesk'

	const settingsKey = TYPE_TO_SETTINGS_KEY[type]
	const schemaSlug = (settingsKey && settings[settingsKey]) || type

	if (!objectStore.objectTypeRegistry[type]) {
		objectStore.registerObjectType(type, schemaSlug, register)
	}
	return objectStore
}
