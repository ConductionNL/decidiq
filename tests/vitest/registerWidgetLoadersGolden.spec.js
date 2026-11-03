/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the loaders of three register detail widgets: the
 * confidentiality timeline, the delegation chain and the version timeline.
 * Each run records every store call and every label lookup, in order and with
 * its arguments, and the state the loader leaves behind, over full records,
 * gaps in the data, a missing store and a failing store.
 *
 * @spec exclude golden test of widget loaders, no behaviour of its own
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const WIDGETS = path.resolve(__dirname, '../../src/components/widgets')
const GOLDEN = path.resolve(
	__dirname,
	'../fixtures/register-widget-loaders-golden.json',
)

globalThis.t = (_app, text) => text

/**
 * A store double that logs every call and answers through `answer`.
 *
 * @param {Array<object>} calls The shared call log.
 * @param {object} spec How the store behaves: registry, answer(call).
 *
 * @return {object} The store.
 */
function recordingStore(calls, spec) {
	const answerOf = (call) => {
		calls.push(call)
		return spec.answer ? spec.answer(call) : null
	}
	return {
		objectTypeRegistry: spec.registry,
		registerObjectType(slug, schema, register) {
			calls.push({ fn: 'registerObjectType', slug, schema, register })
		},
		async fetchObject(schema, id) {
			return answerOf({ fn: 'fetchObject', schema, id })
		},
		async fetchCollection(schema, params) {
			return answerOf({ fn: 'fetchCollection', schema, params })
		},
	}
}

/**
 * Run one widget's loader on one case.
 *
 * @param {object} widget The widget: file, loader name, modules.
 * @param {object} item The case: props and store behaviour.
 *
 * @return {Promise<object>} The calls and the resulting state.
 */
async function run(widget, item) {
	const calls = []
	const resolveObjectLabel = async (store, slug, schema, register, id, field) => {
		calls.push({ fn: 'resolveObjectLabel', slug, schema, register, id, field })
		if (id === 'boom') {
			throw new Error('label lookup failed')
		}
		return `label of ${id}`
	}
	const component = loadSfcScript(
		path.join(WIDGETS, widget.file),
		widget.modules(resolveObjectLabel),
	)
	const store =
		item.store === null ? null : recordingStore(calls, item.store || {})
	const vm = instanceOf(component, {
		content: {},
		objectId: '',
		register: '',
		schema: '',
		objectData: {},
		store,
		...item.props,
	})
	for (const [name, fn] of Object.entries(component.computed || {})) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	vm.getStore = () => store
	let thrown = null
	try {
		await vm[widget.loader]()
		if (item.rerun) {
			vm.objectData = item.rerun
			await vm[widget.loader]()
		}
	} catch (e) {
		thrown = e.message
	}
	const state = {}
	for (const key of Object.keys(component.data())) {
		state[key] = vm[key]
	}
	return { calls, state, thrown }
}

const BASE_MODULES = {
	'@nextcloud/vue': { NcButton: {} },
}

