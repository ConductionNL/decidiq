/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for initializeStores: over several settings shapes, every
 * call it makes on the settings and object stores is snapshotted in order.
 *
 * @spec openspec/specs/decidesk-store-migration/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

const calls = []
let settings = null

const objectStore = {
	registerObjectType: (...args) => calls.push(['registerObjectType', ...args]),
}
const settingsStore = {
	fetchSettings: async () => {
		calls.push(['fetchSettings'])
	},
	get getSettings() {
		calls.push(['getSettings'])
		return settings
	},
}

vi.mock('@conduction/nextcloud-vue', () => ({
	createObjectStore: () => () => objectStore,
	liveUpdatesPlugin: () => ({}),
}))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => settingsStore,
}))

const { initializeStores } = await import('../../src/store/store.js')

const SCHEMA_KEYS = [
	'minutesSchema',
	'decisionSchema',
	'actionItemSchema',
	'meetingSchema',
	'agendaItemSchema',
	'participantSchema',
	'votingRoundSchema',
	'governanceBodySchema',
	'voteSchema',
	'engagementRecordSchema',
	'publicConsultationSchema',
	'consultationReactionSchema',
	'participatoryBudgetSchema',
	'budgetProposalSchema',
	'citizenVoteSchema',
	'motionSchema',
	'amendmentSchema',
]

const SETTINGS = {
	null: null,
	empty: {},
	'register only': { register: 'other-register' },
	'every override': Object.fromEntries([
		['register', 'r'],
		...SCHEMA_KEYS.map((key) => [key, 'custom-' + key]),
	]),
	'falsy overrides': Object.fromEntries([
		['register', ''],
		...SCHEMA_KEYS.map((key, i) => [
			key,
			['', 0, null, undefined, false][i % 5],
		]),
	]),
	'every other override': Object.fromEntries(
		SCHEMA_KEYS.filter((key, i) => i % 2 === 0).map((key) => [
			key,
			key.toUpperCase(),
		]),
	),
}

describe('initializeStores golden', () => {
	for (const [name, shape] of Object.entries(SETTINGS)) {
		it(name, async () => {
			calls.length = 0
			settings = shape
			const out = await initializeStores()
			expect(out.settingsStore).toBe(settingsStore)
			expect(out.objectStore).toBe(objectStore)
			expect(calls).toMatchSnapshot()
		})
	}
})
