/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the meeting-efficiency math in src/utils/meetingAnalytics.js
 * over seeded meetings, agenda items and engagement records: dates that are
 * valid, reversed, unparseable or absent, durations of every type, missing ids
 * and titles, and input that is not a list. A digest pins every case and a
 * sample stays readable.
 *
 * @spec exclude golden test of the meeting-efficiency math, which has its own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import {
	agendaCompletionRate,
	agendaItemCostBreakdown,
	costTrend,
	isItemCompleted,
	meetingDurationStats,
	speakingDistribution,
	timeAllocationAccuracy,
} from '../../src/utils/meetingAnalytics.js'

/**
 * A seeded pseudo-random generator (mulberry32).
 *
 * @param {number} seed The seed.
 *
 * @return {function(): number} Returns a float in [0, 1).
 */
function mulberry32(seed) {
	let a = seed >>> 0
	return () => {
		a = (a + 0x6d2b79f5) >>> 0
		let t = a
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

/**
 * Pick one item of a list.
 *
 * @param {function(): number} rnd The generator.
 * @param {Array} list The choices.
 *
 * @return {*} The picked item.
 */
function pick(rnd, list) {
	return list[Math.floor(rnd() * list.length)]
}

const ABSENT = Symbol('absent')
const DATES = [
	ABSENT,
	ABSENT,
	null,
	undefined,
	'',
	'2026-06-20T19:00:00Z',
	'2026-06-20T20:30:00Z',
	'2026-06-20T22:15:00Z',
	'2026-06-21T09:00:00Z',
	'2026-05-01T10:00:00Z',
	'not a date',
	1781982000000,
	new Date('2026-06-20T21:00:00Z'),
	new Date('invalid'),
]
const NUMBERS = [
	ABSENT,
	ABSENT,
	null,
	0,
	-5,
	17,
	20,
	23,
	30,
	45.6,
	90,
	'60',
	NaN,
	Infinity,
	120,
]
const IDS = [ABSENT, null, 'm-1', 'm-2', 'm-3', '', 0]
const TITLES = [ABSENT, null, '', 'Raad', 'Commissie']
const STATUSES = [ABSENT, 'completed', 'open', 'closed', null]
const TYPES = [ABSENT, '', null, 'debate', 'hamerstuk', 'motion', 0]
const PARTICIPANTS = [ABSENT, null, 'p-1', 'p-2', 'p-3', 0, '']
const COSTS = [ABSENT, null, 0, -10, 125.5, 300, '200', NaN, Infinity]

/**
 * Build one object from a field table; ABSENT leaves the field out.
 *
 * @param {function(): number} rnd The generator.
 * @param {Array} fields Pairs of field name and value list.
 *
 * @return {object} The object.
 */
function build(rnd, fields) {
	const o = {}
	for (const [field, values] of fields) {
		const v = pick(rnd, values)
		if (v !== ABSENT) {
			o[field] = v
		}
	}
	return o
}

const MEETING_FIELDS = [
	['id', IDS],
	['uuid', IDS],
	['title', TITLES],
	['openedAt', DATES],
	['closedAt', DATES],
	['scheduledDate', DATES],
	['startDate', DATES],
	['startTime', DATES],
	['endDate', DATES],
	['endTime', DATES],
	['plannedDuration', NUMBERS],
	['duration', NUMBERS],
	['meetingCost', COSTS],
]
const ITEM_FIELDS = [
	['id', IDS],
	['uuid', IDS],
	['title', TITLES],
	['status', STATUSES],
	['actualDuration', NUMBERS],
	['estimatedDuration', NUMBERS],
	['itemType', TYPES],
]
const RECORD_FIELDS = [
	['participant', PARTICIPANTS],
	['participantId', PARTICIPANTS],
	['speakingDuration', NUMBERS],
]

/**
 * A list of generated objects, or a value that is not a list.
 *
 * @param {function(): number} rnd The generator.
 * @param {Array} fields The field table.
 *
 * @return {*} The list or the odd value.
 */
function list(rnd, fields) {
	if (rnd() < 0.05) {
		return pick(rnd, [null, undefined, 'x', {}, 3])
	}
	const n = Math.floor(rnd() * 7)
	return Array.from({ length: n }, () => build(rnd, fields))
}

/**
 * Run one function, or record the error it throws.
 *
 * @param {Function} fn The function.
 * @param {Array} args Its arguments.
 *
 * @return {*} The result or `{ error }`.
 */
function run(fn, args) {
	try {
		return fn(...args)
	} catch (e) {
		return { error: e.name + ': ' + e.message }
	}
}

/**
 * JSON with the values JSON drops (undefined, NaN, Infinity, dates) kept apart.
 *
 * @param {*} value The value.
 *
 * @return {string} The text.
 */
function text(value) {
	return JSON.stringify(value, function (key, v) {
		const raw = this[key]
		if (raw instanceof Date) {
			return 'date:' + raw.getTime()
		}
		if (v === undefined) {
			return 'undefined'
		}
		if (typeof v === 'number' && !Number.isFinite(v)) {
			return 'num:' + String(v)
		}
		return v
	})
}

describe('meetingAnalytics golden output', () => {
	it('keeps its figures over seeded meetings, items and records', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 2500; i++) {
			const meetings = list(rnd, MEETING_FIELDS)
			const items = list(rnd, ITEM_FIELDS)
			const records = list(rnd, RECORD_FIELDS)
			const nameMap = rnd() < 0.5 ? { 'p-1': 'Ann', 'p-2': '' } : undefined
			const attendees = pick(rnd, [0, 3, 12])
			const rate = pick(rnd, [0, 75, 120.5])
			all.push(
				text({
					meetings,
					items,
					records,
					duration: run(meetingDurationStats, [meetings]),
					completion: run(agendaCompletionRate, [items]),
					completed: Array.isArray(items)
						? items.map(isItemCompleted)
						: run(isItemCompleted, [items]),
					speaking: run(
						speakingDistribution,
						nameMap === undefined ? [records] : [records, nameMap],
					),
					cost: run(costTrend, [meetings]),
					breakdown: run(agendaItemCostBreakdown, [
						items,
						attendees,
						rate,
					]),
					allocation: run(timeAllocationAccuracy, [items]),
				}),
			)
		}
		const digest = createHash('sha256').update(all.join('\n')).digest('hex')
		expect({
			digest,
			sample: all.slice(0, 8).map((s) => JSON.parse(s)),
		}).toMatchSnapshot()
	})
})
