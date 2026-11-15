// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The step-down schedule and a member's term (bodies-term-of-office-and-step-down).
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import {
	buildMembershipPayload,
	isActiveMembershipAt,
} from '../../src/components/tabs/useRelationStore.js'
import { memberRowsFor, startOfDay } from '../../src/utils/bodyMembership.js'
import {
	defaultTermStart,
	holdReappointable,
	loadStepDownData,
	membershipTerm,
	pickedTerm,
	REAPPOINTABLE,
	shortDate,
	STEP_DOWN_WINDOWS,
	stepDownLines,
	windowUntil,
} from '../../src/utils/stepDownSchedule.js'

// The store pulls in @nextcloud/vue, which plain vitest cannot resolve; only
// the pure helpers are under test (as in memberRelations.spec.js).
vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))

const NOW = new Date(2026, 9, 3, 10, 0, 0)

const lars = { id: 'p-lars', name: 'Lars de Koning' }
const joost = { id: 'p-joost', name: 'Joost Hendriks' }
const personsById = { [lars.id]: lars, [joost.id]: joost }

const larsMembership = {
	id: 'm-lars',
	person: lars.id,
	endDate: '2030-03-29T00:00:00+02:00',
}
const joostMembership = { id: 'm-joost', person: joost.id }

const deputy = { id: 'pt-deputy', name: 'Plaatsvervanger', reappointable: true }
const treasurer = {
	id: 'pt-treasurer',
	name: 'Penningmeester',
	reappointable: true,
	maxConsecutiveTerms: 2,
}
const positionTypesById = { [deputy.id]: deputy, [treasurer.id]: treasurer }

const larsDeputy = {
	id: 'h-lars',
	membership: larsMembership.id,
	position: deputy.id,
	termNumber: 1,
	endDate: '2027-01-01',
}
const joostTreasurer = {
	id: 'h-joost',
	membership: joostMembership.id,
	position: treasurer.id,
	termNumber: 2,
	endDate: '2027-06-30',
}

/**
 * The schedule of the example body over a window.
 *
 * @param {number|null} until The window's end.
 * @return {Array<object>} The lines.
 */
function schedule(until) {
	return stepDownLines(
		{
			memberships: [larsMembership, joostMembership],
			holds: [larsDeputy, joostTreasurer],
			personsById,
			positionTypesById,
		},
		{ until, now: NOW },
	)
}

describe('stepDownLines (REQ-TOS-002)', () => {
	it('lists a position term ending before a membership term first', () => {
		const lines = schedule(null)

		expect(lines.map((line) => [line.kind, line.name, line.endDate])).toEqual([
			['position-hold', 'Lars de Koning', '2027-01-01'],
			['position-hold', 'Joost Hendriks', '2027-06-30'],
			['membership', 'Lars de Koning', '2030-03-29T00:00:00+02:00'],
		])
		expect(lines[0].position).toBe('Plaatsvervanger')
		expect(lines[0].termNumber).toBe(1)
		expect(lines[2].position).toBe('')
		expect(lines[2].termNumber).toBeNull()
	})

	it('leaves out a term without an end date and one that already ended', () => {
		const lines = stepDownLines(
			{
				memberships: [
					joostMembership,
					{
						id: 'm-old',
						person: lars.id,
						endDate: '2026-10-02T00:00:00Z',
					},
				],
				personsById,
			},
			{ until: null, now: NOW },
		)

		expect(lines).toEqual([])
	})

	it('keeps a term that ends later today', () => {
		const lines = stepDownLines(
			{
				memberships: [
					{
						id: 'm-today',
						person: lars.id,
						endDate: new Date(2026, 9, 3, 0, 0).toISOString(),
					},
				],
				personsById,
			},
			{ until: null, now: NOW },
		)

		expect(lines.map((line) => line.objectId)).toEqual(['m-today'])
	})

	it('stops at the window end', () => {
		const until = windowUntil('next-12-months', null, NOW)

		expect(schedule(until).map((line) => line.objectId)).toEqual([
			'h-lars',
			'h-joost',
		])
	})

	it('orders equal end dates by name', () => {
		const lines = stepDownLines(
			{
				memberships: [
					{ id: 'b', person: lars.id, endDate: '2027-01-01' },
					{ id: 'a', person: joost.id, endDate: '2027-01-01' },
				],
				personsById,
			},
			{ now: NOW },
		)

		expect(lines.map((line) => line.name)).toEqual([
			'Joost Hendriks',
			'Lars de Koning',
		])
	})

	it('returns new lines and leaves its inputs as they were', () => {
		const memberships = [{ ...larsMembership }]
		const before = JSON.stringify(memberships)

		schedule(null)
		stepDownLines({ memberships, personsById }, { now: NOW })

		expect(JSON.stringify(memberships)).toBe(before)
	})
})

