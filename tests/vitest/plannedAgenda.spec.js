/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The long-term agenda rules: period order and expiry, the board columns, and
 * the reschedule, lapse and realise writes.
 *
 * @spec openspec/changes/termijnagenda
 */

import { describe, expect, it, vi } from 'vitest'
import {
	boardColumns,
	buildLapse,
	buildRealise,
	buildReschedule,
	comparePeriods,
	groupByPeriod,
	isOverdue,
	isValidPeriod,
	nextPeriod,
	overdueItems,
	ownerProblem,
	periodLastDay,
	realisationCandidates,
	suggestRealisations,
} from '../../src/utils/plannedAgenda.js'

const JAN_15_2027 = new Date(2027, 0, 15, 12)

describe('planned periods', () => {
	it('accepts quarters and months only', () => {
		expect(isValidPeriod('2026-Q4')).toBe(true)
		expect(isValidPeriod('2026-11')).toBe(true)
		expect(isValidPeriod('november 2026')).toBe(false)
		expect(isValidPeriod('2026-Q5')).toBe(false)
		expect(isValidPeriod('2026-13')).toBe(false)
		expect(isValidPeriod('')).toBe(false)
		expect(isValidPeriod(null)).toBe(false)
	})

	it('maps a period to its last day', () => {
		expect(periodLastDay('2026-11')).toBe('2026-11-30')
		expect(periodLastDay('2026-Q4')).toBe('2026-12-31')
		expect(periodLastDay('2028-02')).toBe('2028-02-29')
		expect(periodLastDay('2027-Q1')).toBe('2027-03-31')
		expect(periodLastDay('soon')).toBeNull()
	})

	it('sorts mixed granularity chronologically, month before its quarter on a tie', () => {
		const sorted = ['2027-Q1', '2026-Q4', 'later', '2026-12', '2026-11'].sort(comparePeriods)
		expect(sorted).toEqual(['2026-11', '2026-12', '2026-Q4', '2027-Q1', 'later'])
	})

	it('steps to the next period at the same granularity', () => {
		expect(nextPeriod('2026-Q4')).toBe('2027-Q1')
		expect(nextPeriod('2026-Q2')).toBe('2026-Q3')
		expect(nextPeriod('2026-12')).toBe('2027-01')
		expect(nextPeriod('2026-09')).toBe('2026-10')
		expect(nextPeriod('x')).toBeNull()
	})
})

describe('overdue', () => {
	it('is overdue when the period ended, it is open and nothing realised it', () => {
		expect(isOverdue({ plannedPeriod: '2026-Q4', lifecycle: 'planned' }, JAN_15_2027)).toBe(true)
		expect(isOverdue({ plannedPeriod: '2026-Q4', lifecycle: 'realised' }, JAN_15_2027)).toBe(false)
		expect(isOverdue({ plannedPeriod: '2026-Q4', lifecycle: 'lapsed' }, JAN_15_2027)).toBe(false)
		expect(isOverdue({ plannedPeriod: '2026-Q4', lifecycle: 'postponed', realisedAgendaItem: 'a1' }, JAN_15_2027)).toBe(false)
		expect(isOverdue({ plannedPeriod: '2027-Q1', lifecycle: 'planned' }, JAN_15_2027)).toBe(false)
		expect(isOverdue({ plannedPeriod: '2027-01', lifecycle: 'planned' }, JAN_15_2027)).toBe(false)
		expect(isOverdue(null, JAN_15_2027)).toBe(false)
	})

	it('is not overdue on the last day itself', () => {
		expect(isOverdue({ plannedPeriod: '2026-12', lifecycle: 'planned' }, new Date(2026, 11, 31, 23))).toBe(false)
	})

	it('counts only the overdue items', () => {
		const items = [
			{ id: 'a', plannedPeriod: '2026-Q2', lifecycle: 'postponed' },
			{ id: 'b', plannedPeriod: '2026-Q2', lifecycle: 'realised' },
			{ id: 'c', plannedPeriod: '2027-Q4', lifecycle: 'planned' },
			{ id: 'd', plannedPeriod: '2026-10', lifecycle: 'planned' },
		]
		expect(overdueItems(items, JAN_15_2027).map((item) => item.id)).toEqual(['a', 'd'])
		expect(overdueItems(undefined, JAN_15_2027)).toEqual([])
	})
})

