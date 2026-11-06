/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `applyMenuRelocations` and `evaluateFragmentPlacement`
 * in scripts/check-nav-ceiling.js over seeded menus, relocation maps and
 * fragments: entries with and without children or a click target,
 * relocations onto missing groups, onto themselves and in cycles, and
 * fragments that are placed, self-scoped or unplaced. A digest pins every
 * case and a sample stays readable.
 *
 * @spec exclude test of the nav-ceiling gate script, which has its own spec-tagged unit tests
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import {
	applyMenuRelocations,
	evaluateFragmentPlacement,
} from '../../scripts/check-nav-ceiling.js'

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

const IDS = ['a', 'b', 'c', 'd', 'e', 'f']

/**
 * Generate a menu node, with children while depth remains.
 *
 * @param {function(): number} rnd The generator.
 * @param {number} depth The remaining nesting depth.
 *
 * @return {object} The node.
 */
function node(rnd, depth) {
	const n = { id: pick(rnd, IDS) }
	const click = pick(rnd, [null, null, 'route', 'href', 'action'])
	if (click !== null) {
		n[click] = '/' + n.id
	}
	if (rnd() < 0.3) {
		n.section = pick(rnd, ['footer', 'settings', 'main'])
	}
	if (depth > 0 && rnd() < 0.5) {
		n.children = menu(rnd, depth - 1)
	}
	return n
}

/**
 * Generate a list of menu nodes.
 *
 * @param {function(): number} rnd The generator.
 * @param {number} depth The remaining nesting depth.
 *
 * @return {Array<object>} The nodes.
 */
function menu(rnd, depth) {
	const n = Math.floor(rnd() * 5)
	const out = []
	for (let i = 0; i < n; i++) {
		out.push(node(rnd, depth))
	}
	return out
}

/**
 * Generate a relocation map, sometimes missing or not an object.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {*} The relocations.
 */
function relocations(rnd) {
	if (rnd() < 0.08) {
		return pick(rnd, [undefined, null, 'a', 0])
	}
	const map = {}
	const n = Math.floor(rnd() * 5)
	for (let i = 0; i < n; i++) {
		map[pick(rnd, IDS)] = pick(rnd, [...IDS, 'missing', ''])
	}
	return map
}

/**
 * Generate a menu layout for the placement check.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {object} The layout.
 */
function layout(rnd) {
	const out = {}
	if (rnd() < 0.6) {
		out.relocations = relocations(rnd) || {}
	}
	if (rnd() < 0.5) {
		out.removals = IDS.filter(() => rnd() < 0.2)
	}
	if (rnd() < 0.5) {
		out.settingsSection = IDS.filter(() => rnd() < 0.2)
	}
	return out
}

/**
 * Generate placement-check fragments.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {Array} The fragments.
 */
function fragments(rnd) {
	const out = []
	const n = Math.floor(rnd() * 4)
	for (let i = 0; i < n; i++) {
		const kind = rnd()
		if (kind < 0.08) {
			out.push(pick(rnd, [null, {}, { menu: 'x' }]))
		} else {
			const frag = { menu: menu(rnd, 0) }
			if (rnd() < 0.7) {
				frag.file = 'src/manifest.d/f' + i + '.json'
			}
			out.push(frag)
		}
	}
	return out
}

describe('check-nav-ceiling relocation and placement golden output', () => {
	it('keeps its output over seeded menus and layouts', () => {
		const rnd = mulberry32(2026)
		const all = []
		for (let i = 0; i < 1500; i++) {
			const input = menu(rnd, 2)
			const before = JSON.stringify(input)
			const map = relocations(rnd)
			const out = applyMenuRelocations(input, map)
			const frags = fragments(rnd)
			const lay = layout(rnd)
			const placement =
				rnd() < 0.1
					? evaluateFragmentPlacement(frags)
					: evaluateFragmentPlacement(frags, lay)
			all.push({ before, map, out, mutated: input, frags, lay, placement })
		}
		const digest = createHash('sha256').update(JSON.stringify(all)).digest('hex')
		expect({ digest, sample: all.slice(0, 6) }).toMatchSnapshot()
	})
})
