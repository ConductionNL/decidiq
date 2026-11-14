/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the simple dashboard asks OpenRegister, and whether its attention card
 * can show at all.
 *
 * `simpleListAndDashboard.spec.js` compares FILTERS. Two defects found live in
 * the dossiq and pipelinq dashboards passed that kind of comparison:
 *
 *   - a filter with a nested operator (`{ field: { lt: ... } }`) leaves the
 *     browser as JSON, and OpenRegister answers 500;
 *   - nextcloud-vue 2.60.0 gives up the cell of a banner whose `content.text`
 *     is empty BEFORE it reads `visibleWhen`, so the card can never show.
 *
 * So this spec builds the request with the library's own calls, reads the
 * address, and evaluates the card's condition with the library's own
 * `readVisibleWhenValue` and `compareVisibleWhen` on a stubbed fetch.
 *
 * @spec openspec/changes/simple-list-and-dashboard/specs/dashboard/spec.md#requirement-req-sld-002-a-number-and-the-list-it-opens-ask-the-same-question
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { buildQueryString } from '@conduction/nextcloud-vue/src/utils/headers.js'
import { resolveFilterTokens } from '@conduction/nextcloud-vue/src/utils/resolveFilterTokens.js'
import {
	compareVisibleWhen,
	readVisibleWhenValue,
} from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file under the repository root.
 *
 * @param {...string} parts Path parts.
 * @return {object} The parsed file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const built = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
)
const dashboard = built.pages.find((page) => page.id === 'Dashboard')
const widget = (id) => dashboard.config.widgets.find((item) => item.id === id)

/**
 * Every count source a page declares: a condition's, a tile's, a bar's.
 *
 * @param {object} page A built page.
 * @return {Array<{id: string, filter: object}>} The sources' filters.
 */
function countFilters(page) {
	const out = []
	for (const item of page.config?.widgets ?? []) {
		for (const source of [
			item.visibleWhen?.source,
			item.content?.visibleWhen?.source,
			item.content?.source,
		]) {
			if (source?.filter) out.push({ id: item.id, filter: source.filter })
		}
		for (const entry of item.content?.entries ?? []) {
			if (entry?.filter) out.push({ id: item.id, filter: entry.filter })
		}
	}
	return out
}

describe('what the simple structure asks OpenRegister', () => {
	it('asks the attention count with plain fields, never with JSON', () => {
		const source = widget('simple-first-today').content.visibleWhen.source
		const query = buildQueryString({
			...resolveFilterTokens(source.filter, {}),
			_limit: 1,
		})
		const params = new URLSearchParams(query)
		expect(params.get('lifecycle')).toBe('voting')
		expect(params.get('_limit')).toBe('1')
		expect(decodeURIComponent(query)).not.toContain('{')
		expect(decodeURIComponent(query)).not.toContain('@')
	})

	it('holds no nested operator in any count the simple structure ADDS', () => {
		// A nested operator in a condition goes out as JSON and is answered
		// with a 500. The flat key `"field[lt]"` is the form that works.
		const simpleFile = readJson('src', 'menu-layout.simple.json')
		const added = []
		for (const overlay of simpleFile.pages) {
			for (const item of overlay.configAppend?.widgets ?? []) {
				for (const found of countFilters({ config: { widgets: [item] } })) {
					added.push({ ...found, page: overlay.id })
				}
			}
		}
		const hub = built.pages.find((page) => page.id === 'RegistersHub')
		expect(countFilters(hub)).toEqual([])
		expect(added.length).toBeGreaterThan(0)
		for (const { page, id, filter } of added) {
			for (const [field, value] of Object.entries(filter)) {
				expect(typeof value, `${page} ${id}: ${field}`).not.toBe('object')
			}
			expect(JSON.stringify(Object.values(filter))).not.toContain('{')
		}
	})

	it('holds no nested operator in any CONDITION on the dashboard, old or new', () => {
		for (const item of dashboard.config.widgets) {
			const filter =
				item.visibleWhen?.source?.filter
				?? item.content?.visibleWhen?.source?.filter
			for (const [field, value] of Object.entries(filter ?? {})) {
				expect(typeof value, `${item.id}: ${field}`).not.toBe('object')
			}
		}
	})
})

describe('whether the attention card shows', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	/**
	 * Evaluate the card's condition the way the dashboard does, against
	 * OpenRegister's list answer for a `_limit=1` read.
	 *
	 * @param {number} total The total OpenRegister reports.
	 * @return {Promise<{met: boolean, value: unknown, url: string}>} The verdict, the value and the address asked.
	 */
	async function verdict(total) {
		const asked = []
		vi.stubGlobal('fetch', async (url) => {
			asked.push(String(url))
			return {
				ok: true,
				status: 200,
				json: async () => ({
					results: total > 0 ? [1] : [],
					total,
					page: 1,
					pages: total,
					limit: 1,
				}),
			}
		})
		const cond = widget('simple-first-today').content.visibleWhen
		const value = await readVisibleWhenValue(cond)
		return {
			met: compareVisibleWhen(value, cond.op || 'eq', cond.value),
			value,
			url: asked[0],
		}
	}

	it('is met on the total, not on the one row a _limit=1 read returns', async () => {
		const many = await verdict(7)
		expect(many.value).toBe(7)
		expect(many.met).toBe(true)
		const address = decodeURIComponent(many.url)
		expect(address).toContain('/decidiq/decision')
		expect(address).toContain('lifecycle=voting')
		expect(address).toContain('_limit=1')
		expect(address).not.toContain('{')

		const none = await verdict(0)
		expect(none.value).toBe(0)
		expect(none.met).toBe(false)
	})

	it('carries a text, because the dashboard gives up the cell of a banner without one', () => {
		// CnDashboardPage.isCollapsedWidget collapses the cell of a banner
		// whose `content.text` is empty BEFORE the condition is looked at. A
		// met condition is not enough.
		const card = widget('simple-first-today')
		expect(card.type).toBe('banner')
		expect(card.content.text).toBeTruthy()
		expect(card.content.text).toBe(card.content.title)
		expect(card.content.reason).toContain('{value}')
		expect(card.visibleWhen ?? card.content.visibleWhen).toBeTruthy()
	})
})
