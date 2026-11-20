/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboard's joining-and-leaving widgets (member-onboarding, Task 4):
 * each counts a declared schema by its declared lifecycle states, and each
 * KPI opens the index pre-filtered to the set it counts.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-011-griffie-progress-dashboard
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file under the repository root.
 *
 * @param {...string} parts Path parts.
 * @return {object} The decoded file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const dashboard = readJson('src', 'manifest.json').pages.find((page) => page.id === 'Dashboard')
const widget = (id) => dashboard.config.widgets.find((w) => w.id === id)
const pages = readJson('src', 'manifest.d', 'member-onboarding.json').pages
const fragment = readJson('lib', 'Settings', 'register.d', '88-member-onboarding-in-plain-words.json').components.schemas
const lifecycleOf = {
	'member-onboarding': fragment.MemberOnboarding['x-openregister-lifecycle'],
	'member-offboarding': fragment.MemberOffboarding['x-openregister-lifecycle'],
}

describe('joining and leaving on the dashboard', () => {
	it.each([
		['onboarding-open', 'member-onboarding', 'MemberOnboarding'],
		['offboarding-open', 'member-offboarding', 'MemberOffboarding'],
	])('%s counts the unfinished records and opens the same set', (id, schema, route) => {
		const { source, route: target } = widget(id).content
		const lifecycle = lifecycleOf[schema]
		const unfinished = lifecycle.states.filter((state) => !lifecycle.terminal.includes(state))

		expect(source).toEqual({ register: 'decidiq', schema, metric: 'count', filter: { lifecycle: { in: unfinished } } })
		expect(target).toEqual({ name: route, query: { lifecycle: unfinished } })
		expect(pages.map((page) => page.id)).toContain(route)
	})

	it('counts each schema per declared state', () => {
		const entries = widget('member-pathways-by-status').content.entries

		expect(entries).toHaveLength(4)
		for (const entry of entries) {
			expect(lifecycleOf[entry.schema].states).toContain(entry.filter.lifecycle)
			expect(entry).toMatchObject({ register: 'decidiq', metric: 'count' })
		}
	})

	it('places each widget once, below the existing bands', () => {
		const layout = dashboard.config.layout
		const placed = layout.filter((cell) => ['onboarding-open', 'offboarding-open', 'member-pathways-by-status'].includes(cell.widgetId))

		expect(placed).toHaveLength(3)
		const others = layout.filter((cell) => !placed.includes(cell))
		const bottom = Math.max(...others.map((cell) => cell.gridY + cell.gridHeight))
		for (const cell of placed) {
			expect(cell.gridY).toBeGreaterThanOrEqual(bottom)
		}
		expect(placed.reduce((sum, cell) => sum + cell.gridWidth, 0)).toBe(12)
	})
})
