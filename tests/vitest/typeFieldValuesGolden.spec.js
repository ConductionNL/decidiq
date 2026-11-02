/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `findItemType` and `setTypeFieldValue` in
 * src/utils/agendaItemTypeFields.js over seeded types, references, inputs and
 * raw control values: references as ids, uuids, slugs or picker options,
 * inputs of every kind, and raw values that are empty, objects, numbers or
 * text.
 *
 * @spec exclude golden test of the agenda item type fields, which have their own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import {
	findItemType,
	setTypeFieldValue,
} from '../../src/utils/agendaItemTypeFields.js'

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

const TYPES = [
	{ name: 'by-id', id: 't1' },
	{ name: 'by-uuid', uuid: 't2' },
	{ name: 'by-self-slug', '@self': { slug: 'vraag' } },
	{ name: 'by-slug', slug: 'motie' },
	{ name: 'empty-self', '@self': null, slug: 'besluit' },
	null,
	{ name: 'second-t1', id: 't1' },
]
const REFS = [
	null,
	undefined,
	'',
	't1',
	't2',
	'vraag',
	'motie',
	'besluit',
	'none',
	{ id: 't2' },
	{ uuid: 'vraag' },
	{ value: 'motie' },
	{ id: '', uuid: '', value: 't1' },
	{},
	0,
]
const KINDS = [
	'checkbox',
	'user',
	'number',
	'text',
	'textarea',
	'select',
	'constructor',
	undefined,
]
const RAWS = [
	null,
	undefined,
	'',
	0,
	1,
	'0',
	'12',
	'abc',
	' 7 ',
	true,
	false,
	{ id: 'u1' },
	{ value: 'u2' },
	{ id: '', value: '' },
	{},
	[],
	['a'],
	NaN,
	12.5,
]
const VALUES = [null, undefined, {}, { f: 'old', g: 1 }, { g: 2 }]

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

describe('agenda item type fields golden output', () => {
	it('keeps the type lookup and the written answers over seeded cases', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 3000; i++) {
			const types =
				rnd() < 0.05
					? pick(rnd, [null, undefined, 'x'])
					: TYPES.filter(() => rnd() < 0.6)
			const ref = pick(rnd, REFS)
			const found = findItemType(types, ref)
			const values = pick(rnd, VALUES)
			const input = {
				key: pick(rnd, ['f', 'g', 'h']),
				input: pick(rnd, KINDS),
			}
			const raw = pick(rnd, RAWS)
			const written = setTypeFieldValue(values, { input, raw })
			all.push(
				text({
					types: Array.isArray(types)
						? types.map((t) => t?.name ?? null)
						: String(types),
					ref,
					found: found === null ? null : found.name,
					values,
					input,
					raw,
					written,
					sameObject: written === values,
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
