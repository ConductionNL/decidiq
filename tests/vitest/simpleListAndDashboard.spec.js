/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboard and the proposal and decision lists in the simple structure.
 *
 * Three things have to hold, and none of them shows in a screenshot review:
 *   - no widget and no view of the full structure is dropped;
 *   - every number is a plain-equality filter on a field the schema has;
 *   - a number and the list it opens ask the same question.
 *
 * @spec openspec/changes/simple-list-and-dashboard/specs/dashboard/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { validateManifest } from '@conduction/nextcloud-vue/src/utils/validateManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

/**
 * Build one structure from the manifest, the fragments and a profile file.
 *
 * @param {string} file The profile file under src/.
 * @return {object} The built manifest.
 */
function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', file),
	)
}

/**
 * The properties of one schema, base register plus fragments, by slug.
 *
 * @param {string} slug The schema slug.
 * @return {object} The merged properties.
 */
function schemaProperties(slug) {
	const properties = {}
	const files = [path.join(ROOT, 'lib', 'Settings', 'decidesk_register.json')]
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const name of fs.readdirSync(dir).sort()) {
		if (name.endsWith('.json')) files.push(path.join(dir, name))
	}
	for (const file of files) {
		const schemas =
			JSON.parse(fs.readFileSync(file, 'utf8')).components?.schemas ?? {}
		for (const schema of Object.values(schemas)) {
			if (schema?.slug !== slug) continue
			for (const [name, added] of Object.entries(schema.properties ?? {})) {
				properties[name] = { ...(properties[name] ?? {}), ...added }
			}
		}
	}
	return properties
}

const simple = build('menu-layout.simple.json')
const full = build('menu-layout.json')
const pageOf = (built, id) => built.pages.find((page) => page.id === id)
const dutch = readJson('l10n', 'nl.json').translations
const decision = schemaProperties('decision')
const commitment = schemaProperties('governance-commitment')

/**
 * A filter as the query string of a list link spells it: every value a string.
 *
 * @param {object} filter A widget filter.
 * @return {object} The same keys with string values.
 */
function asQuery(filter) {
	return Object.fromEntries(
		Object.entries(filter).map(([key, value]) => [key, String(value)]),
	)
}

