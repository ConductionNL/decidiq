/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the `refresh()` loaders of three governance tabs: which store
 * calls they make, in which order and with which arguments, and the state they
 * leave behind, over a normal load, gaps in the data and a failing store.
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
 * @param {object} tab The tab under test: file, extra module doubles, props and instance overrides.
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
	Object.assign(vm, tab.overrides ? tab.overrides(stores.calls) : {})
	await vm.refresh()
	const state = {}
	for (const key of Object.keys(component.data())) {
		state[key] = vm[key]
	}
	return { calls: stores.calls, state }
}

const VOTES = {
	file: 'MeetingVotesTab.vue',
	props: { objectId: 'm1' },
	modules: {
		'@conduction/nextcloud-vue': {
			CnDataTable: {},
			CnNoteCard: {},
			CnStatusBadge: {},
		},
		'@nextcloud/router': { generateUrl: (u) => u },
		'../../utils/voteBreakdown.js': {
			breakdownUrl: (id) => id,
			factionRows: () => '',
		},
	},
	overrides: (calls) => ({
		factionSummary: async (roundId) => {
			calls.push({ fn: 'factionSummary', roundId })
			return `factions of ${roundId}`
		},
	}),
}

const VOTE_DATA = {
	'agenda-item': [{ id: 'a1' }, { uuid: 'a2' }, {}, null],
	motion: {
		a1: [
			{ id: 'mo1', title: 'Budget', motionType: 'amendment' },
			{ uuid: 'mo2' },
			{ title: 'no id' },
		],
		a2: null,
	},
	'voting-round': {
		mo1: [
			{
				id: 'r1',
				votesFor: 5,
				votesAgainst: 2,
				votesAbstain: 1,
				result: 'adopted',
				closedAt: '2026-01-02',
			},
			{ uuid: 'r2', votesFor: 0, openedAt: '2026-01-01' },
		],
		mo2: [{ id: 'r3', votesAgainst: null }],
	},
}

/**
 * Store answers for the votes tab, keyed by the parent id in the params.
 *
 * @param {object} call The store call.
 *
 * @return {*} The payload.
 */
function votesAnswer(call) {
	if (call.schema === 'agenda-item') return VOTE_DATA['agenda-item']
	if (call.schema === 'motion') return VOTE_DATA.motion[call.params.agendaItem]
	return VOTE_DATA['voting-round'][call.params.motion]
}

const EFFICIENCY = {
	file: 'GovernanceBodyEfficiencyTab.vue',
	props: { objectId: 'b1' },
	modules: {
		'@conduction/nextcloud-vue': { CnNoteCard: {} },
		'../../utils/meetingAnalytics.js': {},
		'../../utils/meetingCost.js': { formatEur: (v) => String(v) },
	},
}

const EFFICIENCY_DATA = {
	'governance-body': { hourlyRate: '42.5' },
	meeting: [
		{ id: 'm1', governanceBody: 'b1' },
		{ id: 'm2', '@self': { relations: { governanceBody: 'b1' } } },
		{ id: 'm3', governanceBody: 'other' },
	],
	'agenda-item': [
		{ id: 'i1', meeting: 'm1' },
		{ id: 'i2', '@self': { relations: { meeting: 'm2' } } },
		{ id: 'i3', meeting: 'm3' },
		null,
	],
	participant: [
		{ id: 'p1', governanceBody: 'b1' },
		{ id: 'p2', meeting: 'm2' },
		{ id: 'p3', '@self': { relations: { meeting: 'm1' } } },
		{ id: 'p4', meeting: 'm3' },
	],
	'engagement-record': [
		{ id: 'e1', meeting: 'm1' },
		{ id: 'e2', meeting: 'm9' },
	],
}

const COMPOSITION = {
	file: 'GovernanceBodyCompositionTab.vue',
	props: { objectId: 7 },
	modules: {
		'@nextcloud/vue': { NcButton: {} },
		'../../modals/BoardCompetenceModal.vue': { default: {} },
		'../../modals/MemberCompetenceModal.vue': { default: {} },
		'../../utils/boardComposition.js': {
			compositionFigures: () => ({}),
			currentSeats: (memberships) => memberships.filter((m) => !m.endDate),
			skillsMatrix: () => ({}),
			targetResults: () => [],
		},
		'../../utils/competenceApi.js': {
			competencePath: () => '',
			competenceRequest: async () => ({}),
		},
	},
}

const COMPOSITION_DATA = {
	'governance-body': { diversityTargets: [{ dimension: 'gender', value: 'f' }] },
	membership: [
		{ id: 's1', person: 'p1' },
		{ id: 's2', person: 'p2' },
		{ id: 's3', person: 'p1' },
		{ id: 's4' },
		{ id: 's5', person: 'p3', endDate: '2025-01-01' },
	],
	'board-competence': [{ id: 'c1' }],
	person: { p1: { name: 'Ann' }, p2: null },
	'member-competence': {
		s1: [{ id: 'h1' }, null],
		s2: null,
		s3: [],
		s4: [{ id: 'h2' }],
	},
}

/**
 * Store answers for the composition tab.
 *
 * @param {object} call The store call.
 *
 * @return {*} The payload.
 */
