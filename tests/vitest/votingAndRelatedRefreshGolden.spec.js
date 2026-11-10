/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the `refresh()` loaders of the decision voting tab and the
 * related-decisions tab: which store calls they make, in which order and with
 * which arguments, and the state they leave behind, over a normal load, gaps
 * in the data and a failing store. Pinned before the loaders were split into
 * named steps.
 *
 * @spec exclude golden test of tab loaders, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript, recordingStores } from './helpers/sfcScript.js'

const TABS = path.resolve(__dirname, '../../src/components/tabs')

/**
 * Run one tab's refresh() against recorded stores.
 *
 * @param {object} tab The tab under test: file, module doubles and props.
 * @param {(call: object) => *} answer The store answers.
 *
 * @return {Promise<object>} The calls and the resulting state.
 */
async function runRefresh(tab, answer) {
	const stores = recordingStores(answer)
	const component = loadSfcScript(path.join(TABS, tab.file), {
		...tab.modules,
		'./useRelationStore.js': { ensureRelationType: stores.ensureRelationType },
	})
	const vm = instanceOf(component, tab.props)
	await vm.refresh()
	const state = {}
	for (const key of Object.keys(component.data())) {
		state[key] = vm[key]
	}
	return { calls: stores.calls, state }
}

function VOTING (objectId) {
  return {
	file: 'DecisionVotingTab.vue',
	props: { objectId },
	modules: {
		'@conduction/nextcloud-vue': {
			CnDataTable: {},
			CnNoteCard: {},
			CnStatusBadge: {},
		},
		'../../utils/rankedBallot.js': {
			withRankingText: (list, round) =>
				list.map((vote) => ({ ...vote, round: round.id })),
		},
	},
}
}

function RELATED (objectId) {
  return {
	file: 'RelatedDecisionsTab.vue',
	props: { objectId },
	modules: {
		'@conduction/nextcloud-vue': {
			CnDeleteDialog: {},
			CnNoteCard: {},
			CnStatusBadge: {},
		},
		'@nextcloud/vue': { NcButton: {} },
		'vue-material-design-icons/Plus.vue': { default: {} },
		'vue-material-design-icons/TrashCanOutline.vue': { default: {} },
		'../../modals/RelatedDecisionAddModal.vue': { default: {} },
	},
}
}

describe('DecisionVotingTab refresh()', () => {
	it('does nothing without an object id', async () => {
		const run = await runRefresh(VOTING(''), () => {
			throw new Error('no call expected')
		})
		expect(run.calls).toEqual([])
		expect(run.state.loading).toBe(false)
	})

	it('loads the rounds of the linked motion and their votes', async () => {
		const run = await runRefresh(VOTING('d1'), (call) => {
			if (call.fn === 'fetchObject') return { id: 'd1', motion: { id: 'm7' } }
			if (call.type === 'voting-round') return [{ id: 'r1' }, { id: 'r2' }]
			if (call.params.votingRound === 'r1') return [{ id: 'v1' }, { id: 'v2' }]
			return { not: 'a list' }
		})
		expect(run.calls).toMatchSnapshot()
		expect(run.state).toMatchSnapshot()
	})

	it('reads a motion stored as a plain id, including 0', async () => {
		const run = await runRefresh(VOTING('d1'), (call) => {
			if (call.fn === 'fetchObject') return { id: 'd1', motion: 0 }
			return null
		})
		expect(run.calls).toMatchSnapshot()
		expect(run.state).toMatchSnapshot()
	})

	it('clears rounds and votes when no motion is linked', async () => {
		for (const decision of [null, { motion: '' }, { motion: null }, {}]) {
			const run = await runRefresh(VOTING('d1'), (call) =>
				call.fn === 'fetchObject' ? decision : [{ id: 'r1' }],
			)
			expect(run.calls.length).toBe(1)
			expect(run.state).toEqual({
				loading: false,
				error: '',
				motionId: '',
				rounds: [],
				votes: [],
			})
		}
	})

	it('stringifies a motion object without an id', async () => {
		const run = await runRefresh(VOTING('d1'), (call) =>
			call.fn === 'fetchObject' ? { motion: { id: '' } } : [],
		)
		expect(run.state.motionId).toBe('[object Object]')
		expect(run.calls.length).toBe(2)
	})

	it('reports a failing store, falling back to the default message', async () => {
		const failing = await runRefresh(VOTING('d1'), () => {
			throw new Error('boom')
		})
		expect(failing.state.error).toBe('boom')
		expect(failing.state.loading).toBe(false)
		const silent = await runRefresh(VOTING('d1'), (call) => {
			if (call.fn === 'fetchObject') return { motion: 'm1' }
			throw new Error('')
		})
		expect(silent.state).toMatchSnapshot()
	})
})

describe('RelatedDecisionsTab refresh()', () => {
	it('does nothing without an object id', async () => {
		const run = await runRefresh(RELATED(''), () => {
			throw new Error('no call expected')
		})
		expect(run.calls).toEqual([])
	})

	it('resolves outgoing relations and finds incoming ones', async () => {
		const all = [
			{ id: 'd1', supersedes: ['d2', { id: 'd3' }, 'gone'], amends: 'not-a-list' },
			{ id: 'd2', repeals: [{ uuid: 'd1' }] },
			{ uuid: 'd3', refersTo: ['d1', 'd2'], implements: [null, 'd1'] },
			{ id: 'd4', amends: [{ id: 'other' }] },
		]
		const run = await runRefresh(RELATED('d1'), (call) =>
			call.fn === 'fetchObject' ? all[0] : all,
		)
		expect(run.calls).toMatchSnapshot()
		expect(run.state).toMatchSnapshot()
	})

	it('falls back to the uuid and then the object id for its own id', async () => {
		const all = [{ id: 'x', supersedes: ['u1', 'p9'] }]
		const byUuid = await runRefresh(RELATED('p9'), (call) =>
			call.fn === 'fetchObject' ? { uuid: 'u1' } : all,
		)
		expect(byUuid.state.incoming).toMatchSnapshot()
		const byProp = await runRefresh(RELATED('p9'), (call) =>
			call.fn === 'fetchObject' ? null : all,
		)
		expect(byProp.state).toMatchSnapshot()
		const noList = await runRefresh(RELATED('p9'), (call) =>
			call.fn === 'fetchObject' ? { id: 'p9', supersedes: ['x'] } : null,
		)
		expect(noList.state).toMatchSnapshot()
	})

	it('reports a failing store, falling back to the default message', async () => {
		const failing = await runRefresh(RELATED('d1'), () => {
			throw new Error('boom')
		})
		expect(failing.state).toMatchSnapshot()
		const silent = await runRefresh(RELATED('d1'), () => {
			throw new Error('')
		})
		expect(silent.state.error).toBe('Failed to load related decisions.')
	})
})
