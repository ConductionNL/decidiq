// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * works-council-consultation task 3: the WOR-trajecten list and page.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-008-list-and-detail-pages-plus-dashboard-kpis
 */
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => JSON.parse(readFileSync(resolve(here, '../../', path), 'utf8'))

const fragment = read('src/manifest.d/works-council-consultation.json')
const page = (id) => fragment.pages.find((p) => p.id === id)
const schema = read('lib/Settings/register.d/47-works-council-consultation.json').components.schemas.ConsultationRequest

describe('the WOR-trajecten list (REQ-WCC-008)', () => {
	const index = page('WorksCouncilConsultations')

	it('reads the consultation-request schema by slug', () => {
		expect(index.config.schema).toBe('consultation-request')
		expect(page('WorksCouncilConsultationDetail').config.schema).toBe('consultation-request')
	})

	it('filters on type and status, and on the works council through its facet', () => {
		const filters = index.config.quickFilters.map((q) => Object.keys(q.filter)[0]).filter(Boolean)
		expect(new Set(filters)).toEqual(new Set(['type', 'lifecycle']))
		expect(index.config.quickFilters.map((q) => q.filter.type).filter(Boolean)).toEqual(schema.properties.type.enum)
		expect(schema.properties.governanceBody.facetable).toBe(true)
		expect(index.config.columns.find((c) => c.key === 'governanceBody')).toMatchObject({ widget: 'fkResolve', widgetProps: { schema: 'governance-body' } })
	})
})

describe('the WOR-traject page (REQ-WCC-006, REQ-WCC-008)', () => {
	const detail = page('WorksCouncilConsultationDetail')
	const suspension = detail.config.widgets.find((w) => w.id === 'wor-traject-suspension')

	it('shows the suspension term only while one is set', () => {
		expect(suspension.content.include).toEqual(['decisionOutcome', 'decisionDate', 'suspensionTo'])
		expect(evaluateVisibleWhenLocal(suspension.visibleWhen, { suspensionTo: '2026-04-10' })).toBe(true)
		expect(evaluateVisibleWhenLocal(suspension.visibleWhen, { suspensionTo: null })).toBe(false)
		expect(evaluateVisibleWhenLocal(suspension.visibleWhen, {})).toBe(false)
		expect(detail.config.layout.some((l) => l.widgetId === 'wor-traject-suspension')).toBe(true)
	})

	it('keeps the documents and the related references', () => {
		const types = detail.config.widgets.map((w) => `${w.type}:${w.integrationId ?? ''}`)
		expect(types).toEqual(expect.arrayContaining(['integration:files', 'related:', 'data:']))
		for (const ref of ['consultationMeeting', 'agendaItem', 'constituencyConsultation', 'relatedDecision']) {
			expect(schema.properties[ref].$ref, ref).toBeTruthy()
			expect(schema.required).not.toContain(ref)
		}
	})
})
