/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the governance body members tab's `refresh()`: which store
 * calls it makes, in which order and with which arguments, and the rows it
 * builds (person names, contact details, faction names, date labels), over
 * own and faction memberships, a failing contact lookup, past members shown
 * and hidden, and a failing store. Pinned before refresh() was split into
 * named steps.
 *
 * @spec exclude golden test of the members tab loader, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import * as bodyMembership from '../../src/utils/bodyMembership.js'

// The store pulls in @nextcloud/vue, which plain vitest cannot resolve; only
// the pure membership helpers are used.
vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

globalThis.t = (_app, text) => text

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/GovernanceBodyMembersTab.vue',
)

const ICONS = [
	'AccountEdit',
	'AccountGroup',
	'AccountMultiplePlus',
	'CalendarRange',
	'CardAccountDetailsOutline',
	'FileDelimited',
	'LinkOff',
	'Plus',
]

/**
 * Run refresh() over a recording store.
 *
 * @param {object} data The store's answers and switches.
 * @return {Promise<object>} The calls and the state.
 */
async function run(data) {
	const calls = []
	const store = (type) => ({
		async fetchCollection(schema, params) {
			calls.push({ fn: 'fetchCollection', type, schema, params })
			if (data.fail) throw new Error(data.silent ? '' : 'store down')
			if (schema === 'contact-detail') {
				if (params.person === 'p-broken') throw new Error('no contacts')
				return data.contacts[params.person] || []
			}
			if (schema === 'governance-body') return data.factions
			return params.faction ? data.byFaction : data.own
		},
		async fetchObject(schema, id) {
			calls.push({ fn: 'fetchObject', type, schema, id })
			return data.people[id] || null
		},
	})
	const modules = {
		'@conduction/nextcloud-vue': {
			CnDataTable: {},
			CnDeleteDialog: {},
			CnNoteCard: {},
			CnRowActions: {},
		},
		'@nextcloud/vue': {
			NcActionButton: {},
			NcActions: {},
			NcButton: {},
			NcCheckboxRadioSwitch: {},
		},
		'../../modals/ContactDetailsDialog.vue': { default: {} },
		'../../modals/MemberAddDialog.vue': { default: {} },
		'../../modals/MemberCsvImportDialog.vue': { default: {} },
		'../../modals/MemberGroupImportDialog.vue': { default: {} },
		'../../modals/MemberRoleDialog.vue': { default: {} },
		'../../modals/MemberTermDialog.vue': { default: {} },
		'../../utils/bodyMembership.js': bodyMembership,
		'../../utils/memberProfile.js': { profilePath: (id) => `/p/${id}` },
		'./useRelationStore.js': {
			buildMembershipPayload: () => ({}),
			ensureRelationType: store,
		},
	}
	for (const icon of ICONS) {
		modules[`vue-material-design-icons/${icon}.vue`] = { default: {} }
	}
	const component = loadSfcScript(FILE, modules)
	const vm = instanceOf(component, { objectId: 'body-1' })
	vm.showPast = Boolean(data.showPast)
	vm.dateLabel = (value) => (value ? `label(${value})` : '')
	await vm.refresh()
	return { calls, state: { loading: vm.loading, error: vm.error, rows: vm.rows } }
}

const DATA = {
	own: [
		{
			id: 'm1',
			person: 'p-anna',
			governanceBody: 'body-1',
			role: 'member',
			startDate: '2024-01-01',
			faction: 'f-1',
		},
		{
			id: 'm2',
			person: 'p-broken',
			governanceBody: 'body-1',
			role: 'chair',
			startDate: '2024-02-01',
		},
		{
			id: 'm3',
			person: 'p-old',
			governanceBody: 'body-1',
			role: 'member',
			startDate: '2020-01-01',
			endDate: '2021-01-01',
		},
		{
			id: 'm4',
			governanceBody: 'body-1',
			role: 'member',
			startDate: '2024-01-01',
		},
	],
	byFaction: [
		{
			id: 'm1',
			person: 'p-anna',
			governanceBody: 'council',
			faction: 'body-1',
			startDate: '2024-01-01',
		},
		{
			id: 'm5',
			person: 'p-bert',
			governanceBody: 'council',
			faction: 'body-1',
			startDate: '2023-06-01',
		},
	],
	factions: [
		{ id: 'f-1', name: 'Groen', bodyType: 'faction', parentBody: 'body-1' },
	],
	people: {
		'p-anna': { id: 'p-anna', name: 'Anna', email: 'anna@person.example' },
		'p-broken': { id: 'p-broken', name: 'Broken' },
		'p-old': { id: 'p-old', name: 'Old' },
		'p-bert': { id: 'p-bert', name: 'Bert', email: 'bert@person.example' },
	},
	contacts: {
		'p-anna': [
			{ type: 'email', value: 'anna@contact.example' },
			{ type: 'phone', value: '0612345678' },
		],
	},
}

describe('GovernanceBodyMembersTab refresh()', () => {
	it('loads, joins and decorates the members, current only and with the past', async () => {
		expect(await run(DATA)).toMatchSnapshot()
		expect(await run({ ...DATA, showPast: true })).toMatchSnapshot()
	})

	it('treats missing answers as empty and reports a failing store', async () => {
		expect(
			await run({ ...DATA, own: null, byFaction: null, factions: [] }),
		).toMatchSnapshot()
		const failing = await run({ ...DATA, fail: true })
		expect(failing.state).toEqual({
			loading: false,
			error: 'store down',
			rows: [],
		})
		const silent = await run({ ...DATA, fail: true, silent: true })
		expect(silent.state.error).toBe('Failed to load members.')
	})
})
