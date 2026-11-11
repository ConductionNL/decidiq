/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for what the decisions tab and the decisions widget share: the
 * props and state they declare, the host identity they read, their watchers
 * and created hook, and the row, lifecycle, link, create and refresh methods.
 * Each component is evaluated with its mixins merged the way Vue merges them,
 * so the recording holds whichever file a part lives in.
 *
 * Record with RECORD_DECISIONS_LEAF_GOLDEN=1.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { loadSfcScript } from './helpers/sfcScript.js'

const GOLDEN = path.resolve(__dirname, '../fixtures/decisions-leaf-shared-golden.json')
const INTEGRATIONS = path.resolve(__dirname, '../../src/integrations')

const rec = vi.hoisted(() => ({ calls: [], hostDecisions: [], failure: null }))

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
	translatePlural: (_app, a) => a,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: async () => ({ data: {} }), post: async () => ({ data: {} }) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (u) => `/index.php${u}`,
	generateOcsUrl: (u) => u,
}))
vi.mock('../../src/integrations/decisionLink.js', async (importOriginal) => ({
	...(await importOriginal()),
	listHostDecisions: async (id, limit) => {
		rec.calls.push(['listHostDecisions', id, limit])
		if (rec.failure !== null) {
			throw rec.failure
		}
		return rec.hostDecisions
	},
	listDecisionTypes: async () => {
		rec.calls.push(['listDecisionTypes'])
		return ['proposal', 'decision']
	},
}))
vi.mock('../../src/integrations/decisionCard.js', async (importOriginal) => ({
	...(await importOriginal()),
	listDecisionStages: async (id) => {
		rec.calls.push(['listDecisionStages', id])
		return [{ assignedPerson: 'p-' + id }]
	},
	decisionAuthority: async (id) => {
		rec.calls.push(['decisionAuthority', id])
		return { allowed: true }
	},
	personNames: async (ids) => {
		rec.calls.push(['personNames', ids])
		return { names: ids.length }
	},
	userDisplayNames: async (owners) => {
		rec.calls.push(['userDisplayNames', owners])
		return { owners: owners.length }
	},
}))

/**
 * The real sibling modules, by the specifier the components import them with.
 *
 * @return {Promise<object>} Specifier to module.
 */
async function siblings() {
	const out = {}
	for (const file of ['decisionCard.js', 'decisionLink.js', 'hostDecisions.js', 'hostProposalCreate.js', 'hostWording.js', 'leafReport.js']) {
		if (fs.existsSync(path.join(INTEGRATIONS, file))) {
			out['./' + file] = await import(path.join(INTEGRATIONS, file))
		}
	}
	return out
}

/**
 * A component's options with its mixins merged as Vue merges them.
 *
 * @param {string} file The component file.
 * @param {object} real The real sibling modules.
 * @return {object} props, data, computed, methods, watch and created.
 */
function merged(file, real) {
	const full = path.join(INTEGRATIONS, file)
	const stub = new Proxy({}, { get: (_t, key) => (key === 'default' ? {} : () => ({})) })
	const modules = {
		'@nextcloud/l10n': { translate: (_app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text) },
		'@nextcloud/router': { generateUrl: (u) => `/index.php${u}` },
	}
	for (const [, spec] of fs.readFileSync(full, 'utf8').matchAll(/^import\s+[\s\S]*?\s+from\s+'([^']+)'/gm)) {
		modules[spec] = modules[spec] || real[spec] || stub
	}
	const component = loadSfcScript(full, modules)
	const parts = [...(component.mixins || []), component]
	return {
		props: Object.assign({}, ...parts.map((p) => p.props || {})),
		data: () => Object.assign({}, ...parts.map((p) => (p.data ? p.data() : {}))),
		computed: Object.assign({}, ...parts.map((p) => p.computed || {})),
		methods: Object.assign({}, ...parts.map((p) => p.methods || {})),
		watch: Object.assign({}, ...parts.map((p) => p.watch || {})),
		created: parts.map((p) => p.created).filter(Boolean),
	}
}

/**
 * A plain instance: props at their defaults, the state, the computed values as getters.
 *
 * @param {object} options The merged options.
 * @param {object} props Props to set.
 * @return {object} The instance.
 */
