/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the parallel-session helpers in src/utils/meetingSessions.js
 * over seeded evenings, sessions and entered values: references as strings,
 * objects or blanks, starts that are valid, unparseable or absent, rooms,
 * chairs, agenda items and broadcasts that are present or missing. Times are
 * formatted in a fixed locale and time zone. A digest pins every case and a
 * sample stays readable.
 *
 * @spec exclude golden test of the parallel-session helpers, which have their own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import {
	bySessionStart,
	groupSessions,
	newSession,
	parentOf,
	referenceId,
	sessionColumns,
	sessionLabel,
	siblingsOf,
} from '../../src/utils/meetingSessions.js'

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
const IDS = ['ev', 'ev2', 's1', 's2', 's3', 's4']
const REFS = [
	ABSENT,
	null,
	'',
	'ev',
	'ev2',
	's1',
	'gone',
	{ id: 'ev' },
	{ uuid: 'ev2' },
	{ id: '', uuid: 'ev' },
	{ id: null, uuid: 's1' },
	{},
	7,
]
const DATES = [
	ABSENT,
	null,
	'',
	'2026-11-03T19:30:00+01:00',
	'2026-11-03T19:00:00+01:00',
	'2026-11-03T18:00:00Z',
	'2026-11-03T18:00:00.250+0100',
	'2026-11-03T18:00:00-05:00',
	'2026-11-03',
	'not a date',
]
const EVENING_DATES = [...DATES, '2026-11-03T18:00', '2026-11-03T18:00:00+0100']
const TITLES = [
	ABSENT,
	null,
	'',
	'Commissie Bestuur',
	'Commissie Ruimte',
	'  Raad  ',
	0,
]
const ROOMS = [ABSENT, null, '', 'Raadzaal', ' Kamer 1 ']
const TIMES = [ABSENT, null, '', '19:30', '7:30', '19:30:00', '00:00']
const SCALARS = [ABSENT, null, '', 'hybrid', true, false, 0]

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
	['id', [ABSENT, ...IDS]],
	['title', TITLES],
	['parentMeeting', REFS],
	['scheduledDate', DATES],
	['room', ROOMS],
	['chair', [ABSENT, null, 'p1', { id: 'p2' }, 'p9']],
	['lifecycle', [ABSENT, null, '', 'open', 'closed']],
	['governanceBody', REFS],
	['meetingMode', SCALARS],
	['isPublic', SCALARS],
]
const ENTERED_FIELDS = [
	['title', TITLES],
	['room', ROOMS],
	['time', TIMES],
]

/**
 * The related records for the columns, with parts left out at random.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {object|undefined} The related records.
 */
function related(rnd) {
	if (rnd() < 0.1) {
		return undefined
	}
	const item = (n) => ({
		id: 'a' + n,
		orderNumber: pick(rnd, [n, String(n), null, 'x', -1, 2]),
	})
	return build(rnd, [
		[
			'agendaItems',
			[
				ABSENT,
				null,
				{},
				{ s1: [item(1), item(2), item(3), item(4)], s2: [item(5)], ev: [] },
			],
		],
		[
			'broadcasts',
			[
				ABSENT,
				null,
				{},
				{ s1: { lifecycle: 'live' }, s2: {}, s3: { lifecycle: null } },
			],
		],
		[
			'chairs',
			[
				ABSENT,
				null,
				{},
				{
					p1: { displayName: 'Ann' },
					p2: { name: 'Bob' },
					p3: {},
					p9: { displayName: 'Cee', name: 'C' },
				},
			],
		],
	])
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
		return { error: e.name }
	}
}

/**
 * JSON with undefined kept apart.
 *
 * @param {*} value The value.
 *
 * @return {string} The text.
 */
function text(value) {
	return JSON.stringify(value, (_key, v) => (v === undefined ? 'undefined' : v))
}

/**
 * Columns without their time, which follows the machine's locale and time
 * zone when none is given.
 *
 * @param {Array|object} columns The columns or an error.
 *
 * @return {Array|object} The columns without `time`.
 */
function withoutTime(columns) {
	return Array.isArray(columns)
		? columns.map(({ time, ...rest }) => ({ ...rest, timed: time !== '' }))
		: columns
}

const FORMAT = { locale: 'en-GB', timeZone: 'Europe/Amsterdam' }

describe('meetingSessions golden output', () => {
	it('keeps its arrangement over seeded evenings and sessions', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 2500; i++) {
			const meetings = Array.from({ length: Math.floor(rnd() * 6) }, () =>
				build(rnd, MEETING_FIELDS),
			)
			const input = rnd() < 0.05 ? pick(rnd, [null, undefined, 'x']) : meetings
			const one = meetings[0] ?? build(rnd, MEETING_FIELDS)
			const entered =
				rnd() < 0.05
					? pick(rnd, [null, undefined])
					: build(rnd, ENTERED_FIELDS)
			const rel = related(rnd)
			const evening = {
				...one,
				...build(rnd, [['scheduledDate', EVENING_DATES]]),
			}
			all.push(
				text({
					meetings,
					input: Array.isArray(input) ? 'list' : String(input),
					entered,
					rel,
					refs: meetings.map((m) => [
						referenceId(m.parentMeeting),
						referenceId(m.governanceBody),
						parentOf(m),
					]),
					order: [...meetings].sort(bySessionStart).map((m) => m.id),
					pair:
						meetings.length > 1
							? Math.sign(bySessionStart(meetings[0], meetings[1]))
							: null,
					grouped: run(groupSessions, [input]),
					labels: meetings.map((m) =>
						sessionLabel(m, FORMAT.locale, FORMAT.timeZone),
					),
					siblings: run(siblingsOf, [one, input]),
					columns:
						rel === undefined
							? withoutTime(run(sessionColumns, [input]))
							: run(sessionColumns, [input, { ...rel, ...FORMAT }]),
					session: run(newSession, [evening, entered]),
				}),
			)
		}
		all.push(
			text([
				parentOf(null),
				parentOf(undefined),
				bySessionStart(null, undefined),
				sessionLabel(null, FORMAT.locale, FORMAT.timeZone),
			]),
		)
		const digest = createHash('sha256').update(all.join('\n')).digest('hex')
		expect({
			digest,
			sample: all.slice(0, 6).map((s) => JSON.parse(s)),
		}).toMatchSnapshot()
	})
})
