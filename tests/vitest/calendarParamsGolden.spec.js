/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `calendarParams` in src/utils/activityCalendar.js over
 * seeded views: audiences that are set, empty, absent or NO_AUDIENCE, bodies
 * that are set or not, and meeting types with and without matching
 * audiences. The grid range follows the machine's time zone, so it is
 * checked against gridRange and kept out of the recorded text.
 *
 * @spec exclude golden test of the calendar query, which has its own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import {
	calendarParams,
	gridRange,
	NO_AUDIENCE,
} from '../../src/utils/activityCalendar.js'

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
const TYPES = [
	{ id: 't1', audiences: ['council'] },
	{ '@self': { id: 't2' }, audiences: ['executive', 'council'] },
	{ id: 't3', audiences: [] },
	{ id: 't4' },
	{ audiences: ['residents'] },
	{ id: 't5', audiences: 'council' },
]

describe('calendarParams golden output', () => {
	it('keeps the query over seeded views', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 2000; i++) {
			const view = {
				year: pick(rnd, [2025, 2026]),
				month: pick(rnd, [0, 5, 11]),
			}
			for (const [field, values] of [
				[
					'audience',
					[
						ABSENT,
						undefined,
						null,
						'',
						NO_AUDIENCE,
						'council',
						'executive',
						'residents',
						'staff',
					],
				],
				['body', [ABSENT, undefined, null, '', 'b1', 0]],
				[
					'types',
					[
						ABSENT,
						undefined,
						null,
						TYPES,
						TYPES.filter(() => rnd() < 0.5),
						[],
					],
				],
			]) {
				const v = pick(rnd, values)
				if (v !== ABSENT) {
					view[field] = v
				}
			}
			const params = calendarParams(view)
			const range = gridRange(view.year, view.month)
			const rangeMatches =
				params === null
				|| (params['scheduledDate[gte]'] === range.from
					&& params['scheduledDate[lte]'] === range.to)
			const kept = params === null ? null : { ...params }
			if (kept !== null) {
				delete kept['scheduledDate[gte]']
				delete kept['scheduledDate[lte]']
			}
			all.push(
				JSON.stringify({
					view: {
						...view,
						types: Array.isArray(view.types)
							? view.types.length
							: String(view.types),
					},
					keys: params === null ? null : Object.keys(params),
					kept,
					rangeMatches,
				}),
			)
		}
		const digest = createHash('sha256').update(all.join('\n')).digest('hex')
		expect({
			digest,
			sample: all.slice(0, 6).map((s) => JSON.parse(s)),
		}).toMatchSnapshot()
	})
})
