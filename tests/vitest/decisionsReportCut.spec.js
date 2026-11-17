/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Decisions report's period and body cut (ins-02). The
 * report is manifest configuration over CnDashboardPage, so the tests read
 * the DecisionsReport page from src/manifest.json and run its filters through
 * the library's own token resolver: the same resolve-then-drop step the stat
 * and table widgets apply before they ask OpenRegister.
 *
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-002-every-figure-on-the-decisions-report-follows-the-chosen-period
 */

import { interpolateUrlTokens } from '@conduction/nextcloud-vue/src/utils/interpolateUrlTokens.js'
import {
	dropOptionalUnresolved,
	resolveFilterTokens,
} from '@conduction/nextcloud-vue/src/utils/resolveFilterTokens.js'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(
	fileURLToPath(new URL('../../src/manifest.json', import.meta.url)),
	'utf8',
))
const page = manifest.pages.find((p) => p.id === 'DecisionsReport')
const widgets = page.config.widgets
const byId = (id) => widgets.find((w) => w.id === id)

const college = '7f1e2d3c-0000-4000-8000-00000000c011'
const lastYear = { dateFrom: '2025-01-01T00:00:00.000Z', dateTo: '2025-12-31T23:59:59.999Z' }

/**
 * Resolve a filter the way the stat and table widgets do.
 *
 * @param {object} filter The manifest filter.
 * @param {object} workspace The page's workspace context.
 * @return {object} The filter sent to OpenRegister.
 */
function sent(filter, workspace) {
	return dropOptionalUnresolved(resolveFilterTokens(filter, { workspace }))
}

describe('DecisionsReport period picker', () => {
	it('starts on this year and offers this year, this quarter, all and a custom range', () => {
		expect(page.config.dateRange.enabled).toBe(true)
		expect(page.config.dateRange.default).toEqual({ preset: 'this-year' })
		expect(page.config.dateRange.presets.map((p) => p.id)).toEqual(['this-year', 'this-quarter', 'all', 'custom'])
	})

	it('makes this year and this quarter calendar periods, and all an open range', () => {
		const preset = (id) => page.config.dateRange.presets.find((p) => p.id === id)
		expect(preset('this-year').period).toBe('year')
		expect(preset('this-quarter').period).toBe('quarter')
		expect(preset('all').days).toBeNull()
		expect(preset('all').period).toBeUndefined()
	})

	it('counts every stat on decisionDate within the chosen period', () => {
		const stats = widgets.filter((w) => w.type === 'stat')
		expect(stats.map((w) => w.id)).toEqual(['dec-adopted', 'dec-rejected', 'dec-public'])
		expect(sent(byId('dec-adopted').content.source.filter, lastYear)).toEqual({
			outcome: 'adopted',
			decisionDate: { gte: lastYear.dateFrom, lte: lastYear.dateTo },
		})
		for (const w of stats) {
			expect(sent(w.content.source.filter, lastYear).decisionDate).toEqual({ gte: lastYear.dateFrom, lte: lastYear.dateTo })
		}
	})

	it('drops an open bound instead of sending the token', () => {
		expect(sent(byId('dec-rejected').content.source.filter, { dateFrom: '', dateTo: '' })).toEqual({ outcome: 'rejected' })
		expect(sent(byId('dec-public').content.source.filter, { dateFrom: lastYear.dateFrom })).toEqual({
			isPublished: 'public',
			decisionDate: { gte: lastYear.dateFrom },
		})
	})

	it('cuts the outcome, type and stage donuts through the grouped aggregation with the same bounds', () => {
		for (const [id, groupBy] of [['dec-by-outcome', 'outcome'], ['dec-by-type', 'decisionType'], ['dec-by-lifecycle', 'lifecycle']]) {
			const src = byId(id).content.endpointSource
			expect(src.url).toBe('/apps/openregister/api/objects/aggregations/decidiq/decision/grouped')
			expect(src.responsePath).toBe('groups')
			expect(sent(src.params, { ...lastYear, decidingBody: college })).toEqual({
				groupBy,
				metric: 'count',
				'filter[decisionDate][gte]': lastYear.dateFrom,
				'filter[decisionDate][lte]': lastYear.dateTo,
				'filter[decidingBody]': college,
			})
		}
	})
})

describe('DecisionsReport body picker', () => {
	it('lists the installation\'s governance bodies and can be cleared to all bodies', () => {
		expect(page.config.pageFilters).toEqual([{
			key: 'decidingBody',
			label: 'Body',
			optionsFrom: { register: 'decidiq', schema: 'governance-body', labelField: 'name' },
			clearable: true,
			placeholder: 'All bodies',
		}])
	})

	it('counts only the picked body\'s decisions', () => {
		expect(sent(byId('dec-adopted').content.source.filter, { decidingBody: college })).toEqual({
			outcome: 'adopted',
			decidingBody: college,
		})
		expect(sent(byId('dec-recent').content.filter, { decidingBody: college })).toEqual({ decidingBody: college })
	})

	it('counts every body when none is picked, and the By body chart groups on decidingBody', () => {
		expect(sent(byId('dec-adopted').content.source.filter, { decidingBody: null })).toEqual({ outcome: 'adopted' })
		const byBody = byId('dec-by-body').content.dataSource
		expect(byBody.aggregate.groupBy).toBe('decidingBody')
		expect(byBody.aggregate.labelResolve).toEqual({ register: 'decidiq', schema: 'governance-body', labelField: 'name' })
		expect(page.config.layout.some((l) => l.widgetId === 'dec-by-body')).toBe(true)
	})
})

describe('DecisionsReport opens its decisions as a list', () => {
	const action = page.config.headerActions.find((a) => a.id === 'dec-report-show-list')

	it('is a Show these decisions link to the Decisions list', () => {
		expect(action.type).toBe('navigate')
		expect(action.label).toBe('Show these decisions')
		const decisions = manifest.pages.find((p) => p.id === 'Decisions')
		expect(action.target.split('?')[0]).toBe(decisions.route)
	})

	it('carries the picked body and period into the list query', () => {
		const target = interpolateUrlTokens(action.target, { workspace: { ...lastYear, decidingBody: college } })
		const query = new URLSearchParams(target.split('?')[1])
		expect(query.get('decidingBody')).toBe(college)
		expect(query.get('decisionDate[gte]')).toBe(lastYear.dateFrom)
		expect(query.get('decisionDate[lte]')).toBe(lastYear.dateTo)
	})

	it('leaves an unpicked body and an open period empty, which the list ignores', () => {
		const target = interpolateUrlTokens(action.target, { workspace: {} })
		expect(target).toBe('/decisions?decidingBody=&decisionDate[gte]=&decisionDate[lte]=')
	})
})