function compositionAnswer(call) {
	if (call.schema === 'person') return COMPOSITION_DATA.person[call.id]
	if (call.schema === 'member-competence') {
		return COMPOSITION_DATA['member-competence'][call.params.membership]
	}
	return COMPOSITION_DATA[call.schema]
}

/**
 * Answers that throw for one schema and delegate the rest.
 *
 * @param {string} schema The failing schema.
 * @param {(call: object) => *} answer The other answers.
 * @param {string} [message] The error message; empty for an error without one.
 *
 * @return {(call: object) => *} The answers.
 */
function failing(schema, answer, message = 'store down') {
	return (call) => {
		if (call.schema === schema) throw new Error(message)
		return answer(call)
	}
}

describe('MeetingVotesTab refresh()', () => {
	it('walks agenda items, motions and rounds', async () => {
		expect(await runRefresh(VOTES, votesAnswer)).toMatchSnapshot()
	})

	it('clears the rounds when the meeting has no agenda items', async () => {
		expect(await runRefresh(VOTES, () => [])).toMatchSnapshot()
		expect(await runRefresh(VOTES, () => ({ not: 'a list' }))).toMatchSnapshot()
	})

	it('reports a failing store and keeps the rounds it had', async () => {
		expect(
			await runRefresh(VOTES, failing('voting-round', votesAnswer)),
		).toMatchSnapshot()
		expect(
			await runRefresh(VOTES, failing('motion', votesAnswer, '')),
		).toMatchSnapshot()
	})

	it('fails on a null round', async () => {
		const answer = (call) =>
			call.schema === 'voting-round' ? [null] : votesAnswer(call)
		expect(await runRefresh(VOTES, answer)).toMatchSnapshot()
	})

	it('does nothing without a meeting', async () => {
		expect(
			await runRefresh({ ...VOTES, props: { objectId: '' } }, votesAnswer),
		).toMatchSnapshot()
	})
})

describe('GovernanceBodyEfficiencyTab refresh()', () => {
	it('loads the body and filters every collection to its meetings', async () => {
		expect(
			await runRefresh(EFFICIENCY, (c) => EFFICIENCY_DATA[c.schema]),
		).toMatchSnapshot()
	})

	it('falls back to a zero hourly rate', async () => {
		for (const rate of [{ hourlyRate: -3 }, { hourlyRate: 'x' }, null]) {
			const answer = (c) =>
				c.schema === 'governance-body' ? rate : EFFICIENCY_DATA[c.schema]
			expect((await runRefresh(EFFICIENCY, answer)).state.hourlyRate).toBe(0)
		}
		const result = await runRefresh(
			EFFICIENCY,
			failing('governance-body', (c) => EFFICIENCY_DATA[c.schema]),
		)
		expect(result).toMatchSnapshot()
	})

	it('tolerates empty collections', async () => {
		const answer = (c) => (c.schema === 'governance-body' ? {} : null)
		expect(await runRefresh(EFFICIENCY, answer)).toMatchSnapshot()
	})

	it('stops at the first failing collection', async () => {
		for (const schema of [
			'meeting',
			'agenda-item',
			'participant',
			'engagement-record',
		]) {
			const result = await runRefresh(
				EFFICIENCY,
				failing(schema, (c) => EFFICIENCY_DATA[c.schema]),
			)
			expect(result).toMatchSnapshot(schema)
		}
		const silent = await runRefresh(
			EFFICIENCY,
			failing('meeting', (c) => EFFICIENCY_DATA[c.schema], ''),
		)
		expect(silent.state.error).toBe('Failed to load analytics.')
	})

	it('does nothing without a body', async () => {
		expect(
			await runRefresh({ ...EFFICIENCY, props: { objectId: 0 } }, () => []),
		).toMatchSnapshot()
	})
})

describe('GovernanceBodyCompositionTab refresh()', () => {
	it('loads the body, current seats, people and held competences', async () => {
		expect(await runRefresh(COMPOSITION, compositionAnswer)).toMatchSnapshot()
	})

	it('tolerates a body and collections that come back empty', async () => {
		const answer = (call) =>
			call.schema === 'membership' ? null : compositionAnswer(call)
		expect(await runRefresh(COMPOSITION, answer)).toMatchSnapshot()
		const noBody = (call) =>
			call.schema === 'governance-body' ? null : compositionAnswer(call)
		const competencesGone = (call) =>
			call.schema === 'board-competence' ? undefined : noBody(call)
		expect(await runRefresh(COMPOSITION, competencesGone)).toMatchSnapshot()
	})

	it('reports a failing store and assigns nothing', async () => {
		for (const schema of ['governance-body', 'person', 'member-competence']) {
			expect(
				await runRefresh(COMPOSITION, failing(schema, compositionAnswer)),
			).toMatchSnapshot(schema)
		}
		const silent = await runRefresh(
			COMPOSITION,
			failing('person', compositionAnswer, ''),
		)
		expect(silent.state.error).toBe('The composition could not be loaded.')
	})

	it('does nothing without a body', async () => {
		expect(
			await runRefresh(
				{ ...COMPOSITION, props: { objectId: '' } },
				compositionAnswer,
			),
		).toMatchSnapshot()
	})
})