const CONFIDENTIALITY = {
	file: 'ConfidentialityStatusTimelineWidget.vue',
	loader: 'resolveReferences',
	modules: (resolveObjectLabel) => ({
		...BASE_MODULES,
		'@conduction/nextcloud-vue': {
			CnIcon: {},
			CnWidgetWrapper: {},
			useObjectStore: () => null,
		},
		'vue-material-design-icons/AlertOutline.vue': { default: {} },
		'vue-material-design-icons/CheckCircleOutline.vue': { default: {} },
		'vue-material-design-icons/ClockOutline.vue': { default: {} },
		'./registerDetailWidgets.js': {
			buildConfidentialityStages: () => [],
			resolveObjectLabel,
		},
	}),
	cases: {
		full: {
			props: {
				content: { register: 'reg', groundSchema: 'grond' },
				objectData: {
					id: 'c1',
					ground: 'g1',
					imposedByBody: 'b1',
					ratificationDecision: 'd1',
					ratificationAgendaItem: 'a1',
					dissolutionDecision: 'd2',
					targetDocument: 'doc1',
					targetDecision: 'd9',
				},
			},
			store: {
				registry: {},
				answer: (call) => ({ id: call.id, name: 'ground ' + call.id }),
			},
		},
		'registered-ground-target-item': {
			props: {
				objectData: { id: 'c2', ground: 'g2', targetAgendaItem: 'a7' },
			},
			store: { registry: { 'geheimhouding-grond': {} }, answer: () => null },
		},
		'no-registry-target-decision': {
			props: {
				register: 'prop-reg',
				objectData: {
					id: 'c3',
					targetDecision: 'd3',
					dissolutionDecision: 'd4',
				},
			},
			store: {},
		},
		'nothing-linked': { props: { objectData: { id: 'c4' } }, store: {} },
		'linked-then-cleared': {
			props: {
				objectData: {
					id: 'c9',
					ground: 'g9',
					imposedByBody: 'b9',
					ratificationDecision: 'd9',
					ratificationAgendaItem: 'a9',
					dissolutionDecision: 'd8',
					targetDecision: 'd7',
				},
			},
			rerun: { id: 'c9' },
			store: { answer: (call) => ({ id: call.id }) },
		},
		'no-id': { props: { objectData: { ground: 'g5' } }, store: {} },
		'no-record': { props: { objectData: null }, store: {} },
		'no-store': {
			props: { objectData: { id: 'c6', ground: 'g6' } },
			store: null,
		},
		'label-fails': {
			props: {
				objectData: {
					id: 'c7',
					imposedByBody: 'boom',
					ratificationDecision: 'd7',
				},
			},
			store: {},
		},
		'ground-fails': {
			props: {
				objectData: { id: 'c8', ground: 'g8', ratificationAgendaItem: 'a8' },
			},
			store: {
				answer: () => {
					throw new Error('fetch failed')
				},
			},
		},
	},
}

const DELEGATION = {
	file: 'DelegationChainWidget.vue',
	loader: 'load',
	modules: (resolveObjectLabel) => ({
		...BASE_MODULES,
		'@conduction/nextcloud-vue': {
			CnIcon: {},
			CnNoteCard: {},
			CnWidgetWrapper: {},
			useObjectStore: () => null,
		},
		'./registerDetailWidgets.js': { resolveObjectLabel },
	}),
	cases: {
		'chain-children-parties': {
			props: {
				content: { register: 'reg' },
				objectData: {
					id: 't3',
					subject: 'Third',
					parentAllocation: 't2',
					delegans: 'b1',
					delegatarisBody: 'b2',
					decision: 'd1',
				},
			},
			store: {
				registry: {},
				answer: (call) => {
					if (call.fn === 'fetchObject') {
						return (
							{
								t2: {
									id: 't2',
									subject: 'Second',
									parentAllocation: 't1',
								},
								t1: { id: 't1', parentAllocation: 't3' },
							}[call.id] || null
						)
					}
					return [
						{ id: 't4', subject: 'Fourth' },
						{ id: 't3' },
						{ id: 't5', subject: '' },
					]
				},
			},
		},
		'fetch-current-slug-retry-role': {
			props: {
				objectId: 't9',
				objectData: {},
				content: { decisionRefField: 'mandate' },
			},
			store: {
				registry: { bevoegdheidstoedeling: {} },
				answer: (call) => {
					if (call.fn === 'fetchObject') {
						return {
							id: 't9',
							'@self': { slug: 'nine' },
							delegansDescription: 'The council',
							decision: 'd5',
							delegateRole: 'Clerk',
							parentAllocation: 'gone',
						}
					}
					return call.params.parentAllocation === 'nine'
						? [{ id: 'c1', subject: 'Child' }]
						: []
				},
			},
		},
		'top-slug-person': {
			props: { objectData: { id: 't8', slug: 'eight', delegatePerson: 'p1' } },
			store: { answer: (call) => (call.fn === 'fetchCollection' ? [] : null) },
		},
		'no-parties-no-slug': {
			props: { objectData: { id: 't7' } },
			store: { answer: (call) => (call.fn === 'fetchCollection' ? [] : null) },
		},
		'children-null': {
			props: {
				objectData: { '@self': { id: 't6' }, id: 't6', delegans: 'boom' },
			},
			store: { answer: () => null },
		},
		'current-missing': {
			props: { objectId: 't0' },
			store: {
				answer: (call) =>
					call.fn === 'fetchCollection' ? [{ id: 'k1' }] : null,
			},
		},
		'no-id': { props: {}, store: {} },
		'no-store': { props: { objectId: 't1' }, store: null },
		'store-fails': {
			props: { objectData: { id: 't5', parentAllocation: 't4' } },
			store: {
				answer: () => {
					throw new Error('')
				},
			},
		},
	},
}