describe('board', () => {
	it('builds ordered columns from the items plus empty quarters after the last', () => {
		const items = [
			{ plannedPeriod: '2027-Q1' },
			{ plannedPeriod: '2026-11' },
			{ plannedPeriod: '2026-Q4' },
			{ plannedPeriod: 'bogus' },
		]
		expect(boardColumns(items, 2)).toEqual(['2026-11', '2026-Q4', '2027-Q1', '2027-Q2', '2027-Q3'])
	})

	it('continues in quarters after a month column', () => {
		expect(boardColumns([{ plannedPeriod: '2026-11' }], 1)).toEqual(['2026-11', '2027-Q1'])
	})

	it('starts at the current quarter when the body has no items', () => {
		expect(boardColumns([], 1, JAN_15_2027)).toEqual(['2027-Q1', '2027-Q2'])
	})

	it('groups items under their column', () => {
		const a = { id: 'a', plannedPeriod: '2026-Q4' }
		const b = { id: 'b', plannedPeriod: '2027-Q1' }
		const c = { id: 'c', plannedPeriod: 'bogus' }
		expect(groupByPeriod([a, b, c], ['2026-Q4', '2027-Q1', '2027-Q2'])).toEqual({
			'2026-Q4': [a],
			'2027-Q1': [b],
			'2027-Q2': [],
		})
	})
})

describe('reschedule', () => {
	const item = {
		id: 'x',
		subject: 'Herziening parkeerbeleid',
		governanceBody: 'b1',
		plannedPeriod: '2026-Q4',
		lifecycle: 'planned',
		shiftHistory: [],
	}

	it('postpones, moves the period and appends one history entry', () => {
		const saved = buildReschedule(item, {
			toPeriod: '2027-Q1',
			reason: '  Wacht op uitkomst regionale samenwerking ',
			user: 'griffier',
			now: Date.UTC(2026, 10, 14, 10),
		})
		expect(saved).toEqual({
			...item,
			lifecycle: 'postponed',
			plannedPeriod: '2027-Q1',
			shiftHistory: [
				{
					van: '2026-Q4',
					toPeriod: '2027-Q1',
					reason: 'Wacht op uitkomst regionale samenwerking',
					door: 'griffier',
					op: '2026-11-14T10:00:00.000Z',
				},
			],
		})
		expect(item.shiftHistory).toEqual([])
	})

	it('keeps the first entry unchanged on a second shift', () => {
		const first = buildReschedule(item, { toPeriod: '2027-Q1', reason: 'a', user: 'u', now: 0 })
		const second = buildReschedule(first, { toPeriod: '2027-Q2', reason: 'b', user: 'u', now: 1000 })
		expect(second.shiftHistory).toHaveLength(2)
		expect(second.shiftHistory[0]).toEqual(first.shiftHistory[0])
		expect(second.shiftHistory[1]).toMatchObject({ van: '2027-Q1', toPeriod: '2027-Q2', reason: 'b' })
	})

	it('refuses without a reason, to the same or an invalid period, or when settled', () => {
		expect(() => buildReschedule(item, { toPeriod: '2027-Q1', reason: '  ' })).toThrow('A reason is required.')
		expect(() => buildReschedule(item, { toPeriod: '2026-Q4', reason: 'r' })).toThrow('Choose a different period.')
		expect(() => buildReschedule(item, { toPeriod: 'next year', reason: 'r' })).toThrow('Choose a period')
		expect(() => buildReschedule({ ...item, lifecycle: 'realised' }, { toPeriod: '2027-Q1', reason: 'r' })).toThrow('cannot be rescheduled')
	})

	it('starts a history when the item has none', () => {
		const saved = buildReschedule({ ...item, shiftHistory: undefined }, { toPeriod: '2027-03', reason: 'r', user: 'u', now: 0 })
		expect(saved.shiftHistory).toHaveLength(1)
	})
})

