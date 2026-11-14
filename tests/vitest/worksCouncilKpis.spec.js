// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * works-council-consultation task 4: the two WOR KPIs on the dashboard.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-008-list-and-detail-pages-plus-dashboard-kpis
 */
import { needsListEndpoint } from '@conduction/nextcloud-vue/src/utils/fetchFilterCounts.js'
import { resolveFilterTokens } from '@conduction/nextcloud-vue/src/utils/resolveFilterTokens.js'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => JSON.parse(readFileSync(resolve(here, '../../', path), 'utf8'))

const dashboard = read('src/manifest.json').pages.find((p) => p.id === 'Dashboard')
const widget = (id) => dashboard.config.widgets.find((w) => w.id === id)
const lifecycle = read('lib/Settings/register.d/47-works-council-consultation.json').components.schemas.ConsultationRequest['x-openregister-lifecycle']
const seeds = read('lib/Settings/profiles/works-council.json')['x-openregister'].seedData.objects['consultation-request']

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

describe('the WOR KPIs (REQ-WCC-008)', () => {
	const open = widget('open-wor-consultations')
	const overdue = widget('wor-response-overdue')

	it('both sit on the dashboard grid and count through the list endpoint', () => {
		for (const w of [open, overdue]) {
			expect(w.type).toBe('stat')
			expect(w.content.source).toMatchObject({ register: 'decidiq', schema: 'consultation-request', metric: 'count', via: 'list' })
			expect(needsListEndpoint(w.content.source.filter)).toBe(true)
			expect(dashboard.config.layout.some((l) => l.widgetId === w.id)).toBe(true)
			expect(w.content.route.name).toBe('WorksCouncilConsultations')
		}
	})

	it('open means every state but the terminal ones', () => {
		const expected = lifecycle.states.filter((s) => !lifecycle.terminal.includes(s))
		expect(open.content.source.filter.lifecycle).toEqual(expected)
		expect(open.content.route.query.lifecycle).toEqual(expected)
	})

	it('past the requested date means open, not yet sent, and dated before today', () => {
		const states = overdue.content.source.filter.lifecycle
		expect(states).toEqual(['received', 'in-progress', 'constituencyConsultation', 'consultationMeeting', 'adopted'])
		expect(overdue.content.source.filter.requestedResponseDate).toEqual({ lt: '@today' })
		const resolved = resolveFilterTokens(overdue.content.source.filter, {})
		expect(resolved.requestedResponseDate.lt).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})

	it('both read more than zero on the works-council example set', () => {
		const at = (filter) => seeds.filter((row) => matches(row, { ...filter, ...(filter.requestedResponseDate ? { requestedResponseDate: { lt: '2026-05-01' } } : {}) })).map((r) => r.slug)
		expect(at(open.content.source.filter)).toEqual(['adviesaanvraag-reorganisatie-logistiek', 'instemmingsverzoek-werktijdenregeling'])
		expect(at(overdue.content.source.filter)).toEqual(['adviesaanvraag-reorganisatie-logistiek'])
	})
})
