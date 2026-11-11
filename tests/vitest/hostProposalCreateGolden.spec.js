/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of creating a proposal from the decisions tab and the decisions
 * widget on a host object: the decision written, the refresh, and the state
 * left behind, with no host, a create already running, a success and two
 * kinds of failure.
 *
 * @spec exclude golden test of the create-proposal handlers, no behaviour of its own
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import * as decisionLink from '../../src/integrations/decisionLink.js'
import { loadSfcScript } from './helpers/sfcScript.js'

const GOLDEN = path.resolve(
	__dirname,
	'../fixtures/host-proposal-create-golden.json',
)
const INTEGRATIONS = path.resolve(__dirname, '../../src/integrations')
const SIBLINGS = {}

const rec = vi.hoisted(() => ({ calls: [], failure: null }))
const calls = rec.calls

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: async (url, payload) => {
			const { decisionDate, ...rest } = payload
			rec.calls.push({
				fn: 'post',
				url,
				payload: rest,
				dated: typeof decisionDate === 'string',
			})
			if (rec.failure !== null) {
				throw rec.failure
			}
			return { data: { id: 'new' } }
		},
		get: async () => ({ data: {} }),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (u) => u,
	generateOcsUrl: (u) => u,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => text,
	translatePlural: (_app, a) => a,
}))

/**
 * Load a component with every import answered by a stand-in, and the real
 * decision-link module (with its create call recorded).
 *
 * @param {string} file The component file.
 *
 * @return {object} The component options.
 */
function load(file) {
	const full = path.join(INTEGRATIONS, file)
	const stub = new Proxy(
		{},
		{ get: (_t, key) => (key === 'default' ? {} : () => ({})) },
	)
	const known = {
		'./decisionLink.js': decisionLink,
		'@nextcloud/l10n': { translate: (_app, text) => text },
	}
	const modules = {}
	for (const [, spec] of fs
		.readFileSync(full, 'utf8')
		.matchAll(/^import\s+[\s\S]*?\s+from\s+'([^']+)'/gm)) {
		modules[spec] =
			known[spec] || (spec.startsWith('./') ? loadRealSibling(spec) : stub)
	}
	return loadSfcScript(full, modules)
}

/**
 * A sibling module of the components, loaded for real.
 *
 * @param {string} spec The relative specifier.
 *
 * @return {object} The module namespace.
 */
function loadRealSibling(spec) {
	return SIBLINGS[spec] || {}
}

/**
 * Run onCreate on one case.
 *
 * @param {object} component The component options.
 * @param {object} item The case.
 *
 * @return {Promise<object>} The calls and the state.
 */
async function run(component, item) {
	calls.length = 0
	rec.failure = item.failure ?? null
	const vm = {
		hostObjectId: 'host-1',
		hostRegister: 'reg',
		hostSchema: 'case',
		objectLabel: 'Case 1',
		creating: false,
		createOpen: true,
		error: 'old error',
		...item.vm,
		refresh: async () => {
			calls.push({ fn: 'refresh' })
		},
	}
	const methods = Object.assign(
		{},
		...(component.mixins || []).map((m) => m.methods || {}),
		component.methods,
	)
	await methods.onCreate.call(vm, item.formData)
	const { refresh, ...state } = vm
	return { calls: [...calls], state }
}

const CASES = {
	success: {
		formData: { title: 'Budget', text: 'Raise it', decisionType: 'proposal' },
	},
	'success-defaults': { formData: null, vm: { objectLabel: '' } },
	'no-host': { formData: { title: 'x' }, vm: { hostObjectId: '' } },
	'already-creating': { formData: { title: 'x' }, vm: { creating: true } },
	'fails-with-message': {
		formData: { title: 'x' },
		failure: new Error('server said no'),
	},
	'fails-without-message': { formData: { title: 'x' }, failure: {} },
}

describe('creating a proposal on a host object', () => {
	it('writes the recorded decision and leaves the recorded state', async () => {
		SIBLINGS['./leafReport.js'] =
			await import('../../src/integrations/leafReport.js')
		SIBLINGS['./hostWording.js'] =
			await import('../../src/integrations/hostWording.js')
		SIBLINGS['./decisionCard.js'] =
			await import('../../src/integrations/decisionCard.js')
		SIBLINGS['./hostProposalCreate.js'] =
			await import('../../src/integrations/hostProposalCreate.js')
		SIBLINGS['./hostDecisions.js'] =
			await import('../../src/integrations/hostDecisions.js')
		const actual = {}
		for (const file of ['CnDecisionsTab.vue', 'CnDecisionsWidget.vue']) {
			const component = load(file)
			for (const [name, item] of Object.entries(CASES)) {
				actual[`${file} ${name}`] = await run(component, item)
			}
		}
		if (process.env.RECORD_HOST_PROPOSAL_GOLDEN === '1') {
			fs.writeFileSync(GOLDEN, JSON.stringify(actual, null, 2) + '\n')
		}
		const golden = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
		expect(Object.keys(actual)).toEqual(Object.keys(golden))
		for (const key of Object.keys(golden)) {
			expect(JSON.parse(JSON.stringify(actual[key])), key).toEqual(golden[key])
		}
	})
})
