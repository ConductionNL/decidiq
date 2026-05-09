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

import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from '../../store/store.js'

/**
 * Logical type slugs used inside the decidesk tab components.
 * Each maps to the settings key holding the OpenRegister schema slug.
 */
const TYPE_TO_SETTINGS_KEY = {
	'governance-body': 'governanceBodySchema',
	meeting: 'meetingSchema',
	participant: 'participantSchema',
	'agenda-item': 'agendaItemSchema',
	motion: 'motionSchema',
	amendment: 'amendmentSchema',
	'voting-round': 'votingRoundSchema',
	vote: 'voteSchema',
	decision: 'decisionSchema',
	'action-item': 'actionItemSchema',
	minutes: 'minutesSchema',
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
