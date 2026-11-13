/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How buildProfiledManifest assembles its call to `buildManifest`: which
 * layout keys it forwards, how a profile menu is put in front of the base
 * menu without touching the profile file, and how a profile `nav` block is
 * merged over the built one.
 *
 * `buildManifest` is a recording double here on purpose: these assertions are
 * about what this module hands it and does with its answer, not about the
 * library's merge rules (structureProfile.spec.js runs the real one).
 */

import { describe, expect, it, vi } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const echo = () => vi.fn((base, fragments, layout) => ({ ...base, fragments, layout }))

describe('buildProfiledManifest assembly', () => {
	it('forwards only the layout keys buildManifest reads', () => {
		const build = echo()
		const file = {
			relocations: [{ id: 'a' }],
			removals: ['b'],
			settingsSection: 'x',
			integrationsSection: 'y',
			pageDefaults: undefined,
			somethingElse: true,
		}
		buildProfiledManifest(build, { menu: [] }, ['f1'], file)

		expect(build).toHaveBeenCalledTimes(1)
		const [, fragments, layout] = build.mock.calls[0]
		expect(fragments).toEqual(['f1'])
		expect(layout).toEqual({
			relocations: [{ id: 'a' }],
			removals: ['b'],
			settingsSection: 'x',
			integrationsSection: 'y',
		})
	})

	it('passes the base through untouched when the profile has no menu, nav or pages', () => {
		const build = echo()
		const base = { menu: [{ id: 'm' }], pages: [{ id: 'p' }] }
		const out = buildProfiledManifest(build, base, [], null)

		expect(build.mock.calls[0][0]).toBe(base)
		expect(build.mock.calls[0][2]).toEqual({})
		expect(out).toEqual({ ...base, fragments: [], layout: {} })
	})

	it('puts copies of the profile menu in front of the base menu', () => {
		const build = vi.fn((base) => {
			base.menu[0].mutated = true
			return { ...base }
		})
		const profileEntry = { id: 'simple' }
		const file = { menu: [profileEntry] }
		const out = buildProfiledManifest(build, { menu: [{ id: 'full' }], pages: [] }, [], file)

		expect(out.menu.map((entry) => entry.id)).toEqual(['simple', 'full'])
		expect(profileEntry).toEqual({ id: 'simple' })
	})

	it('copes with a base that has no menu', () => {
		const build = echo()
		const out = buildProfiledManifest(build, { pages: [] }, [], { menu: [{ id: 's' }] })

		expect(out.menu).toEqual([{ id: 's' }])
	})

	it('merges the profile nav over the built nav, dropping notes', () => {
		const build = vi.fn(() => ({ nav: { brand: 'old', keep: 1 }, pages: [] }))
		const file = { nav: { brand: 'new', _note: 'for the reader', extra: { a: 'b' } } }
		const out = buildProfiledManifest(build, {}, [], file)

		expect(out.nav).toEqual({ brand: 'new', keep: 1, extra: { a: 'b' } })
	})

	it('adds a nav when the built manifest has none', () => {
		const build = vi.fn(() => ({ pages: [] }))
		const out = buildProfiledManifest(build, {}, [], { nav: { brand: 'b' } })

		expect(out.nav).toEqual({ brand: 'b' })
	})

	it('applies overlays in order and skips one without a page', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const build = vi.fn(() => ({
			pages: [
				{ id: 'a', config: { x: 1 } },
				{ id: 'b', config: { y: 1 } },
			],
		}))
		const file = {
			pages: [
				{ id: 'b', config: { y: 2 } },
				{ id: 'ghost', config: {} },
			],
		}
		const out = buildProfiledManifest(build, {}, [], file)

		expect(out.pages[0]).toEqual({ id: 'a', config: { x: 1 } })
		expect(out.pages[1].config.y).toBe(2)
		expect(warn).toHaveBeenCalledTimes(1)
		expect(warn.mock.calls[0][1]).toEqual({ page: 'ghost' })
		warn.mockRestore()
	})
})
