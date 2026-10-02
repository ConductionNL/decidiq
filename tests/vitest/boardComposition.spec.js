/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for a body's composition (bod-11, bod-12): the skills matrix
 * with its gaps and the composition figures against the body's own targets.
 * The pure functions run against the seeded supervisory board; this repo's
 * vitest cannot mount a `.vue` file, so the widget's wiring is asserted
 * against the sources.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'

// boardComposition.js reuses isActiveMembership from useRelationStore.js,
// whose module-level store import pulls in apexcharts, which needs a window
// this node environment does not have. Only pure functions are under test,
// so the store is stubbed, as memberRelations.spec.js does.
vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))

import { competencePath } from '../../src/utils/competenceApi.js'
import {
	ageBand,
	compositionFigures,
	competenceGaps,
	currentSeats,
	skillsMatrix,
	targetResults,
} from '../../src/utils/boardComposition.js'

const read = (path) =>
	readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8')

// The seeded board of the corporate example set, as the widget loads it.
const seats = [
	{ id: 'm-jan', person: 'jan', role: 'secretary', independenceStatus: '' },
	{ id: 'm-janneke', person: 'janneke', role: 'chair', independenceStatus: 'independent' },
	{ id: 'm-mark', person: 'mark', role: 'member', independenceStatus: 'non-independent' },
]
const persons = {
	jan: { id: 'jan', name: 'Jan de Vries', gender: 'male' },
	janneke: { id: 'janneke', name: 'Janneke de Bruin', gender: 'female', nationality: 'NL' },
	mark: { id: 'mark', name: 'Mark van den Berg', gender: 'male', nationality: 'NL' },
}
const competences = [
	{ id: 'fin', name: 'Finance and audit', requiredHolders: 1, order: 1 },
	{ id: 'water', name: 'Water management', requiredHolders: 1, order: 2 },
	{ id: 'legal', name: 'Legal', requiredHolders: 1, order: 3 },
	{ id: 'it', name: 'IT and cybersecurity', requiredHolders: 1, order: 4 },
	{ id: 'old', name: 'Retired', requiredHolders: 1, order: 5, active: false },
]
const held = [
	{ id: 'h1', membership: 'm-janneke', competence: 'fin', level: 'expert', confirmedBy: 'admin', confirmedAt: '2026-01-01T00:00:00+00:00' },
	{ id: 'h2', membership: 'm-jan', competence: 'legal', level: 'experienced', confirmedBy: 'janneke', confirmedAt: '2026-01-02T00:00:00+00:00' },
	{ id: 'h3', membership: 'm-mark', competence: 'water', level: 'expert' },
]

describe('the skills matrix', () => {
	it('marks IT and cybersecurity and Water management as gaps on the seeded board', () => {
		const gaps = competenceGaps(competences, held)
		expect(gaps.filter((g) => g.gap).map((g) => g.name)).toEqual([
			'Water management',
			'IT and cybersecurity',
		])
		expect(gaps.find((g) => g.id === 'fin')).toMatchObject({ holders: 1, required: 1, gap: false })
		expect(gaps.map((g) => g.id)).not.toContain('old')
	})

	it('closes a gap when a confirmed experienced holder is added', () => {
		const more = [
			...held,
			{ id: 'h4', membership: 'm-jan', competence: 'it', level: 'experienced', confirmedBy: 'janneke', confirmedAt: '2026-02-01T00:00:00+00:00' },
			{ ...held[2], confirmedBy: 'janneke', confirmedAt: '2026-02-01T00:00:00+00:00' },
		].filter((h, i, all) => all.findLastIndex((x) => x.id === h.id) === i)
		expect(competenceGaps(competences, more).filter((g) => g.gap)).toEqual([])
	})

	it('does not count a basic level, or a holder of another seat, towards the required holders', () => {
		const basic = [{ id: 'b', membership: 'm-mark', competence: 'it', level: 'basic', confirmedBy: 'x', confirmedAt: 'y' }]
		expect(competenceGaps(competences, basic).find((g) => g.id === 'it').gap).toBe(true)
		const strangers = [{ id: 's', membership: 'm-gone', competence: 'it', level: 'expert', confirmedBy: 'x', confirmedAt: 'y' }]
		expect(competenceGaps(competences, strangers, ['m-jan']).find((g) => g.id === 'it').gap).toBe(true)
	})

	it('has members as rows, competences as columns and marks unconfirmed levels', () => {
		const matrix = skillsMatrix(seats, persons, competences, held)
		expect(matrix.columns.map((c) => c.name)).toEqual([
			'Finance and audit',
			'Water management',
			'Legal',
			'IT and cybersecurity',
		])
		expect(matrix.rows.map((r) => r.name)).toEqual([
			'Jan de Vries',
			'Janneke de Bruin',
			'Mark van den Berg',
		])
		const mark = matrix.rows.find((r) => r.seat === 'm-mark')
		expect(mark.cells.water).toMatchObject({ level: 'expert', confirmed: false })
		expect(mark.cells.it).toBeNull()
		const janneke = matrix.rows.find((r) => r.seat === 'm-janneke')
		expect(janneke.cells.fin).toMatchObject({ level: 'expert', confirmed: true, confirmedBy: 'admin' })
	})

	it('lists only current seats', () => {
		expect(currentSeats([{ id: 'a' }, { id: 'b', endDate: '2025-01-01T00:00:00Z' }]).map((s) => s.id)).toEqual(['a'])
	})
})

