/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The commitments register as configuration: the menu entry, the index page
 * with its columns, lenses and export, the detail page with files, related
 * objects and history, and the dashboard tile that counts the commitments
 * over their deadline exactly as the index's Over the deadline lens does.
 *
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/tasks.md#task-3-manifest-fragment--indexdetail-pages--menu-for-both-registers
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const readJson = (file) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'))

const fragment = readJson('src/manifest.d/toezeggingen-ingekomen-stukken.json')
const indexPage = fragment.pages.find((p) => p.id === 'Commitments')
const detailPage = fragment.pages.find((p) => p.id === 'CommitmentDetail')

/**
 * The dashboard tile that counts commitments over their deadline.
 *
 * @return {object} The widget
 */
function overdueTile() {
	const dashboard = readJson('src/manifest.json').pages.find((p) => p.id === 'Dashboard')
	return dashboard.config.widgets.find((w) => w.id === 'open-commitments-overdue')
}

describe('commitments pages', () => {
	it('puts Commitments in the menu, on its own route', () => {
		expect(fragment.menu).toContainEqual(expect.objectContaining({ id: 'Commitments', route: 'Commitments' }))
		expect(indexPage).toMatchObject({ route: '/commitments', type: 'index' })
		expect(detailPage).toMatchObject({ route: '/commitments/:id', type: 'detail' })
	})

	it('refers to the schema by slug on both pages', () => {
		for (const page of [indexPage, detailPage]) {
			expect(page.config).toMatchObject({ register: 'decidiq', schema: 'governance-commitment' })
		}
	})

	it('lists text, made by, directed to, deadline and status, and opens a row on its page', () => {
		expect(indexPage.config.columns.map((c) => c.key)).toEqual(['text', 'madeBy', 'directedTo', 'deadline', 'lifecycle'])
		expect(indexPage.config.actions).toContainEqual(expect.objectContaining({ handler: 'navigate', route: 'CommitmentDetail' }))
	})

	it('exports the list', () => {
		expect(indexPage.config.showMassExport).toBe(true)
		expect(indexPage.config.headerButtons).toContainEqual({ action: 'export' })
	})

	it('offers a lens per state and one for commitments over the deadline', () => {
		const filters = Object.fromEntries(indexPage.config.quickFilters.map((q) => [q.label, q.filter]))
		expect(filters['Still open']).toEqual({ lifecycle: 'open' })
		expect(filters['In execution']).toEqual({ lifecycle: 'in-execution' })
		expect(filters['Over the deadline']).toEqual({ 'deadline[lt]': '@today', lifecycle: ['open', 'in-execution'] })
	})

	it('shows files, related objects and the history on the detail page', () => {
		const types = detailPage.config.widgets.map((w) => w.integrationId || w.type)
		expect(types).toEqual(expect.arrayContaining(['data', 'files', 'related']))
		expect(detailPage.config.sidebar.tabs.flatMap((t) => t.widgets.map((w) => w.type))).toContain('audit')
	})
})

/**
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-007-dashboard-kpi-for-overdue-open-commitments
 */
describe('commitments over deadline tile', () => {
	const lens = indexPage.config.quickFilters.find((q) => q.label === 'Over the deadline').filter

	it('counts what the Over the deadline lens lists', () => {
		const { source } = overdueTile().content
		expect(source).toMatchObject({ register: 'decidiq', schema: 'governance-commitment', metric: 'count' })
		expect(source.filter).toEqual(lens)
	})

	it('counts through the list endpoint, which resolves @today and a list of states', () => {
		expect(overdueTile().content.source.via).toBe('list')
	})

	it('opens the index on that same lens', () => {
		const { route } = overdueTile().content
		expect(route.name).toBe(indexPage.id)
		expect(route.query).toEqual(lens)
	})

	it('never counts a commitment whose deadline has not passed', () => {
		expect(overdueTile().content.source.filter).toHaveProperty(['deadline[lt]'], '@today')
	})
})
