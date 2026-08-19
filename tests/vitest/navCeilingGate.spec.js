/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for scripts/check-nav-ceiling.js — the ADR-004 nav-ceiling gate.
 *
 * These are pure-function tests over small in-memory fixtures (no real
 * src/manifest.json / src/menu-layout.json involved — see test-plan.md
 * "Out of Scope" for why the real repo state is deliberately not asserted
 * against here). TC-4/TC-5 are the change's POSITIVE CONTROL, required per
 * REQ-NAV-013: proof the gate can fail before it is trusted to say "pass".
 */

import { describe, expect, it } from 'vitest'
import {
	buildEffectiveMenu,
	evaluateCeiling,
	evaluateFragmentPlacement,
} from '../../scripts/check-nav-ceiling.js'

describe('nav-ceiling gate: evaluateCeiling (REQ-NAV-007)', () => {
	it('TC-1: passes when the merged menu is at or under the ceiling', () => {
		const menu = [
			{ id: 'A' }, { id: 'B' }, { id: 'C' },
			{ id: 'D' }, { id: 'E' }, { id: 'F' },
		]
		const result = evaluateCeiling(menu, 6)
		expect(result.failures).toEqual([])
		expect(result.primary.length).toBe(6)
	})

	it('TC-2: fails and names the ceiling and actual count when over the ceiling', () => {
		const menu = [
			{ id: 'A' }, { id: 'B' }, { id: 'C' },
			{ id: 'D' }, { id: 'E' }, { id: 'F' }, { id: 'G' },
		]
		const result = evaluateCeiling(menu, 6)
		expect(result.failures.length).toBe(1)
		expect(result.failures[0]).toContain('7')
		expect(result.failures[0]).toContain('ceiling: 6')
		expect(result.failures[0]).toContain('G')
	})

	it('TC-3: footer and settings entries are excluded from the primary count', () => {
		const menu = [
			{ id: 'A' }, { id: 'B' }, { id: 'C' },
			{ id: 'D' }, { id: 'E' }, { id: 'F' },
			{ id: 'Docs', section: 'footer' },
			{ id: 'Roadmap', section: 'footer' },
			{ id: 'Beheer', section: 'settings' },
		]
		const result = evaluateCeiling(menu, 6)
		expect(result.failures).toEqual([])
		expect(result.primary.length).toBe(6)
		expect(result.footer.length).toBe(2)
		expect(result.settings.length).toBe(1)
	})
})

describe('nav-ceiling gate: evaluateFragmentPlacement (REQ-NAV-008, positive control REQ-NAV-013)', () => {
	const fragment = {
		file: 'src/manifest.d/fixture-new-thing.json',
		menu: [{ id: 'NewThing', label: 'New thing', route: 'NewThing', order: 200 }],
	}

	it('TC-4 (POSITIVE CONTROL): an unplaced fragment entry fails, naming the entry', () => {
		const emptyLayout = { relocations: {}, removals: [], settingsSection: [] }
		const result = evaluateFragmentPlacement([fragment], emptyLayout)
		expect(result.checked).toBe(1)
		expect(result.failures.length).toBe(1)
		expect(result.failures[0]).toContain('NewThing')
		expect(result.failures[0]).toContain(fragment.file)
	})

	it('TC-5: a relocation for the same entry clears the failure', () => {
		const layout = { relocations: { NewThing: 'SomeExistingGroup' }, removals: [], settingsSection: [] }
		const result = evaluateFragmentPlacement([fragment], layout)
		expect(result.failures).toEqual([])
	})

	it('TC-6: a removal for the same entry clears the failure', () => {
		const layout = { relocations: {}, removals: ['NewThing'], settingsSection: [] }
		const result = evaluateFragmentPlacement([fragment], layout)
		expect(result.failures).toEqual([])
	})

	it('TC-7a: a settingsSection entry for the same id clears the failure', () => {
		const layout = { relocations: {}, removals: [], settingsSection: ['NewThing'] }
		const result = evaluateFragmentPlacement([fragment], layout)
		expect(result.failures).toEqual([])
	})

	it('TC-7b: a self-declared section: "settings" on the fragment entry clears the failure with no menu-layout.json entry at all', () => {
		const selfScopedFragment = {
			file: 'src/manifest.d/user-settings.json',
			menu: [{ id: 'UserSettingsMenu', section: 'settings', route: 'UserSettings' }],
		}
		const emptyLayout = { relocations: {}, removals: [], settingsSection: [] }
		const result = evaluateFragmentPlacement([selfScopedFragment], emptyLayout)
		expect(result.failures).toEqual([])
	})

	it('TC-7c: a self-declared section: "footer" clears the failure the same way', () => {
		const footerFragment = {
			file: 'src/manifest.d/fixture-footer-thing.json',
			menu: [{ id: 'FooterThing', section: 'footer', route: 'FooterThing' }],
		}
		const emptyLayout = { relocations: {}, removals: [], settingsSection: [] }
		const result = evaluateFragmentPlacement([footerFragment], emptyLayout)
		expect(result.failures).toEqual([])
	})
})

describe('nav-ceiling gate: buildEffectiveMenu end-to-end (regression scenario)', () => {
	it('reproduces the recurrence defect: an unrelocated fragment entry pushes the merged menu over the ceiling AND is individually unplaced', () => {
		// Base is already AT the ADR-004 ceiling (6 primary entries). Every
		// entry carries a `route` — a routeless, childless, non-clickable
		// entry is dropped as an empty group shell by applyMenuRelocations,
		// same as in the real merged manifest.
		const base = {
			menu: [
				{ id: 'Dashboard', route: 'Dashboard' }, { id: 'Meetings', route: 'Meetings' }, { id: 'Decisions', route: 'Decisions' },
				{ id: 'ActionItems', route: 'ActionItems' }, { id: 'GovernanceBodies', route: 'GovernanceBodies' }, { id: 'Moties', route: 'Moties' },
				{ id: 'Documentation', section: 'footer', route: 'Documentation' },
			],
		}
		const fragments = [
			{ file: 'src/manifest.d/urgent-decision-procedure.json', menu: [{ id: 'UrgentDecisions', route: 'UrgentDecisions' }] },
		]
		const emptyLayout = { relocations: {}, removals: [], settingsSection: [] }

		const effectiveMenu = buildEffectiveMenu(base, fragments, emptyLayout)
		const ceiling = evaluateCeiling(effectiveMenu, 6)
		const placement = evaluateFragmentPlacement(fragments, emptyLayout)

		// 6 base primary entries + 1 unrelocated fragment entry = 7 — over the ceiling.
		expect(ceiling.primary.length).toBe(7)
		expect(ceiling.failures.length).toBe(1)
		// The fragment-placement check fires independently of the count —
		// it would fail even if the base had room to spare (REQ-NAV-008).
		expect(placement.failures.length).toBe(1)
		expect(placement.failures[0]).toContain('UrgentDecisions')

		// Relocating it (as ia-six-clusters does) clears BOTH failures.
		const relocatedLayout = { relocations: { UrgentDecisions: 'Decisions' }, removals: [], settingsSection: [] }
		const relocatedMenu = buildEffectiveMenu(base, fragments, relocatedLayout)
		const relocatedPlacement = evaluateFragmentPlacement(fragments, relocatedLayout)
		expect(relocatedPlacement.failures).toEqual([])
		expect(evaluateCeiling(relocatedMenu, 6).primary.length).toBe(6)
		expect(evaluateCeiling(relocatedMenu, 6).failures).toEqual([])
	})
})
