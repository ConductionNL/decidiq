/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `buildConfidentialityStages` in
 * src/components/widgets/registerDetailWidgets.js over seeded records: each
 * field present, empty, null or absent, every lifecycle, and deadlines
 * before, at and after "now". A digest pins every case and a sample stays
 * readable.
 *
 * @spec exclude golden test of the confidentiality timeline, which has its own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import { buildConfidentialityStages } from '../../src/components/widgets/registerDetailWidgets.js'

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

const NOW = Date.parse('2026-06-01T12:00:00Z')
const ABSENT = Symbol('absent')
const IDS = [ABSENT, ABSENT, null, '', 'id-1', 0, 7]
const DATES = [
	ABSENT,
	ABSENT,
	null,
	'',
	'2026-01-01',
	'2026-06-01T12:00:00Z',
	'2026-12-31',
	'not a date',
	NOW - 1,
	NOW,
	0,
]
const FIELDS = {
	imposedAt: DATES,
	ratificationDate: DATES,
	ratificationDecision: IDS,
	ratificationDeadline: DATES,
	ratificationAgendaItem: IDS,
	liftingDate: DATES,
	dissolutionDecision: IDS,
	liftingConditions: [ABSENT, null, '', 'after the vote'],
	lifecycle: [ABSENT, 'imposed', 'imposed', 'ratified', 'dissolved', ''],
}

/**
 * Generate one record; ABSENT leaves the field out.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {object|null} The record.
 */
function record(rnd) {
	if (rnd() < 0.03) {
		return pick(rnd, [null, undefined])
	}
	const r = {}
	for (const [field, values] of Object.entries(FIELDS)) {
		const v = pick(rnd, values)
		if (v !== ABSENT) {
			r[field] = v
		}
	}
	return r
}

describe('buildConfidentialityStages golden output', () => {
	it('keeps its stages over seeded records', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 3000; i++) {
			const r = record(rnd)
			const now = pick(rnd, [NOW, NOW + 86400000, 0])
			all.push({ r, now, stages: buildConfidentialityStages(r, now) })
		}
		all.push({ r: {}, now: 'default', stages: buildConfidentialityStages({}) })
		const digest = createHash('sha256').update(JSON.stringify(all)).digest('hex')
		expect({ digest, sample: all.slice(0, 12) }).toMatchSnapshot()
	})
})