describe('the dashboard in the simple structure', () => {
	const before = pageOf(full, 'Dashboard').config
	const config = pageOf(simple, 'Dashboard').config
	const widgets = new Map(config.widgets.map((item) => [item.id, item]))

	it('is unchanged in the full structure', () => {
		expect(pageOf(full, 'Dashboard')).toEqual(
			readJson('src', 'manifest.json').pages.find(
				(page) => page.id === 'Dashboard',
			),
		)
	})

	it('keeps every widget the full dashboard has, as it was', () => {
		for (const widget of before.widgets) {
			if (widget.id === 'open-commitments-overdue') continue
			expect(widgets.get(widget.id), widget.id).toEqual(widget)
		}
		// One tile is reworded, because its label was cut off in a
		// three-column tile. Its count, its filter and its link are untouched.
		const old = before.widgets.find(
			(widget) => widget.id === 'open-commitments-overdue',
		)
		const now = widgets.get('open-commitments-overdue')
		expect(now.content.label).toBe('Past the deadline')
		expect({
			...now,
			title: old.title,
			content: { ...now.content, label: old.content.label },
		}).toEqual(old)
		expect(config.widgets).toHaveLength(before.widgets.length + 4)
	})

	it('lays every old card out once, in two columns as the board draws them', () => {
		// DcDashboard (6 October 2026): under the counters a main column eight
		// wide (proposals per step, pending votes, my action items) and a side
		// column four wide (upcoming meetings, commitments with a deadline).
		// The widgets the board does not show follow below at full width, in
		// the order they had.
		const placed = (id) => config.layout.find((item) => item.widgetId === id)
		for (const old of before.layout) {
			expect(placed(old.widgetId), old.widgetId).toBeDefined()
		}
		const main = [
			'simple-proposals-per-step',
			'pending-votes-list',
			'my-action-items',
		]
		const side = ['upcoming-meetings-list', 'simple-commitments-due']
		for (const id of main) {
			expect(placed(id).gridX, id).toBe(0)
			expect(placed(id).gridWidth, id).toBe(8)
		}
		for (const id of side) {
			expect(placed(id).gridX, id).toBe(8)
			expect(placed(id).gridWidth, id).toBe(4)
		}
		// Each column reads top to bottom in the board's order, starting right
		// under the counters.
		for (const column of [main, side]) {
			expect(placed(column[0]).gridY).toBe(6)
			for (let at = 1; at < column.length; at++) {
				expect(placed(column[at]).gridY).toBeGreaterThan(
					placed(column[at - 1]).gridY,
				)
			}
		}
		// What the board does not show sits below both columns, full width
		// where it was full width, in the order the full dashboard had.
		const columns = new Set([...main, ...side])
		// The four counters keep their row right under the attention card.
		const counters = new Set(
			before.layout
				.filter((item) => placed(item.widgetId).gridY === 4)
				.map((item) => item.widgetId),
		)
		expect(counters.size).toBe(4)
		const rest = before.layout
			.filter(
				(item) =>
					!columns.has(item.widgetId) && !counters.has(item.widgetId),
			)
			.sort((a, b) => a.gridY - b.gridY || a.gridX - b.gridX)
		const bottom = Math.max(
			...[...columns].map((id) => placed(id).gridY + placed(id).gridHeight),
		)
		for (const old of rest) {
			const now = placed(old.widgetId)
			expect(now.gridY, old.widgetId).toBeGreaterThanOrEqual(bottom)
			expect(now.gridWidth, old.widgetId).toBe(old.gridWidth)
			expect(now.gridHeight, old.widgetId).toBe(old.gridHeight)
		}
		for (let at = 1; at < rest.length; at++) {
			expect(
				placed(rest[at].widgetId).gridY,
				rest[at].widgetId,
			).toBeGreaterThanOrEqual(placed(rest[at - 1].widgetId).gridY)
		}
	})

	it('makes opening the decisions the primary action of the attention card, drawn last', () => {
		// DcDashboard: "Lezen" outlined, then "Paraferen" in blue. The library
		// takes the first action as primary unless one says so itself.
		const actions = widgets.get('simple-first-today').content.actions
		expect(actions.map((action) => action.label)).toEqual([
			'Open the meetings',
			'Open these decisions',
		])
		expect(actions[0].primary).toBeUndefined()
		expect(actions[1].primary).toBe(true)
	})

	it('puts no two cards on one cell, and every card on a widget that exists', () => {
		const cells = new Set()
		for (const item of config.layout) {
			expect(widgets.has(item.widgetId), item.widgetId).toBe(true)
			expect(item.gridX + item.gridWidth).toBeLessThanOrEqual(12)
			for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
				for (let y = item.gridY; y < item.gridY + item.gridHeight; y++) {
					expect(
						cells.has(`${x}:${y}`),
						`${item.widgetId} at ${x}:${y}`,
					).toBe(false)
					cells.add(`${x}:${y}`)
				}
			}
		}
		const ids = config.layout.map((item) => item.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('opens with the greeting, the attention card and the four counters side by side', () => {
		const top = [...config.layout].sort(
			(a, b) => a.gridY - b.gridY || a.gridX - b.gridX,
		)
		expect(top.slice(0, 7).map((item) => item.widgetId)).toEqual([
			'simple-greeting',
			'simple-first-today',
			'active-decisions',
			'upcoming-meetings-kpi',
			'pending-votes-kpi',
			'overdue-actions-kpi',
			'simple-proposals-per-step',
		])
		// The four counters stay four tiles on one row, never stacked cards.
		for (const item of top.slice(2, 6)) {
			expect(item.gridWidth).toBe(3)
			expect(item.gridY).toBe(4)
		}
	})

	it('gives every tile on the page a label short enough not to be cut, in English and in Dutch', () => {
		// A tile is three columns wide. "Commitments over deadline" was cut to
		// "Commitments over de" in the live check, and the first version of
		// this spec only looked at the tiles it had added. So: EVERY stat
		// widget the page declares, old or new.
		const tiles = config.widgets.filter((widget) => widget.type === 'stat')
		expect(tiles.length).toBeGreaterThan(0)
		for (const tile of tiles) {
			const label = tile.content.label
			expect(label.length, label).toBeLessThanOrEqual(18)
			expect(dutch[label], label).toBeTruthy()
			expect(dutch[label].length, dutch[label]).toBeLessThanOrEqual(18)
		}
		// The control: the manifest's own label is the one that was cut.
		expect(
			before.widgets.find((widget) => widget.id === 'open-commitments-overdue')
				.content.label.length,
		).toBeGreaterThan(18)
	})

	it('gives the proposals bar room for its legend and the counts under it', () => {
		const bar = config.layout.find(
			(item) => item.widgetId === 'simple-proposals-per-step',
		)
		expect(bar.gridHeight).toBeGreaterThanOrEqual(3)
		expect(bar.sizeToContent).toBe(true)
	})

	it('counts with plain equality on fields the schema has', () => {
		const sources = [
			widgets.get('simple-first-today').content.visibleWhen.source,
			widgets.get('simple-proposals-per-step').content.source,
			widgets.get('simple-commitments-due').content.source,
		]
		for (const source of sources) {
			expect(source.register).toBe('decidiq')
			const properties =
				source.schema === 'decision'
					? decision
					: schemaProperties(source.schema)
			expect(Object.keys(properties).length, source.schema).toBeGreaterThan(0)
			for (const [field, value] of Object.entries(source.filter)) {
				expect(properties[field], `${source.schema}.${field}`).toBeDefined()
				// A scalar: not a range, not a list, not a relative date.
				expect(typeof value, field).not.toBe('object')
				expect(String(value), field).not.toContain('@')
				if (properties[field].enum) {
					expect(properties[field].enum, field).toContain(value)
				}
			}
		}
	})

	it('opens, from the attention card, the list that counts the same decisions', () => {
		const card = widgets.get('simple-first-today').content
		expect(card.layout).toBe('attention')
		expect(card.visibleWhen).toMatchObject({ op: 'gt', value: 0 })
		// The primary action (DcDashboard "Paraferen", drawn last) opens them.
		const primary = card.actions.find((action) => action.primary === true)
		const link = primary.route
		expect(link.name).toBe('Decisions')
		expect(link.query).toEqual(asQuery(card.visibleWhen.source.filter))
		// The list it opens reads the same schema, with no filter of its own.
		const list = pageOf(simple, 'Decisions').config
		expect(list.schema).toBe(card.visibleWhen.source.schema)
		expect(list.filter).toBeUndefined()
		// And that list has the view with the same filter and a count.
		expect(list.quickFilters).toContainEqual({
			label: 'Voting',
			filter: card.visibleWhen.source.filter,
			showCount: true,
		})
		expect(pageOf(simple, 'Meetings')).toBeDefined()
		expect(card.actions.find((action) => !action.primary).route).toBe('Meetings')
	})

	it('groups the proposals the way the views on the Proposals list count them', () => {
		const bar = widgets.get('simple-proposals-per-step').content
		const list = pageOf(simple, 'Motions').config
		expect(bar.source.schema).toBe(list.schema)
		expect(bar.source.filter).toEqual(list.filter)
		expect(bar.source.groupBy).toBe('lifecycle')
		expect([...bar.order].sort()).toEqual([...decision.lifecycle.enum].sort())
		expect(Object.keys(bar.labels).sort()).toEqual(
			[...decision.lifecycle.enum].sort(),
		)
		for (const view of list.quickFilters.filter(
			(item) => item.filter.lifecycle,
		)) {
			expect(bar.labels[view.filter.lifecycle]).toBe(view.label)
		}
	})

	it('lists open commitments by deadline, and "view all" opens the same ones', () => {
		const widget = widgets.get('simple-commitments-due')
		const list = widget.content
		const { source } = list
		expect(widget.type).toBe('object-table')
		expect(source.order).toEqual({ deadline: 'asc' })
		expect(commitment.deadline.format).toBe('date')
		for (const column of list.columns) {
			expect(commitment[column.key], column.key).toBeDefined()
		}
		expect(list.viewAllRoute.query).toEqual(asQuery(source.filter))
		const target = pageOf(simple, list.viewAllRoute.name).config
		expect(target.schema).toBe(source.schema)
		expect(target.quickFilters).toContainEqual({
			label: 'Open',
			filter: source.filter,
		})
		expect(pageOf(simple, list.rowRoute).type).toBe('detail')
		// Today is late: zero days left is already the error colour.
		expect(
			list.columns.find((column) => column.key === 'deadline').widgetProps
				.variantWhen[0],
		).toEqual({ op: 'lte', value: 0, variant: 'error' })
	})

	it('fits the commitments into the side column: the text wraps, the date keeps a fixed width', () => {
		// The board's narrow list: no header row, the commitment and its
		// deadline on the right. At 1440 px the side column is about 350 px
		// wide; under the auto layout a long commitment pushed the deadline
		// out of the card (seen live, 6 October 2026).
		const list = widgets.get('simple-commitments-due').content
		expect(list.hideHeader).toBe(true)
		expect(list.fixedLayout).toBe(true)
		expect(list.columns.map((column) => column.key)).toEqual([
			'text',
			'deadline',
		])
		const date = list.columns.find((column) => column.key === 'deadline')
		expect(date.width).toBe('7.5rem')
		expect(date.align).toBe('right')
		// The text column takes the rest, and wraps: a cell is one clipped
		// line by default, and a commitment is a sentence (seen live cut at
		// "Wethouder Van Dijk zegt t", 6 October 2026).
		expect(list.columns[0].width).toBeUndefined()
		expect(list.columns[0].cellClass).toBe('cn-cell--wrap')
	})
})

describe.each([
	['Motions', 'the proposals list'],
	['Decisions', 'the decisions list'],
])('%s: %s in the simple structure', (id) => {
	const before = pageOf(full, id).config
	const config = pageOf(simple, id).config

	it('keeps every view the full structure has', () => {
		for (const view of before.quickFilters ?? []) {
			const kept = config.quickFilters.find(
				(item) => item.label === view.label,
			)
			expect(kept, view.label).toMatchObject(view)
		}
	})

	it('shows five views in front, each with a count, and one default', () => {
		expect(config.quickFilterMaxVisible).toBe(5)
		expect(config.quickFilters.length).toBeGreaterThan(5)
		const front = config.quickFilters.slice(0, 5)
		// A view that leads to another page (Motions on Decisions) has no
		// count of its own: the count is on that page.
		for (const view of front.filter((item) => !item.route)) {
			expect(view.showCount, view.label).toBe(true)
		}
		expect(config.quickFilters.filter((item) => item.default)).toHaveLength(1)
		expect(config.quickFilters[0].default).toBe(true)
		const labels = config.quickFilters.map((item) => item.label)
		expect(new Set(labels).size).toBe(labels.length)
	})

	it('filters with plain equality on fields the decision has, with short Dutch labels', () => {
		for (const view of config.quickFilters) {
			for (const [field, value] of Object.entries(view.filter)) {
				expect(decision[field], `${view.label}: ${field}`).toBeDefined()
				expect(typeof value, field).not.toBe('object')
				if (decision[field].enum) {
					expect(decision[field].enum, field).toContain(value)
				}
			}
			expect(dutch[view.label], view.label).toBeTruthy()
			expect(dutch[view.label].length, view.label).toBeLessThanOrEqual(20)
		}
	})

	it('offers urgent decisions as a view', () => {
		expect(config.quickFilters).toContainEqual({
			label: 'Urgent',
			filter: { isUrgent: true },
			showCount: true,
		})
		expect(decision.isUrgent.type).toBe('boolean')
	})

	it('changes nothing else on the page', () => {
		const strip = ({
			quickFilters,
			quickFilterMaxVisible,
			headerActions,
			...rest
		}) => rest
		expect(strip(config)).toEqual(strip(before))
	})
})

describe('both', () => {
	it('read in Dutch', () => {
		const overlay = readJson('src', 'menu-layout.simple.json').pages.find(
			(page) => page.id === 'Dashboard',
		)
		const seen = new Set()
		JSON.stringify(overlay.configAppend, (key, value) => {
			if (
				['title', 'label', 'kicker', 'reason', 'emptyText'].includes(key)
				&& typeof value === 'string'
			) {
				seen.add(value)
			}
			if (key === 'labels') Object.values(value).forEach((v) => seen.add(v))
			return value
		})
		expect(seen.size).toBeGreaterThan(10)
		for (const text of seen) {
			expect(dutch[text], text).toBeTruthy()
		}
	})

	it('build into a manifest the library accepts', () => {
		const result = validateManifest(JSON.parse(JSON.stringify(simple)))
		expect(result.errors).toEqual([])
		expect(result.valid).toBe(true)
	})
})
