/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for applyPageOverlay: fixed page and overlay pairs plus
 * generated ones over every overlay part, with every result snapshotted
 * and the input page checked to be left alone.
 *
 * @spec openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
import { describe, expect, it } from 'vitest'
import { applyPageOverlay } from '../../src/utils/structureProfile.js'

/**
 * A deterministic pick sequence.
 *
 * @param {number} seed - the start of the sequence
 * @return {Function} returns the next pick below a given bound
 */
function picker(seed) {
	let state = seed >>> 0
	return (bound) => {
		state = (state + 0x6d2b79f5) >>> 0
		let t = Math.imul(state ^ (state >>> 15), 1 | state)
		t ^= t + Math.imul(t ^ (t >>> 7), 61 | t)
		return ((t ^ (t >>> 14)) >>> 0) % bound
	}
}

const NAMES = ['a', 'b', 'c', 'd', 'w1', 'w2']
const ITEMS = [
	{ id: 'a', n: 1 },
	{ key: 'b' },
	{ label: 'c' },
	'd',
	{
		id: 'w1',
		content: { sections: [{ widgetId: 'w2' }, { widget: { id: 'x' } }] },
	},
	{ id: 'w2', title: 'Two' },
	null,
	7,
	{ other: 'e' },
]
const LISTS = ['list', 'widgets', 'scalar', 'missing']

/**
 * A generated page and overlay.
 *
 * @param {number} seed - the start of the sequence
 * @return {Array} the page and the overlay
 */
function generated(seed) {
	const pick = picker(seed)
	const someItems = () =>
		Array.from({ length: pick(5) }, () => ITEMS[pick(ITEMS.length)])
	const config = { scalar: pick(2) ? 'text' : 3 }
	for (const key of ['list', 'widgets']) {
		if (pick(4)) {
			config[key] = someItems()
		}
	}
	const page = { id: 'P', title: 'Page ' + seed, config }
	if (pick(2)) {
		page.slots = { one: 'One' }
	}
	const overlay = { id: 'P' }
	const parts = {
		config: () => ({ scalar: 'over', extra: pick(3) }),
		configPatch: () => {
			const patch = {}
			for (const key of LISTS.slice(0, 1 + pick(4))) {
				const byName = {}
				for (const name of NAMES.slice(0, pick(NAMES.length + 1))) {
					byName[name] = [null, { n: pick(9) }, { z: true }, undefined][
						pick(4)
					]
				}
				patch[key] = pick(5) ? byName : null
			}
			return patch
		},
		configAppend: () => {
			const append = {}
			for (const key of LISTS.slice(0, 1 + pick(4))) {
				append[key] = pick(5) ? someItems() : 'not a list'
			}
			return append
		},
		configOrder: () => {
			const order = {}
			for (const key of LISTS.slice(0, 1 + pick(4))) {
				order[key] = pick(5)
					? NAMES.slice(pick(3), 2 + pick(5)).reverse()
					: 'not a list'
			}
			return order
		},
		slots: () => [{ two: 'Two' }, 'text', null][pick(3)],
	}
	for (const [part, make] of Object.entries(parts)) {
		if (pick(2)) {
			overlay[part] = make()
		}
	}
	return [page, overlay]
}

const PAIRS = {
	'empty overlay': [{ id: 'P', config: { a: 1 } }, { id: 'P' }],
	'page without config': [{ id: 'P' }, { id: 'P', config: { a: 1 } }],
	'patch on a missing list': [
		{ id: 'P', config: {} },
		{ id: 'P', configPatch: { list: { a: { n: 2 } } } },
	],
	'patch with a null by-name': [
		{ id: 'P', config: { list: [{ id: 'a' }] } },
		{ id: 'P', configPatch: { list: null } },
	],
	'patch a scalar item': [
		{ id: 'P', config: { list: ['a', { id: 'a' }] } },
		{ id: 'P', configPatch: { list: { a: { n: 2 } } } },
	],
	'remove every item': [
		{ id: 'P', config: { list: [{ id: 'a' }, { id: 'a' }] } },
		{ id: 'P', configPatch: { list: { a: null } } },
	],
	'append a non-list': [
		{ id: 'P', config: { list: [1] } },
		{ id: 'P', configAppend: { list: 'x', other: [2] } },
	],
	'append onto a scalar': [
		{ id: 'P', config: { list: 'x' } },
		{ id: 'P', configAppend: { list: [2] } },
	],
	'order with unknown names': [
		{ id: 'P', config: { list: [{ id: 'a' }, { id: 'b' }] } },
		{ id: 'P', configOrder: { list: ['z', 'b', 'b'] } },
	],
	'order a non-list': [
		{ id: 'P', config: { list: [{ id: 'a' }] } },
		{ id: 'P', configOrder: { list: 'a' } },
	],
	'widgets get sections inlined': [
		{
			id: 'P',
			config: {
				widgets: [
					{ id: 'w1', content: { sections: [{ widgetId: 'w2' }] } },
					{ id: 'w2' },
				],
			},
		},
		{ id: 'P' },
	],
	'widgets not a list': [{ id: 'P', config: { widgets: 'x' } }, { id: 'P' }],
	'slots without page slots': [
		{ id: 'P', config: {} },
		{ id: 'P', slots: { two: 'Two' } },
	],
	'slots that are not an object': [
		{ id: 'P', slots: { one: 1 }, config: {} },
		{ id: 'P', slots: 'two' },
	],
	'slots null': [
		{ id: 'P', slots: { one: 1 }, config: {} },
		{ id: 'P', slots: null },
	],
}
for (let n = 0; n < 80; n++) {
	PAIRS['generated ' + n] = generated(n + 1)
}

describe('applyPageOverlay golden', () => {
	for (const [name, [page, overlay]] of Object.entries(PAIRS)) {
		it(name, () => {
			const before = JSON.stringify(page)
			expect(applyPageOverlay(page, overlay)).toMatchSnapshot()
			expect(JSON.stringify(page)).toBe(before)
		})
	}
})