describe('holdReappointable (REQ-TOS-003)', () => {
	it('reads last term for a treasurer in the last allowed term', () => {
		expect(holdReappointable(joostTreasurer, treasurer)).toBe(
			REAPPOINTABLE.lastTerm,
		)
		expect(schedule(null)[1].reappointable).toBe('last-term')
	})

	it('reads no when the position type is not reappointable', () => {
		expect(
			holdReappointable(larsDeputy, { ...deputy, reappointable: false }),
		).toBe(REAPPOINTABLE.no)
	})

	it('reads no when the hold itself may not be renewed', () => {
		expect(
			holdReappointable({ ...larsDeputy, reappointable: false }, deputy),
		).toBe(REAPPOINTABLE.no)
	})

	it('reads yes below the maximum and without one', () => {
		expect(
			holdReappointable({ ...joostTreasurer, termNumber: 1 }, treasurer),
		).toBe(REAPPOINTABLE.yes)
		expect(holdReappointable(larsDeputy, deputy)).toBe(REAPPOINTABLE.yes)
		expect(holdReappointable(larsDeputy, null)).toBe(REAPPOINTABLE.yes)
	})

	it('gives a plain membership yes', () => {
		expect(schedule(null)[2].reappointable).toBe(REAPPOINTABLE.yes)
	})
})

describe('windowUntil', () => {
	const body = { termEnd: '2030-03-29T00:00:00Z' }

	it('defaults to twelve months from today', () => {
		expect(STEP_DOWN_WINDOWS[0]).toBe('next-12-months')
		expect(windowUntil('next-12-months', body, NOW)).toBe(
			new Date(2027, 9, 3, 10, 0, 0).getTime(),
		)
		expect(windowUntil('unknown', body, NOW)).toBe(
			new Date(2027, 9, 3, 10, 0, 0).getTime(),
		)
	})

	it('runs to the end of the body term for this term', () => {
		expect(windowUntil('this-term', body, NOW)).toBe(
			Date.parse('2030-03-29T00:00:00Z'),
		)
		expect(windowUntil('this-term', {}, NOW)).toBeNull()
	})

	it('is open for all', () => {
		expect(windowUntil('all', body, NOW)).toBeNull()
	})
})

describe('the term on the Leden form (REQ-TOS-001)', () => {
	it('starts on the start of the body term', () => {
		const start = defaultTermStart(
			{ termStart: '2026-03-30T00:00:00+02:00' },
			NOW,
		)

		expect(start.getTime()).toBe(Date.parse('2026-03-30T00:00:00+02:00'))
	})

	it('starts today when the body has no term', () => {
		expect(defaultTermStart({}, NOW).getTime()).toBe(NOW.getTime())
		expect(defaultTermStart(null, NOW).getTime()).toBe(NOW.getTime())
	})

	it('saves both dates on the membership', () => {
		const term = membershipTerm(
			'2026-03-30T00:00:00.000Z',
			'2030-03-29T00:00:00.000Z',
		)
		const payload = buildMembershipPayload({
			personId: 'p-rachid',
			governanceBodyId: 'b-raad',
			role: 'member',
			startDate: term.startDate,
			endDate: term.endDate,
		})

		expect(term.valid).toBe(true)
		expect(payload).toEqual({
			person: 'p-rachid',
			governanceBody: 'b-raad',
			role: 'member',
			startDate: '2026-03-30T00:00:00.000Z',
			endDate: '2030-03-29T00:00:00.000Z',
		})
	})

	it('leaves an empty end out of the payload', () => {
		const term = membershipTerm('2026-03-30T00:00:00.000Z', '')
		const payload = buildMembershipPayload({
			personId: 'p',
			governanceBodyId: 'b',
			role: 'member',
			...term,
		})

		expect(term).toEqual({
			startDate: '2026-03-30T00:00:00.000Z',
			endDate: '',
			valid: true,
		})
		expect(payload).not.toHaveProperty('endDate')
	})

	it('refuses an end before the start', () => {
		expect(
			membershipTerm('2030-03-29T00:00:00.000Z', '2026-03-30T00:00:00.000Z')
				.valid,
		).toBe(false)
	})
})

