/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ondermandaat chain widget shows the toedeling's depth: a hoofdmandaat
 * is depth 0 and an ondermandaat under it depth 1, counted from the chain
 * the widget walked up.
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import {
	contentWithDefaults,
	detailObjectId,
	plainObject,
} from '../../src/components/widgets/registerDetailWidgets.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/components/widgets/DelegationChainWidget.vue',
)

globalThis.t = (_app, text) => text

const MODULES = {
	'@conduction/nextcloud-vue': {
		CnIcon: {},
		CnNoteCard: {},
		CnWidgetWrapper: {},
		useObjectStore: () => null,
	},
	'@nextcloud/vue': { NcButton: {} },
	'./registerDetailWidgets.js': {
		contentWithDefaults,
		detailObjectId,
		plainObject,
		resolveObjectLabel: async (_store, ref) => `label of ${ref.id}`,
	},
}

/**
 * Load the chain for one toedeling over a store holding `records`.
 *
 * @param {object} current The toedeling on the page.
 * @param {Object<string, object>} records Toedelingen by id.
 *
 * @return {Promise<object>} The widget instance after loading.
 */
async function loaded(current, records) {
	const component = loadSfcScript(FILE, MODULES)
	const store = {
		objectTypeRegistry: { bevoegdheidstoedeling: {} },
		registerObjectType() {},
		async fetchObject(_schema, id) {
			return records[id] || null
		},
		async fetchCollection() {
			return []
		},
	}
	const vm = instanceOf(component, {
		content: {},
		objectId: current.id,
		register: '',
		schema: '',
		objectData: current,
		store,
	})
	for (const [name, fn] of Object.entries(component.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	vm.getStore = () => store
	await vm.load()
	return vm
}

describe('DelegationChainWidget depth (REQ-DMR-003)', () => {
	it('shows depth 0 for a hoofdmandaat', async () => {
		const vm = await loaded({ id: 'h', subject: 'Hoofdmandaat' }, {})
		expect(vm.depth).toBe(0)
	})

	it('shows depth 1 for an ondermandaat under a hoofdmandaat', async () => {
		const vm = await loaded(
			{ id: 'o', subject: 'Afdelingshoofd', parentAllocation: 'h' },
			{ h: { id: 'h', subject: 'Gemeentesecretaris' } },
		)
		expect(vm.depth).toBe(1)
		expect(vm.breadcrumb.map((entry) => entry.id)).toEqual(['h', 'o'])
	})

	it('counts every ancestor up to the root', async () => {
		const vm = await loaded(
			{ id: 'c', parentAllocation: 'b' },
			{ b: { id: 'b', parentAllocation: 'a' }, a: { id: 'a' } },
		)
		expect(vm.depth).toBe(2)
	})

	it('stops counting on a defensive cycle', async () => {
		const vm = await loaded(
			{ id: 'c', parentAllocation: 'b' },
			{ b: { id: 'b', parentAllocation: 'c' } },
		)
		expect(vm.depth).toBe(1)
	})

	it('renders the depth with its own translated label', () => {
		const sfc = fs.readFileSync(FILE, 'utf8')
		expect(sfc).toContain('data-testid="delegation-chain-depth"')
		expect(sfc).toContain("t('decidiq', 'Depth {depth}', { depth })")
	})
})
