/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `getNoticeDeadlineInfo` in src/services/noticeRules.js
 * over seeded meetings and clocks: meeting dates and starts that are valid,
 * partial, unparseable or absent, notice periods of every kind, and days
 * before, at and after the warning window. A digest pins every case and a
 * sample stays readable.
 *
 * @spec exclude golden test of the notice deadline mirror, which has its own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import { getNoticeDeadlineInfo } from '../../src/services/noticeRules.js'

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
	null,
	'',
	'2026-06-20',
	'2026-06-16T09:30:00+02:00',
	'2026-06-19',
	'2026-06-10',
	'2026-07-30',
	'2026-02-30',
	'not a date',
	'2026-6-1',
	20260620,
]
const PERIODS = [
	ABSENT,
	undefined,
	null,
	0,
	3,
	15,
	'7',
	-1,
	'abc',
	Infinity,
	2.5,
	'',
	1e9,
]
const NOWS = [
	'2026-06-01T00:00:00Z',
	'2026-06-01T23:59:59Z',
	'2026-06-02T12:00:00Z',
	'2026-06-05T12:00:00Z',
	'2026-06-08T12:00:00Z',
]

/**
 * Generate one meeting; ABSENT leaves the field out.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {object|null} The meeting.
 */
function meeting(rnd) {
	if (rnd() < 0.03) {
		return pick(rnd, [null, undefined])
	}
	const m = {}
	for (const [field, values] of [
		['meetingDate', DATES],
		['meetingStart', DATES],
		['noticePeriodDays', PERIODS],
	]) {
		const v = pick(rnd, values)
		if (v !== ABSENT) {
			m[field] = v
		}
	}
	return m
}

/**
 * The info for one case, or the error it throws.
 *
 * @param {object|null} m The meeting.
 * @param {Date} now The clock.
 *
 * @return {object} The info or `{ error }`.
 */
function info(m, now) {
	try {
		return getNoticeDeadlineInfo(m, now)
	} catch (e) {
		return { error: e.name + ': ' + e.message }
	}
}

describe('getNoticeDeadlineInfo golden output', () => {
	it('keeps its deadline info over seeded meetings', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 3000; i++) {
			const m = meeting(rnd)
			const now = pick(rnd, NOWS)
			const p = m?.noticePeriodDays
			all.push({
				m: m && { ...m, noticePeriodDays: typeof p + ':' + String(p) },
				now,
				out: info(m, new Date(now)),
			})
		}
		const digest = createHash('sha256').update(JSON.stringify(all)).digest('hex')
		expect({ digest, sample: all.slice(0, 12) }).toMatchSnapshot()
	})
})