function instance(options, props = {}) {
	const vm = {}
	for (const [name, prop] of Object.entries(options.props)) {
		vm[name] = typeof prop.default === 'function' && prop.type !== Function ? prop.default() : prop.default
	}
	Object.assign(vm, options.data(), props)
	for (const [name, getter] of Object.entries(options.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), enumerable: false })
	}
	for (const [name, method] of Object.entries(options.methods)) {
		vm[name] = method.bind(vm)
	}
	vm.n = (_app, one, many, count) => (count === 1 ? one : many)
	return vm
}

const DECISIONS = [
	{ id: 'd-1', title: '  Budget  ', lifecycle: 'draft' },
	{ '@self': { id: 'd-2', owner: 'alice' }, data: { title: 'Plan', lifecycle: 'enacted' } },
	{ id: 'd-3', decisionType: 'proposal', proposer: 'bob' },
	{ uuid: 'd-4', lifecycle: 'withdrawn', title: '' },
	{ id: 'd-5', lifecycle: 'archived' },
	{ id: 'd-6', lifecycle: 'odd' },
	{ id: 'd-7' },
	{ title: 'no id', lifecycle: 'decided' },
]

/**
 * Record one component.
 *
 * @param {string} file The component file.
 * @param {object} real The real sibling modules.
 * @return {Promise<object>} The recording.
 */
async function record(file, real) {
	const options = merged(file, real)
	const out = {
		props: Object.keys(options.props).sort(),
		data: options.data(),
		watch: Object.fromEntries(Object.entries(options.watch).map(([k, w]) => [k, Boolean(w.immediate)])),
		created: options.created.length,
	}
	const vm = instance(options, { integrationContext: { objectId: 'ctx-1', register: 'ctx-reg', schema: 'ctx-schema' } })
	out.fromContext = [vm.hostObjectId, vm.hostRegister, vm.hostSchema]
	const own = instance(options, { objectId: 7, register: 'reg', schema: 'case' })
	out.fromProps = [own.hostObjectId, own.hostRegister, own.hostSchema, own.appUrl, own.openInDecidiqLabel]
	out.createSchemaIsObject = typeof own.createSchema === 'object'
	out.rows = DECISIONS.map((d) => [own.rowKey(d) ?? null, own.rowTitle(d), own.lifecycleLabel(d), own.lifecycleVariant(d), own.decisionUrl(d)])

	own.openCreate()
	const blank = instance(options)
	blank.openCreate()
	out.openCreate = [own.createOpen, blank.createOpen]

	rec.calls.length = 0
	for (const hook of options.created) {
		hook.call(own)
	}
	await new Promise((resolve) => setTimeout(resolve, 0))
	out.afterCreated = [rec.calls.slice(), own.decisionTypes]

	const runs = {}
	for (const [name, setup] of Object.entries({
		'no host': () => [instance(options, { decisions: ['stale'] }), null],
		loaded: () => [instance(options, { objectId: 'h-1' }), null],
		'failed with message': () => [instance(options, { objectId: 'h-1' }), new Error('server said no')],
		'failed without message': () => [instance(options, { objectId: 'h-1' }), {}],
	})) {
		const [target, failure] = setup()
		rec.calls.length = 0
		rec.failure = failure
		rec.hostDecisions = [{ id: 'a' }, { id: 'b', '@self': { owner: 'o' } }, { id: 'c', proposer: 'p' }]
		const loading = []
		const pending = target.refresh()
		loading.push(target.loading)
		await pending
		loading.push(target.loading)
		runs[name] = { calls: rec.calls.slice(), loading, error: target.error, decisions: target.decisions.length }
	}
	rec.failure = null
	out.refresh = runs
	return out
}

describe('what the decisions leaves share', () => {
	it('declares, reads, links and loads exactly as recorded', async () => {
		const real = await siblings()
		const actual = {}
		for (const file of ['CnDecisionsTab.vue', 'CnDecisionsWidget.vue']) {
			actual[file] = await record(file, real)
		}
		if (process.env.RECORD_DECISIONS_LEAF_GOLDEN === '1') {
			fs.writeFileSync(GOLDEN, JSON.stringify(actual, null, 2) + '\n')
		}
		expect(JSON.parse(JSON.stringify(actual))).toEqual(JSON.parse(fs.readFileSync(GOLDEN, 'utf8')))
	})
})
