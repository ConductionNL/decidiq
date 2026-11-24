// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * embargo-geheimhouding task 8: the four confidentiality KPIs on the dashboard.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
 */
import { needsListEndpoint } from '@conduction/nextcloud-vue/src/utils/fetchFilterCounts.js'
import { resolveFilterTokens } from '@conduction/nextcloud-vue/src/utils/resolveFilterTokens.js'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => JSON.parse(readFileSync(resolve(here, '../../', path), 'utf8'))

const manifest = read('src/manifest.json')
const dashboard = manifest.pages.find((p) => p.id === 'Dashboard')
const widget = (id) => dashboard.config.widgets.find((w) => w.id === id)
const schemaOf = (path) => read(path).components.schemas.ConfidentialityRestriction['x-openregister-lifecycle']
const base = schemaOf('lib/Settings/register.d/83-confidentiality-in-plain-words.json')
const lapse = schemaOf('lib/Settings/register.d/130-confidentiality-term-and-lapse.json')
const states = [...base.states, ...lapse.states]
const terminal = [...base.terminal, ...lapse.terminal]
const seeds = read('lib/Settings/profiles/municipality.json')['x-openregister'].seedData.objects['confidentiality-restriction']
const fragment = read('src/manifest.d/embargo-geheimhouding.json')

/**
 * Whether a record matches a widget filter as the list endpoint reads it.
 *
 * @param {object} row The record.
 * @param {object} filter The resolved filter.
 * @return {boolean}
 */
function matches(row, filter) {
	return Object.entries(filter).every(([key, value]) => {
		if (Array.isArray(value)) return value.includes(row[key])
		if (value && typeof value === 'object') return Object.entries(value).every(([op, operand]) => op === 'lt' && String(row[key] ?? '') !== '' && row[key] < operand)
		return row[key] === value
	})
}

/**
 * The seeded restrictions a widget counts on a given day.
 *
 * @param {object} w The widget.
 * @param {string} today The day `@today` stands for.
 * @return {string[]}
 */
function countedOn(w, today) {
	const filter = JSON.parse(JSON.stringify(w.content.source.filter).replaceAll('"@today"', JSON.stringify(today)))
	return seeds.filter((row) => matches(row, filter)).map((row) => row.slug)
}

describe('the confidentiality KPIs (REQ-EMB-006)', () => {
	const inForce = widget('confidentiality-in-force')
	const awaiting = widget('confidentiality-awaiting-ratification')
	const overdue = widget('confidentiality-ratification-overdue')
	const toPublish = widget('confidentiality-lifted-awaiting-publication')

	it('all four sit on the dashboard grid and open the confidentiality register', () => {
		const register = fragment.pages.find((p) => p.id === 'ConfidentialityRestrictions')
		expect(register.config.schema).toBe('confidentiality-restriction')
		for (const w of [inForce, awaiting, overdue, toPublish]) {
			expect(w.type).toBe('stat')
			expect(w.content.source).toMatchObject({ register: 'decidiq', schema: 'confidentiality-restriction', metric: 'count' })
			expect(w.content.route.name).toBe(register.id)
			expect(dashboard.config.layout.filter((l) => l.widgetId === w.id)).toHaveLength(1)
		}
	})

	it('in force means every state that is not terminal, counted through the list endpoint', () => {
		const expected = states.filter((s) => !terminal.includes(s))
		expect(expected).toEqual(['imposed', 'ratified'])
		expect(inForce.content.source.filter.lifecycle).toEqual(expected)
		expect(inForce.content.route.query.lifecycle).toEqual(expected)
		expect(inForce.content.source.via).toBe('list')
		expect(needsListEndpoint(inForce.content.source.filter)).toBe(true)
	})

	it('awaiting ratification is a scalar count the aggregation endpoint can answer', () => {
		expect(awaiting.content.source.filter).toEqual({ lifecycle: 'imposed' })
		expect(awaiting.content.route.query).toEqual({ lifecycle: 'imposed' })
		expect(awaiting.content.source.via).toBeUndefined()
		expect(needsListEndpoint(awaiting.content.source.filter)).toBe(false)
	})

	it('overdue means imposed with a ratification deadline before today', () => {
		expect(overdue.content.source.filter).toEqual({ lifecycle: 'imposed', ratificationDeadline: { lt: '@today' } })
		expect(overdue.content.route.query).toEqual({ lifecycle: 'imposed', 'ratificationDeadline[lt]': '@today' })
		expect(overdue.content.source.via).toBe('list')
		const resolved = resolveFilterTokens(overdue.content.source.filter, {})
		expect(resolved.ratificationDeadline.lt).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})

	it('reads three in force and one overdue on the municipality example set', () => {
		const imposed = ['geheimhouding-commissiestuk-vastgoed', 'geheimhouding-raadsnota-grondexploitatie', 'geheimhouding-onderbouwing-tarieven-grondexploitatie']
		expect(countedOn(inForce, '2026-09-01')).toEqual(imposed)
		expect(countedOn(awaiting, '2026-09-01')).toEqual(imposed)
		expect(countedOn(overdue, '2026-09-01')).toEqual(['geheimhouding-commissiestuk-vastgoed'])
		expect(countedOn(overdue, '2026-03-01')).toEqual([])
	})

	it('lifted and awaiting publication counts the server-written flag, a scalar count', () => {
		expect(toPublish.content.source.filter).toEqual({ awaitingPublication: true })
		expect(toPublish.content.route.query).toEqual({ awaitingPublication: true })
		expect(toPublish.content.source.via).toBeUndefined()
		expect(needsListEndpoint(toPublish.content.source.filter)).toBe(false)
		const field = read('lib/Settings/register.d/142-confidentiality-awaiting-publication.json')
			.components.schemas.ConfidentialityRestriction.properties.awaitingPublication
		expect(field).toMatchObject({ type: 'boolean', readOnly: true, facetable: true })
		const simple = JSON.stringify(read('src/menu-layout.simple.json'))
		expect(simple).toContain('"widgetId":"confidentiality-lifted-awaiting-publication"')
	})

	it('every tile label has a Dutch translation', () => {
		const nl = read('l10n/nl.json').translations
		for (const w of [inForce, awaiting, overdue, toPublish]) {
			expect(nl[w.title]).toBeTruthy()
			expect(nl[w.content.label]).toBeTruthy()
		}
	})
})
