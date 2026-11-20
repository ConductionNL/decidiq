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
import { routeFromFlowRun } from '../../src/utils/routeFromFlowRun.js'
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
			return schema === 'approval-route' ? answers.route : answers.decision
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
		'@nextcloud/axios': {
			default: {
				async get(url, config) {
					log.push(['get', url, config.params])
					return { data: { results: answers.runTasks || [] } }
				},
			},
		},
		'@nextcloud/router': { generateUrl: (url) => url },
		'../../utils/routeFromFlowRun.js': { routeFromFlowRun },
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

	// approval-routes-on-flow-user-tasks, REQ-ARF-007: a route sent as a flow
	// is read from the run's tasks, not from stage rows.
	it('reads a route sent as a flow from its run', async () => {
		const { vm, log } = tab({
			decision: { id: 'd-1', lifecycle: 'proposed', approvalEngine: 'flow', approvalRun: 'run-1', approvalRoute: 'route-1' },
			route: { id: 'route-1', steps: [{ order: 1, actorType: 'person', actor: 'wouter', label: 'Ondertekening' }, { order: 2, actorType: 'body', actor: 'raad' }] },
			runTasks: [{ uuid: 't1', state: 'completed', outcome: 'approved', completedBy: 'lisa', metadata: { flowNode: 'step-1-1' } }],
		})

		await vm.refresh()

		expect(log).toContainEqual(['get', '/apps/openregister/api/flow-tasks', { runUuid: 'run-1', limit: 200 }])
		expect(log).toContainEqual(['fetchObject', 'approval-route', 'approval-route', 'route-1'])
		expect(log.some((entry) => entry[2] === 'decision-stage')).toBe(false)
		expect(vm.stages.map((s) => s.status)).toEqual(['decided', 'pending'])
		expect(vm.stages[0].substitute).toBe('lisa')
		expect(vm.currentStage).toBe('')
	})

	// REQ-ARF-004: a step sent back shows its return as an outcome of its own.
	it('labels a returned step', () => {
		const { vm } = tab()
		vm.t = (app, text) => `${app}:${text}`

		expect(vm.outcomeLabel('returned')).toBe('decidiq:returned')
		expect(vm.outcomeLabel('approved')).toBe('decidiq:Approved')
		expect(vm.outcomeLabel('something-else')).toBe('something-else')
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