describe('the composition figures', () => {
	const today = new Date('2026-10-02T12:00:00')

	it('counts one woman and two men with their shares and meets a 0.33 female target', () => {
		const figures = compositionFigures(seats, persons, today)
		expect(figures.gender).toEqual({
			total: 3,
			values: [
				{ value: 'male', count: 2, share: 2 / 3 },
				{ value: 'female', count: 1, share: 1 / 3 },
			],
			notRecorded: 0,
		})
		const [result] = targetResults(figures, [{ dimension: 'gender', value: 'female', minimumShare: 0.33 }])
		expect(result).toMatchObject({ dimension: 'gender', value: 'female', share: 1 / 3, met: true })
		expect(targetResults(figures, [{ dimension: 'gender', value: 'female', minimumShare: 0.5 }])[0].met).toBe(false)
	})

	it('counts a member without birth date under not recorded only', () => {
		const people = { ...persons, mark: { ...persons.mark, birthDate: '1970-05-01' } }
		const figures = compositionFigures(seats, people, today)
		expect(figures.ageBand.notRecorded).toBe(2)
		expect(figures.ageBand.values).toEqual([{ value: '55-69', count: 1, share: 1 / 3 }])
	})

	it('bands a birth date exactly 40 years ago today in 40 to 54', () => {
		expect(ageBand('1986-10-02', today)).toBe('40-54')
		expect(ageBand('1986-10-03', today)).toBe('under-40')
		expect(ageBand('1956-10-02', today)).toBe('70-plus')
		expect(ageBand('', today)).toBeNull()
		expect(ageBand('not a date', today)).toBeNull()
	})

	it('counts nationality, independence and seats from outside, each with not recorded', () => {
		const figures = compositionFigures(
			[...seats.slice(0, 2), { ...seats[2], external: true }],
			persons,
			today,
		)
		expect(figures.nationality).toMatchObject({ notRecorded: 1, values: [{ value: 'NL', count: 2 }] })
		expect(figures.independence).toMatchObject({ notRecorded: 1 })
		expect(figures.independence.values.map((v) => v.value).sort()).toEqual(['independent', 'non-independent'])
		expect(figures.external).toMatchObject({ notRecorded: 2, values: [{ value: 'yes', count: 1 }] })
	})

	it('names nobody: the figures carry counts, not people', () => {
		const text = JSON.stringify(compositionFigures(seats, persons, today))
		for (const person of Object.values(persons)) {
			expect(text).not.toContain(person.name)
			expect(text).not.toContain(person.id)
		}
	})

	it('reports a target on a dimension with no members as not met', () => {
		const [result] = targetResults(compositionFigures([], {}, today), [{ dimension: 'gender', value: 'female', minimumShare: 0.33 }])
		expect(result).toMatchObject({ share: 0, met: false })
	})
})

describe('the composition widget wiring', () => {
	it('builds the guarded routes', () => {
		expect(competencePath('member-competences', 'h3', 'confirm')).toBe('/apps/decidiq/api/member-competences/h3/confirm')
		expect(competencePath('competences')).toBe('/apps/decidiq/api/competences')
	})

	const widget = read('src/components/tabs/GovernanceBodyCompositionTab.vue')
	const manifest = JSON.parse(read('src/manifest.json'))
	const registry = read('src/registry.js')

	it('computes from the pure functions and confirms through the guarded route', () => {
		expect(widget).toMatch(/from '\.\.\/\.\.\/utils\/boardComposition\.js'/)
		expect(widget).toContain('skillsMatrix(')
		expect(widget).toContain('compositionFigures(')
		expect(widget).toContain('targetResults(')
		expect(widget).toMatch(/competencePath\('member-competences', [^)]*'confirm'\)/)
		expect(widget).toContain('MemberCompetenceModal')
	})

	it('is a widget with a slot and a layout cell on the body page', () => {
		const page = manifest.pages.find((p) => p.id === 'GovernanceBodyDetail')
		const widgets = page.config.widgets.map((w) => w.id)
		expect(widgets).toContain('body-composition')
		expect(page.config.layout.some((cell) => cell.widgetId === 'body-composition')).toBe(true)
		expect(page.slots['widget-body-composition']).toBe('GovernanceBodyCompositionTab')
		expect(registry).toContain('GovernanceBodyCompositionTab')
	})
})
