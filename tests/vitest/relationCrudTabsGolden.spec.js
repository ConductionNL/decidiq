/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of four relation tabs that list, create, edit and delete the
 * objects under their parent: motions under an agenda item, amendments under
 * a motion, and action items under a decision (as a table and as a board).
 * Records every store and API call, every dialog result and the state left
 * behind, pinned before the tabs shared their load, open, save and delete
 * steps.
 *
 * @spec exclude golden test of the relation tabs, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import * as relationRows from '../../src/components/tabs/relationRows.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

globalThis.t = (_app, text) => text

// The tabs and the steps they share reach the store through the same double.
let currentStore = null
vi.mock('../../src/components/tabs/useRelationStore.js', () => ({
	ensureRelationType: (type) => currentStore(type),
}))

const TABS = path.resolve(__dirname, '../../src/components/tabs')

const BASE = {
	'@conduction/nextcloud-vue': {
		CnDataTable: {},
		CnDeleteDialog: {},
		CnFormDialog: {},
		CnNoteCard: {},
		CnRowActions: {},
		CnStatusBadge: {},
	},
	'@nextcloud/vue': { NcButton: {}, NcLoadingIcon: {}, NcSelect: {} },
	'@nextcloud/router': { generateUrl: (u) => u },
	'vue-material-design-icons/Pencil.vue': { default: {} },
	'vue-material-design-icons/Plus.vue': { default: {} },
	'vue-material-design-icons/TrashCanOutline.vue': { default: {} },
	'vue-material-design-icons/ViewColumnOutline.vue': { default: {} },
	'../../constants/decisionLifecycle.js': { DECISION_LIFECYCLE_COLORS: {} },
	'../../store/store.js': { useSettingsStore: () => ({ getSettings: {} }) },
}

/**
 * Build a tab over a recording store and recording action-item API.
 *
 * @param {string} file The tab.
 * @param {object} answer How the store and API answer: { fail, silent, items }.
 * @return {{vm: object, calls: Array<object>}} The instance and its log.
 */
function tab(file, answer) {
	const calls = []
	const act = (call, value) => {
		calls.push(call)
		if (answer.fail && call.fn !== 'fetchSchema') {
			throw new Error(answer.silent ? '' : `${call.fn} failed`)
		}
		return value
	}
	const store = (type) => ({
		fetchSchema: async (schema) =>
			act({ fn: 'fetchSchema', type, schema }, { title: `${schema} schema` }),
		fetchCollection: async (schema, params) =>
			act({ fn: 'fetchCollection', type, schema, params }, answer.items),
		saveObject: async (schema, object) =>
			act({ fn: 'saveObject', type, schema, object }, object),
		deleteObject: async (schema, id) =>
			act({ fn: 'deleteObject', type, schema, id }, true),
	})
	const api = {
		createActionItem: async (data) =>
			act({ fn: 'createActionItem', data }, data),
		updateActionItem: async (uid, data) =>
			act({ fn: 'updateActionItem', uid, data }, data),
		deleteActionItem: async (uid) => act({ fn: 'deleteActionItem', uid }, true),
	}
	currentStore = store
	const component = loadSfcScript(path.join(TABS, file), {
		...BASE,
		'./relationRows.js': relationRows,
		'../../services/actionItemApi.js': api,
		'../../services/deckProjection.js': {
			// The board's own uid rule, as deckProjection.itemUid states it.
			itemUid: (item) =>
				String(
					(item
						&& (item.uuid
							|| item.id
							|| (item['@self'] && item['@self'].uuid)))
						|| '',
				),
			LANES: ['open', 'in-progress', 'done'],
			projectActionItems: async () => ({}),
			statusToLane: () => 'open',
		},
		'./useRelationStore.js': { ensureRelationType: store },
	})
	const vm = instanceOf(component, { objectId: 'parent-1' })
	vm.$refs = {
		formDialog: { setResult: (r) => calls.push({ fn: 'formResult', r }) },
		deleteDialog: { setResult: (r) => calls.push({ fn: 'deleteResult', r }) },
	}
	const refresh = vm.refresh
	vm.refresh = (...args) => {
		calls.push({ fn: 'refresh' })
		return refresh(...args)
	}
	return { vm, calls, keys: Object.keys(component.data()) }
}

/**
 * Run a scenario and keep the calls and the state.
 *
 * @param {string} file The tab.
 * @param {object} answer How the store answers.
 * @param {(vm: object) => Promise<void>} steps What to do.
 * @return {Promise<object>} The calls and the state.
 */
async function scenario(file, answer, steps) {
	const { vm, calls, keys } = tab(file, answer)
	await steps(vm)
	await new Promise((resolve) => setTimeout(resolve, 0))
	const state = {}
	for (const key of keys) state[key] = vm[key]
	return { calls, state }
}

const ROW = {
	id: 'row-1',
	uuid: 'uuid-1',
	title: 'Row',
	'@self': { uuid: 'self-1' },
}

const FILES = [
	'AgendaMotionsTab.vue',
	'MotionAmendmentsTab.vue',
	'DecisionActionItemsTab.vue',
	'ActionItemDeckBoard.vue',
]

describe.each(FILES)('%s', (file) => {
	it('loads its rows, once without and once with a cached schema', async () => {
		expect(
			await scenario(file, { items: [ROW] }, async (vm) => {
				await vm.refresh()
				await vm.refresh()
			}),
		).toMatchSnapshot()
		expect(
			await scenario(file, { items: null }, (vm) => vm.refresh()),
		).toMatchSnapshot()
	})

	it('reports a failing load, with and without a message, and skips without a parent', async () => {
		expect(
			await scenario(file, { fail: true }, (vm) => vm.refresh()),
		).toMatchSnapshot()
		expect(
			await scenario(file, { fail: true, silent: true }, (vm) => vm.refresh()),
		).toMatchSnapshot()
		const { vm, calls } = tab(file, {})
		vm.objectId = ''
		await vm.refresh()
		expect(calls).toEqual([{ fn: 'refresh' }])
	})

	it('opens the form to create and to edit', async () => {
		expect(
			await scenario(file, {}, async (vm) => {
				await vm.openCreate()
				vm.formOpen = false
				await vm.openEdit(ROW)
			}),
		).toMatchSnapshot()
	})

	it('saves a new and an edited row, and reports a failing save', async () => {
		expect(
			await scenario(file, { items: [] }, (vm) =>
				vm.onConfirm({ title: 'New' }),
			),
		).toMatchSnapshot()
		expect(
			await scenario(file, { items: [] }, async (vm) => {
				vm.editTarget = { ...ROW }
				await vm.onConfirm({ title: 'Edited' })
			}),
		).toMatchSnapshot()
		expect(
			await scenario(file, { fail: true }, (vm) =>
				vm.onConfirm({ title: 'New' }),
			),
		).toMatchSnapshot()
		expect(
			await scenario(file, { fail: true, silent: true }, (vm) =>
				vm.onConfirm({ title: 'New' }),
			),
		).toMatchSnapshot()
	})

	it('deletes a row, and reports a failing delete', async () => {
		const remove = async (vm) => {
			vm.deleteTarget = { ...ROW }
			await vm.confirmDelete()
		}
		expect(await scenario(file, { items: [] }, remove)).toMatchSnapshot()
		expect(await scenario(file, { fail: true }, remove)).toMatchSnapshot()
		expect(
			await scenario(file, { fail: true, silent: true }, remove),
		).toMatchSnapshot()
	})
})