describe('lapse and realise', () => {
	const item = { id: 'x', subject: 'Parkeerbeleid binnenstad', plannedPeriod: '2026-Q4', lifecycle: 'postponed' }

	it('lapses with the reason and refuses without one', () => {
		expect(buildLapse(item, ' Opgegaan in omgevingsvisie ')).toEqual({
			...item,
			lifecycle: 'lapsed',
			reasonExpired: 'Opgegaan in omgevingsvisie',
		})
		expect(() => buildLapse(item, '')).toThrow('A reason is required.')
		expect(() => buildLapse({ ...item, lifecycle: 'lapsed' }, 'r')).toThrow('already settled')
	})

	it('realises with a link and refuses without one', () => {
		expect(buildRealise(item, { agendaItem: 'a1' })).toEqual({
			...item,
			lifecycle: 'realised',
			realisedAgendaItem: 'a1',
			realisedDecision: null,
		})
		expect(buildRealise(item, { decision: 'd1' })).toMatchObject({ realisedAgendaItem: null, realisedDecision: 'd1' })
		expect(() => buildRealise(item, {})).toThrow('Link the agenda item or decision')
		expect(() => buildRealise({ ...item, lifecycle: 'realised' }, { agendaItem: 'a1' })).toThrow('already settled')
	})

	it('suggests the agenda items whose title shares the subject first', () => {
		const agenda = [
			{ id: '1', title: 'Begroting 2027' },
			{ id: '2', title: 'Parkeerbeleid' },
			{ id: '3', title: 'Herziening parkeerbeleid binnenstad' },
		]
		expect(suggestRealisations(item, agenda).map((a) => a.id)).toEqual(['3', '2', '1'])
		expect(suggestRealisations(item, agenda, 1).map((a) => a.id)).toEqual(['3'])
		expect(suggestRealisations(item, null)).toEqual([])
	})
})

describe('owner rule', () => {
	it('needs a person when a portfolio holder owns the item', () => {
		expect(ownerProblem({ ownerType: 'portfolio-holder' })).toMatch('needs the person')
		expect(ownerProblem({ ownerType: 'portfolio-holder', owner: 'p1' })).toBeNull()
		expect(ownerProblem({ ownerType: 'executive-board' })).toBeNull()
	})
})

describe('realisation candidates', () => {
	it('reads the body\'s meetings, then their agenda items and decisions', async () => {
		const fetch = vi.fn(async (type) => {
			if (type === 'meeting') return [{ id: 'm1' }, { id: 'm2' }, {}]
			if (type === 'agenda-item') return [{ id: 'a1' }]
			return [{ id: 'd1' }]
		})
		expect(await realisationCandidates(fetch, 'b1')).toEqual({ agendaItems: [{ id: 'a1' }], decisions: [{ id: 'd1' }] })
		expect(fetch.mock.calls).toEqual([
			['meeting', { governanceBody: 'b1' }],
			['agenda-item', { meeting: ['m1', 'm2'] }],
			['decision', { meeting: ['m1', 'm2'] }],
		])
	})

	it('reads nothing without a body and stops when it has no meetings', async () => {
		const fetch = vi.fn(async () => [])
		expect(await realisationCandidates(fetch, '')).toEqual({ agendaItems: [], decisions: [] })
		expect(fetch).not.toHaveBeenCalled()
		expect(await realisationCandidates(fetch, 'b1')).toEqual({ agendaItems: [], decisions: [] })
		expect(fetch).toHaveBeenCalledTimes(1)
	})

	it('treats a failed read as no candidates', async () => {
		const fetch = vi.fn(async (type) => {
			if (type === 'meeting') return [{ id: 'm1' }]
			if (type === 'decision') throw new Error('403')
			return 'not a list'
		})
		expect(await realisationCandidates(fetch, 'b1')).toEqual({ agendaItems: [], decisions: [] })
	})
})
