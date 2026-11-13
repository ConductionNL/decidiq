/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * agenda-item-phase-is-declared: the phase of an agenda item is the declared
 * `phase` (a legacy `status` is read as a fallback), and the agenda item page
 * shows it as a stage line, as DcAgendapunt draws it. This repo's vitest
 * cannot mount a `.vue` file, so the wiring is asserted against the sources.
 *
 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	AGENDA_PHASE_STAGES,
	AGENDA_PHASES,
	canAdvancePhase,
	hasPhase,
	phaseOf,
	phaseStageIndex,
} from '../../src/utils/agendaPhase.js'

function read(path) {
	return readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')
}

function readJson(path) {
	return JSON.parse(read(path))
}

describe('the phase of an agenda item', () => {
	it('is the declared phase, over a legacy status', () => {
		expect(phaseOf({ phase: 'oordeelsvorming', status: 'beeldvorming' })).toBe('oordeelsvorming')
	})

	it('falls back to a phase still held in status, from before the upgrade', () => {
		expect(phaseOf({ status: 'besluitvorming' })).toBe('besluitvorming')
	})

	it('reads as beeldvorming when there is none, or the value is no phase', () => {
		expect(phaseOf({})).toBe('beeldvorming')
		expect(phaseOf(null)).toBe('beeldvorming')
		expect(phaseOf({ phase: 'open', status: 'closed' })).toBe('beeldvorming')
	})

	it('names the five values AgendaItem declares, matching the register fragment', () => {
		const fragment = readJson('../../lib/Settings/register.d/129-agenda-item-phase.json')
		const phase = fragment.components.schemas.AgendaItem.properties.phase
		expect(phase.enum).toEqual(AGENDA_PHASES)
		expect(phase.facetable).toBe(true)
	})
})

describe('the stage line', () => {
	it('draws forming a picture, forming an opinion and deciding', () => {
		expect(AGENDA_PHASE_STAGES.map((stage) => stage.id)).toEqual(['beeldvorming', 'oordeelsvorming', 'besluitvorming'])
	})

	it('marks the item\'s phase as current', () => {
		expect(phaseStageIndex({ itemType: 'decision', phase: 'beeldvorming' })).toBe(0)
		expect(phaseStageIndex({ itemType: 'decision', phase: 'oordeelsvorming' })).toBe(1)
		expect(phaseStageIndex({ itemType: 'decision', phase: 'besluitvorming' })).toBe(2)
	})

	it('puts a proposal before the first stage and a completed item past the last', () => {
		expect(phaseStageIndex({ phase: 'voorstel' })).toBe(0)
		expect(phaseStageIndex({ phase: 'completed' })).toBe(3)
	})

	it('shows for a discussion or decision item and not for an informational one', () => {
		expect(hasPhase({ itemType: 'decision' })).toBe(true)
		expect(hasPhase({ itemType: 'discussion' })).toBe(true)
		expect(hasPhase({ itemType: 'informational' })).toBe(false)
		expect(hasPhase(null)).toBe(false)
	})

	it('lets the chair advance until the item is completed', () => {
		expect(canAdvancePhase({ itemType: 'decision', phase: 'besluitvorming' })).toBe(true)
		expect(canAdvancePhase({ itemType: 'decision', phase: 'completed' })).toBe(false)
		expect(canAdvancePhase({ itemType: 'informational' })).toBe(false)
	})
})

describe('the wiring', () => {
	it('puts the stage line at the top of the agenda item page', () => {
		const page = readJson('../../src/manifest.json').pages.find((p) => p.id === 'AgendaItemDetail')
		const widget = page.config.widgets.find((w) => w.id === 'agenda-phase')
		expect(widget).toMatchObject({ type: 'custom', component: 'AgendaItemPhaseTab' })
		const slot = page.config.layout.find((l) => l.widgetId === 'agenda-phase')
		expect(slot).toMatchObject({ gridX: 0, gridY: 0, gridWidth: 12 })
		for (const other of page.config.layout.filter((l) => l.widgetId !== 'agenda-phase')) {
			expect(other.gridY).toBeGreaterThanOrEqual(slot.gridY + slot.gridHeight)
		}
	})

	it('registers the tab and draws it with the shared stages', () => {
		expect(read('../../src/registry.js')).toContain('AgendaItemPhaseTab: page(AgendaItemPhaseTab)')
		const tab = read('../../src/components/tabs/AgendaItemPhaseTab.vue')
		expect(tab).toContain('<CnTimelineStages')
		expect(tab).toContain('v-if="showsPhase"')
		expect(tab).toContain('AGENDA_PHASE_STAGES')
	})

	it('reads the same phase on the live meeting screen', () => {
		const live = read('../../src/views/LiveMeeting.vue')
		expect(live).toContain('phaseOf(item)')
		expect(live).not.toContain("item?.status ?? 'beeldvorming'")
	})
})
