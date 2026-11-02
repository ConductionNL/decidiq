/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `contributionsOn` in src/utils/liveMeeting.js over seeded
 * engagement records and participants: records with and without speeches and
 * questions, items that match or not, durations of every type, participants
 * named by displayName, name or only their id, and lists that are missing.
 *
 * @spec exclude golden test of the per-item contributions, which have their own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import { contributionsOn } from '../../src/utils/liveMeeting.js'

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

const ITEMS = [null, undefined, '', 'i1', 'i2']
const IDS = ['p1', 'p2', 'p3', undefined, null]
const DURATIONS = [undefined, null, 0, 45, '30', 'x', -5, 12.5, NaN]

/**
 * A list of n generated values, or a value that is not a list.
 *
 * @param {function(): number} rnd The generator.
 * @param {Function} make Makes one value.
 *
 * @return {*} The list or the odd value.
 */
function listOf(rnd, make) {
	const r = rnd()
	if (r < 0.05) {
		return null
	}
	if (r < 0.1) {
		return undefined
	}
	return Array.from({ length: Math.floor(rnd() * 4) }, make)
}

/**
 * One engagement record.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {object|null} The record.
 */
function record(rnd) {
	if (rnd() < 0.05) {
		return null
	}
	return {
		participant: pick(rnd, IDS),
		speeches: listOf(rnd, () =>
			rnd() < 0.1
				? null
				: { agendaItem: pick(rnd, ITEMS), duration: pick(rnd, DURATIONS) },
		),
		questionsRaised: listOf(rnd, () =>
			rnd() < 0.1 ? null : { agendaItem: pick(rnd, ITEMS) },
		),
	}
}

/**
 * One participant.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {object} The participant.
 */
function participant(rnd) {
	return {
		id: pick(rnd, IDS),
		displayName: pick(rnd, [undefined, '', 'Anna']),
		name: pick(rnd, [undefined, '', 'anna']),
	}
}

/**
 * JSON with undefined and NaN kept apart.
 *
 * @param {*} value The value.
 *
 * @return {string} The text.
 */
function text(value) {
	return JSON.stringify(value, (_key, v) => {
		if (v === undefined) {
			return 'undefined'
		}
		return typeof v === 'number' && !Number.isFinite(v) ? 'num:' + v : v
	})
}

describe('contributionsOn golden output', () => {
	it('keeps the contributions over seeded records', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 3000; i++) {
			const records = listOf(rnd, () => record(rnd))
			const participants =
				rnd() < 0.2 ? undefined : listOf(rnd, () => participant(rnd))
			const itemId = pick(rnd, ITEMS)
			all.push(
				text({
					records,
					participants,
					itemId,
					out: contributionsOn(records, itemId, participants),
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
