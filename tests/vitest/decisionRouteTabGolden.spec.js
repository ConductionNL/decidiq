/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the decision route tab: the store reads `refresh()` makes
 * and the state it leaves, which decision repeals or supersedes this one,
 * the decision-maker name of a stage and the repealed / superseded notice.
 * Pinned before those methods were split into named steps.
 *
 * @spec exclude golden test of the decision route tab, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/DecisionRouteTab.vue',
)

/**
 * Build the tab over a recording store.
 *
 * @param {object} answers The store's answers (the decision, the candidate
 *   decisions, other collections by schema) or a failure.
 * @return {{component: object, vm: object, log: Array}} The tab and its log.
 */
function tab(answers = {}) {
	const log = []
	const store = (type) => ({
		async fetchObject(schema, id) {
			log.push(['fetchObject', type, schema, id])
			if ('fail' in answers) throw new Error(answers.fail)
			return answers.decision
		},
		async fetchCollection(schema, params) {
			log.push(['fetchCollection', type, schema, params])
			return schema === 'decision' ? answers.candidates : answers[schema]
		},
	})
	const component = loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': { CnNoteCard: {}, CnStatusBadge: {} },
		'@nextcloud/vue': { NcButton: {} },
		'./useRelationStore.js': { ensureRelationType: store },
	})
	const vm = instanceOf(component, { objectId: answers.objectId ?? 'd-1' })
	return { component, vm, log }
}

/**
 * The tab's state as plain data.
 *
 * @param {object} vm The instance.
 * @return {object} The state.
 */
function state(vm) {
	const keys = ['loading', 'error', 'lifecycle', 'currentStage', 'stages']
	return {
		...Object.fromEntries(keys.map((k) => [k, vm[k]])),
		effectiveStatus: vm.effectiveStatus,
		effectingDecision: vm.effectingDecision,
		openActionItemCount: vm.openActionItemCount,
	}
}

const CANDIDATES = [
	{ id: 'c1', supersedes: ['d-1'] },
	{ id: 'c2', repeals: [{ uuid: 'd-1' }] },
	{ id: 'c3', repeals: 'd-1' },
]

describe('DecisionRouteTab golden runs', () => {
	it('refresh', async () => {
		const out = []
		for (const [label, answers] of [
			[
				'loads',
				{
					decision: { id: 'd-1', lifecycle: 'decided', currentStage: 's2' },
					'decision-stage': [{ id: 's2', sequence: 2 }, { id: 's0' }, { id: 's1', sequence: 1 }],
					'action-item': [{ status: 'open' }, { status: 'done' }, { status: 'completed' }, {}],
				},
			],
			['no decision', { 'decision-stage': null }],
			['no id', { objectId: '' }],
			['fails', { fail: 'gone' }],
			['fails silently', { fail: '' }],
		]) {
			const { vm, log } = tab(answers)
			vm.effectiveStatus = 'stale'
			vm.effectingDecision = 'stale'
			await vm.refresh()
			out.push({ label, log, state: state(vm) })
		}
		expect(out).toMatchSnapshot()
	})

	it('deriveEffectiveStatus', async () => {
		const out = []
		for (const [label, decision, candidates] of [
			['repealed wins', { id: 'd-1' }, CANDIDATES],
			['superseded', { uuid: 'd-1' }, CANDIDATES.slice(0, 1)],
			['by object id', null, [{ id: 'x', supersedes: [{ id: 'd-1' }] }]],
			['none', { id: 'other' }, CANDIDATES],
			['nothing read', { id: 'd-1' }, null],
		]) {
			const { vm, log } = tab({ candidates })
			await vm.deriveEffectiveStatus(decision)
			out.push({ label, log, state: state(vm) })
		}
		expect(out).toMatchSnapshot()
	})

	it('makerName and the effective status notice', () => {
		const { component, vm } = tab()
		const names = [
			{ decisionMakerType: 'person', assignedPerson: { name: 'Ann' } },
			{ decisionMakerType: 'person', assignedPerson: { displayName: 'Bob' } },
			{ decisionMakerType: 'person', assignedPerson: {}, assignedBody: { name: 'x' } },
			{ decisionMakerType: 'person' },
			{ assignedBody: { title: 'Board' } },
			{ assignedBody: 'b-1' },
			{},
		].map((stage) => vm.makerName(stage))
		const notice = component.computed.effectiveStatusMessage
		const notices = []
		for (const effectiveStatus of ['repealed', 'superseded']) {
			for (const effectingDecision of [
				null,
				{ enactedAt: '2026-01-02T00:00:00Z', decisionDate: '2025-01-01' },
				{ decisionDate: '2025-01-01' },
				{ enactedAt: '' },
			]) {
				Object.assign(vm, {
					formatDate: (value) => `date(${value})`,
					t: (_app, text, vars = {}) =>
						text.replace(/\{(\w+)\}/g, (_m, key) => `<${vars[key]}>`),
					effectiveStatus,
					effectingDecision,
				})
				notices.push(notice.call(vm))
			}
		}
		expect({ names, notices }).toMatchSnapshot()
	})
})
