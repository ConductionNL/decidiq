/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * capability-rows-get-requirements 4.3 (mot-02): an amendment can target a
 * proposal as well as a motion. The amendments widget sits on the decision
 * page too, lists the amendments that name that decision in `amends`, and a
 * new amendment is saved with that reference as a draft amendment. Only the
 * relation store is a double.
 *
 * @spec openspec/changes/capability-rows-get-requirements/specs/motion-amendment/spec.md#requirement-an-amendment-can-target-a-proposal-as-well-as-a-motion
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import * as relationRows from '../../src/components/tabs/relationRows.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

globalThis.t = (_app, text) => text

let currentStore = null
vi.mock('../../src/components/tabs/useRelationStore.js', () => ({
	ensureRelationType: (type) => currentStore(type),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id)

/**
 * The amendments tab on a decision, over a recording store.
 *
 * @param {string} decisionId The decision the page shows.
 * @param {Array<object>} items What the listing answers.
 * @return {{vm: object, calls: Array<object>}} The instance and the store calls.
 */
function amendmentsOn(decisionId, items = []) {
	const calls = []
	currentStore = (type) => ({
		fetchSchema: async (schema) => ({ title: schema }),
		fetchCollection: async (schema, params) => {
			calls.push({ fn: 'fetchCollection', type, schema, params })
			return items
		},
		saveObject: async (schema, object) => {
			calls.push({ fn: 'saveObject', type, schema, object })
			return object
		},
		deleteObject: async () => true,
	})
	const component = loadSfcScript(path.join(ROOT, 'src/components/tabs/MotionAmendmentsTab.vue'), {
		'@conduction/nextcloud-vue': { CnDataTable: {}, CnDeleteDialog: {}, CnFormDialog: {}, CnNoteCard: {}, CnRowActions: {}, CnStatusBadge: {} },
		'@nextcloud/vue': { NcButton: {} },
		'vue-material-design-icons/Plus.vue': { default: {} },
		'../../constants/decisionLifecycle.js': { DECISION_LIFECYCLE_COLORS: {} },
		'./relationRows.js': relationRows,
		'./useRelationStore.js': { ensureRelationType: (type) => currentStore(type) },
	})
	const mixinMethods = (component.mixins || []).map((mixin) => mixin.methods || {})
	const vm = instanceOf({ ...component, methods: Object.assign({}, ...mixinMethods, component.methods) }, { objectId: decisionId })
	vm.$refs = { formDialog: { setResult: () => {} }, deleteDialog: { setResult: () => {} } }
	return { vm, calls }
}

describe('amendments on a proposal', () => {
	it('sit on the decision page, as on the motion page', () => {
		for (const [pageId, widgetId] of [['DecisionDetail', 'decision-amendments'], ['MotionDetail', 'motion-amendments']]) {
			const detail = page(pageId)
			const widget = detail.config.widgets.find((w) => w.id === widgetId)
			expect(widget).toEqual({ id: widgetId, type: 'custom', component: 'MotionAmendmentsTab', title: 'Amendments', icon: 'FileSign' })
			expect(detail.slots['widget-' + widgetId]).toBe('MotionAmendmentsTab')
			const cell = detail.config.layout.find((l) => l.widgetId === widgetId)
			expect(cell.gridWidth).toBe(12)
		}
		const layout = page('DecisionDetail').config.layout
		expect(new Set(layout.map((l) => l.id)).size).toBe(layout.length)
	})

	it('lists the amendments that name the proposal', async () => {
		const { vm, calls } = amendmentsOn('parking-2027', [{ id: 'am-1', title: 'Paragraph 3', amends: 'parking-2027' }])

		await vm.refresh()

		const listing = calls.find((c) => c.fn === 'fetchCollection')
		expect(listing.schema).toBe('amendment')
		expect(listing.params).toMatchObject({ decisionType: 'amendment', amends: 'parking-2027' })
	})

	it('stores a new amendment with a reference to the proposal', async () => {
		const { vm, calls } = amendmentsOn('parking-2027')

		await vm.onConfirm({ title: 'Paragraph 3', proposedText: 'Parking is free on Sundays.' })

		const save = calls.find((c) => c.fn === 'saveObject')
		expect(save.object).toMatchObject({ title: 'Paragraph 3', decisionType: 'amendment', amends: 'parking-2027' })
		expect(save.object.parentMotion).toBeUndefined()
	})
})
