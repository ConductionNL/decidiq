/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the nav-ceiling gate's merge, removal and settings-lift
 * steps over seeded menus, pinned before those steps were split into named
 * helpers. The digest is of the exact JSON each step produced on the code
 * before the split, so any change in what is merged, kept, pruned or lifted
 * (or in what order) turns this red.
 *
 * @spec exclude test of the nav-ceiling gate script, which has its own spec-tagged unit tests
 */

import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
import {
	applyMenuRemovals,
	applySettingsSection,
	buildEffectiveMenu,
	mergeMenuItems,
} from '../../scripts/check-nav-ceiling.js'

/**
 * A seeded pseudo-random generator, so the menus are the same every run.
 *
 * @param {number} seed The seed.
 * @return {function(): number} The generator.
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

const IDS = ['a', 'b', 'c', 'd', 'e']
const FIELDS = ['label', 'icon', 'route', 'order', 'section', 'href', 'action', 'extra']

/**
 * One seeded menu entry.
 *
 * @param {function(): number} rnd The generator.
 * @param {number} depth How deep children may nest.
 * @return {object} The entry.
 */
function entry(rnd, depth) {
	const n = { id: IDS[Math.floor(rnd() * IDS.length)] }
	for (const field of FIELDS) {
		if (rnd() < 0.25) {
			n[field] = rnd() < 0.1 ? undefined : field + Math.floor(rnd() * 3)
		}
	}
	const kids = rnd()
	if (depth > 0 && kids < 0.4) {
		n.children = entries(rnd, depth - 1)
	} else if (kids < 0.5) {
		n.children = rnd() < 0.5 ? [] : 'not-a-list'
	}
	return n
}

/**
 * A seeded list of menu entries.
 *
 * @param {function(): number} rnd The generator.
 * @param {number} depth How deep children may nest.
 * @return {Array<object>} The entries.
 */
function entries(rnd, depth) {
	const out = []
	const n = Math.floor(rnd() * 4)
	for (let i = 0; i < n; i++) {
		out.push(entry(rnd, depth))
	}
	return out
}

/**
 * A seeded subset of the ids, or a non-list now and then.
 *
 * @param {function(): number} rnd The generator.
 * @return {Array<string>|undefined} The ids.
 */
function ids(rnd) {
	if (rnd() < 0.1) {
		return rnd() < 0.5 ? undefined : []
	}
	return IDS.filter(() => rnd() < 0.3)
}

describe('check-nav-ceiling merge, removal and settings-lift golden output', () => {
	it('keeps its output over seeded menus', () => {
		const rnd = mulberry32(20261111)
		const out = []
		for (let i = 0; i < 400; i++) {
			const target = entries(rnd, 2)
			mergeMenuItems(target, entries(rnd, 2))
			const removals = ids(rnd)
			const settings = ids(rnd)
			out.push([
				target,
				applyMenuRemovals(target, removals),
				applySettingsSection(target, settings),
				buildEffectiveMenu({ menu: entries(rnd, 2) }, [{ menu: target }], {
					removals,
					settingsSection: settings,
				}),
			])
		}
		const digest = createHash('sha256').update(JSON.stringify(out)).digest('hex')
		expect(out.filter(([merged]) => merged.length > 0).length).toBeGreaterThan(200)
		expect(digest).toBe(
			'7c740f1bc02d191aadc2deb2a30c61ec852bc150f4830e8c4953df2f8fb5b26a',
		)
	})
})
