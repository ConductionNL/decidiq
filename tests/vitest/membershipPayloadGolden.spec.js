/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `buildMembershipPayload` in
 * src/components/tabs/useRelationStore.js over seeded field objects: every
 * field present with a truthy, empty, zero, null or undefined value, or
 * absent, plus unknown keys. JSON loses undefined values and key order is
 * part of the payload, so each case records the key list as well.
 *
 * @spec exclude golden test of the membership payload builder, which has its own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it, vi } from 'vitest'
import { buildMembershipPayload } from '../../src/components/tabs/useRelationStore.js'

vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))

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
const VALUES = [ABSENT, ABSENT, undefined, null, '', 0, false, 'v', 2, 0.5]
const FIELDS = [
	'personId',
	'governanceBodyId',
	'role',
	'party',
	'votingWeight',
	'id',
	'startDate',
	'faction',
	'onBehalfOf',
	'unknown',
	'person',
]

/**
 * Describe a value so undefined survives JSON.
 *
 * @param {*} v The value.
 *
 * @return {string} Its type and JSON text.
 */
function show(v) {
	return typeof v + ':' + JSON.stringify(v)
}

describe('buildMembershipPayload golden output', () => {
	it('keeps its payload over seeded fields', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 3000; i++) {
			const fields = {}
			for (const field of FIELDS) {
				const v = pick(rnd, VALUES)
				if (v !== ABSENT) {
					fields[field] = v
				}
			}
			const payload = buildMembershipPayload(fields)
			all.push({
				fields: Object.entries(fields).map(([k, v]) => k + '=' + show(v)),
				payload: Object.entries(payload).map(([k, v]) => k + '=' + show(v)),
			})
		}
		const digest = createHash('sha256').update(JSON.stringify(all)).digest('hex')
		expect({ digest, sample: all.slice(0, 10) }).toMatchSnapshot()
	})
})