describe('former members from the end date (REQ-TOS-005)', () => {
	it('is current until the end date passes', () => {
		const membership = { id: 'm', endDate: '2026-10-02T00:00:00Z' }

		expect(isActiveMembershipAt(membership, '2026-10-01T12:00:00Z')).toBe(true)
		expect(isActiveMembershipAt(membership, '2026-10-02T00:00:01Z')).toBe(false)
		expect(isActiveMembershipAt({ id: 'm' }, NOW)).toBe(true)
		expect(isActiveMembershipAt({ id: 'm', endDate: 'not a date' }, NOW)).toBe(
			true,
		)
	})

	it('lists a member whose term ends in the future as current and one whose term ended as past', () => {
		const sanne = {
			id: 'm-sanne',
			person: 'p-sanne',
			endDate: '2001-01-01T00:00:00Z',
		}
		const rachid = {
			id: 'm-rachid',
			person: 'p-rachid',
			endDate: '2999-03-29T00:00:00Z',
		}

		expect(memberRowsFor([sanne, rachid]).map((row) => row.id)).toEqual([
			'm-rachid',
		])
		expect(
			memberRowsFor([sanne, rachid], {}, { past: true }).map((row) => row.id),
		).toEqual(['m-sanne'])
		expect(sanne.endDate).toBe('2001-01-01T00:00:00Z')
	})
})

describe('the dialogs\' picked term', () => {
	it('takes the picked dates as ISO days and refuses an end before the start', () => {
		const start = new Date(2026, 0, 5, 14, 30)
		const end = new Date(2030, 0, 4)
		const ok = pickedTerm(start, end)
		expect(ok).toEqual({ startDate: startOfDay(start), endDate: startOfDay(end), valid: true })
		expect(ok.startDate).toBe(startOfDay(new Date(2026, 0, 5)))
		expect(pickedTerm(new Date(2026, 5, 1), new Date(2026, 4, 1)).valid).toBe(false)
		expect(pickedTerm(null, null)).toEqual({ startDate: '', endDate: '', valid: true })
	})
})

describe('the schedule\'s short date', () => {
	it('gives the locale date, and nothing for an empty or unparsable value', () => {
		expect(shortDate('2026-03-01')).toBe(new Date('2026-03-01').toLocaleDateString())
		expect(shortDate('')).toBe('')
		expect(shortDate(null)).toBe('')
		expect(shortDate('not a date')).toBe('')
	})
})

describe('loading a body\'s step-down data', () => {
	it('reads the body, its memberships, holds and position types, and the people behind them', async () => {
		const calls = []
		const objects = {
			'governance-body': { b1: { id: 'b1', name: 'Raad' } },
			person: { p1: { id: 'p1', name: 'An' } },
		}
		const collections = {
			membership: [{ id: 'm1', person: 'p1' }, { id: 'm2', person: 'p2' }, { id: 'm3', person: 'p1' }],
			'position-hold': [{ id: 'h1' }],
			'position-type': [{ id: 't1', name: 'Voorzitter' }],
		}
		const storeFor = (type) => ({
			fetchObject: (schema, id) => {
				calls.push(['object', type, schema, id])
				const found = objects[schema]?.[id]
				return found ? Promise.resolve(found) : Promise.reject(new Error('gone'))
			},
			fetchCollection: (schema, params) => {
				calls.push(['collection', type, schema, params])
				return Promise.resolve(collections[schema])
			},
		})

		const data = await loadStepDownData('b1', storeFor)

		expect(data).toEqual({
			body: { id: 'b1', name: 'Raad' },
			memberships: collections.membership,
			holds: [{ id: 'h1' }],
			positionTypesById: { t1: { id: 't1', name: 'Voorzitter' } },
			personsById: { p1: { id: 'p1', name: 'An' } },
		})
		expect(calls).toContainEqual(['collection', 'membership', 'membership', { governanceBody: 'b1', _limit: 500 }])
		expect(calls).toContainEqual(['collection', 'position-hold', 'position-hold', { governanceBody: 'b1', _limit: 500 }])
		expect(calls).toContainEqual(['collection', 'position-type', 'position-type', { governanceBody: 'b1', _limit: 200 }])
		expect(calls.filter((c) => c[2] === 'person').map((c) => c[3])).toEqual(['p1', 'p2'])
	})

	it('answers empty lists and no body when the reads come back empty', async () => {
		const storeFor = () => ({
			fetchObject: () => Promise.resolve(null),
			fetchCollection: () => Promise.resolve(null),
		})
		expect(await loadStepDownData('b1', storeFor)).toEqual({
			body: null, memberships: [], holds: [], positionTypesById: {}, personsById: {},
		})
	})
})