const VERSIONS = {
	file: 'RegisterVersionTimelineWidget.vue',
	loader: 'load',
	modules: (resolveObjectLabel) => ({
		...BASE_MODULES,
		'@conduction/nextcloud-vue': {
			CnIcon: {},
			CnNoteCard: {},
			CnStatusBadge: {},
			CnWidgetWrapper: {},
			useObjectStore: () => null,
		},
		'./registerDetailWidgets.js': {
			resolveObjectLabel,
			sortVersionsByEffectiveDate: (v) => v,
		},
	}),
	cases: {
		'versions-with-decisions': {
			props: {
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
					versionRegister: 'vr',
					decisionRefField: 'decision',
				},
				objectData: { id: 'r1' },
			},
			store: {
				registry: {},
				answer: () => [
					{ id: 'v1', decision: 'd1' },
					{ id: 'v2', decision: 'd1' },
					{ id: 'v3' },
					{ id: 'v4', decision: 'd2' },
				],
			},
		},
		'slug-retry': {
			props: {
				register: 'preg',
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
				},
				objectData: { id: 'r2', '@self': { slug: 'two' } },
			},
			store: {
				registry: { 'reg-version': {} },
				answer: (call) =>
					call.params.regulation === 'two' ? [{ id: 'v9' }] : [],
			},
		},
		'empty-no-slug': {
			props: {
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
				},
				objectId: 'r3',
			},
			store: { answer: () => [] },
		},
		'slug-from-data': {
			props: {
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
				},
				objectData: { id: 'r6', slug: 'six' },
			},
			store: { answer: () => [] },
		},
		'no-schema': {
			props: { content: { parentRefField: 'regulation' }, objectId: 'r4' },
			store: {},
		},
		'no-parent-field': {
			props: { content: { versionSchema: 'reg-version' }, objectId: 'r4' },
			store: {},
		},
		'no-id': {
			props: {
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
				},
			},
			store: {},
		},
		'no-store': {
			props: {
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
				},
				objectId: 'r5',
			},
			store: null,
		},
		'store-fails': {
			props: {
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
				},
				objectId: 'r7',
			},
			store: {
				answer: () => {
					throw new Error('collection failed')
				},
			},
		},
		'label-fails': {
			props: {
				content: {
					versionSchema: 'reg-version',
					parentRefField: 'regulation',
					decisionRefField: 'decision',
				},
				objectId: 'r8',
			},
			store: { answer: () => [{ id: 'v1', decision: 'boom' }] },
		},
	},
}

describe('register widget loaders', () => {
	it('make the recorded calls and leave the recorded state', async () => {
		const actual = {}
		for (const [name, widget] of Object.entries({
			CONFIDENTIALITY,
			DELEGATION,
			VERSIONS,
		})) {
			for (const [label, item] of Object.entries(widget.cases)) {
				actual[`${name} ${label}`] = await run(widget, item)
			}
		}
		if (process.env.RECORD_REGISTER_WIDGET_GOLDEN === '1') {
			fs.writeFileSync(GOLDEN, JSON.stringify(actual, null, 2) + '\n')
		}
		const golden = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
		expect(Object.keys(actual)).toEqual(Object.keys(golden))
		for (const key of Object.keys(golden)) {
			expect(JSON.parse(JSON.stringify(actual[key])), key).toEqual(golden[key])
		}
	})
})
