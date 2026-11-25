// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * records-management-archiving task 6: the two archive counters that read
 * OpenRegister's retention block (overdue transfer, unresolved retention).
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */
import { fetchListTotal } from '@conduction/nextcloud-vue/src/utils/fetchFilterCounts.js'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it, vi } from 'vitest'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => JSON.parse(readFileSync(resolve(here, '../../', path), 'utf8'))

const fragment = read('src/manifest.d/records-management.json')
const dashboard = fragment.pages.find((p) => p.id === 'ArchiveDashboard')
const index = fragment.pages.find((p) => p.id === 'ArchivalDossiers')
const widget = (id) => dashboard.config.widgets.find((w) => w.id === id)

/**
 * The query string fetchListTotal sends for a filter, with fetch stubbed.
 *
 * @param {object} filter The widget filter.
 * @return {Promise<URLSearchParams>} The parameters of the request.
 */
async function sentParams(filter) {
	const fetch = vi.fn(async () => ({ ok: true, json: async () => ({ total: 3 }) }))
	vi.stubGlobal('fetch', fetch)
	const total = await fetchListTotal('decidiq', 'archival-dossier', filter, {})
	expect(total).toBe(3)
	const url = String(fetch.mock.calls[0][0])
	expect(url).toContain('/apps/openregister/api/objects/decidiq/archival-dossier?')
	return new URLSearchParams(url.split('?')[1])
}

describe('the archive retention counters (REQ-RMA-007)', () => {
	afterEach(() => vi.unstubAllGlobals())

	const overdue = widget('dossiers-transfer-overdue')
	const unresolved = widget('dossiers-retention-unresolved')

	it('both sit on the archive dashboard and count through the list endpoint', () => {
		for (const w of [overdue, unresolved]) {
			expect(w.type).toBe('stat')
			expect(w.content.source).toMatchObject({ register: 'decidiq', schema: 'archival-dossier', metric: 'count', via: 'list' })
			expect(dashboard.config.layout.some((l) => l.widgetId === w.id)).toBe(true)
			expect(w.content.route.name).toBe('ArchivalDossiers')
		}
	})

	it('no two layout cells overlap', () => {
		const cells = new Set()
		for (const l of dashboard.config.layout) {
			for (let x = l.gridX; x < l.gridX + l.gridWidth; x++) {
				for (let y = l.gridY; y < l.gridY + l.gridHeight; y++) {
					const key = `${x},${y}`
					expect(cells.has(key), `${l.widgetId} overlaps at ${key}`).toBe(false)
					cells.add(key)
				}
			}
		}
	})

	it('overdue means closed, routed to transfer, and the resolved archive action date before today', async () => {
		const filter = overdue.content.source.filter
		expect(filter).toEqual({ lifecycle: 'closed', disposition: 'transfer', '@self.retention.archiefactiedatum': { lt: '@today' } })
		const params = await sentParams(filter)
		expect(params.get('lifecycle')).toBe('closed')
		expect(params.get('disposition')).toBe('transfer')
		// The date is read where OpenRegister writes it, never computed here.
		expect(params.get('@self.retention.archiefactiedatum[lt]')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})

	it('unresolved means OpenRegister resolved no archive nomination', async () => {
		const params = await sentParams(unresolved.content.source.filter)
		expect(params.get('@self.retention.archiefnominatie[isnull]')).toBe('true')
		expect([...params.keys()].filter((k) => k !== '_limit')).toEqual(['@self.retention.archiefnominatie[isnull]'])
	})

	it('the index offers the same two sets as quick filters', () => {
		const byLabel = Object.fromEntries(index.config.quickFilters.map((q) => [q.label, q.filter]))
		expect(byLabel['Transfer overdue']).toEqual(overdue.content.source.filter)
		expect(byLabel['Retention not resolved']).toEqual(unresolved.content.source.filter)
	})
})

describe('the meetings-without-dossier counter (REQ-RMA-007)', () => {
	afterEach(() => vi.unstubAllGlobals())

	const gap = widget('meetings-without-dossier')

	it('sits on the archive dashboard and counts closed meetings through the list endpoint', () => {
		expect(gap.type).toBe('stat')
		expect(gap.content.source).toMatchObject({ register: 'decidiq', schema: 'meeting', metric: 'count', via: 'list' })
		expect(dashboard.config.layout.some((l) => l.widgetId === gap.id)).toBe(true)
		expect(gap.content.route).toEqual({ name: 'Meetings', query: { lifecycle: 'closed', 'archivalDossier[isnull]': 'true' } })
	})

	it('is one isnull filter on the meeting, not an anti-join across schemas', async () => {
		expect(gap.content.source.filter).toEqual({ lifecycle: 'closed', archivalDossier: { isnull: true } })
		const fetch = vi.fn(async () => ({ ok: true, json: async () => ({ total: 2 }) }))
		vi.stubGlobal('fetch', fetch)
		expect(await fetchListTotal('decidiq', 'meeting', gap.content.source.filter, {})).toBe(2)
		const url = String(fetch.mock.calls[0][0])
		expect(url).toContain('/apps/openregister/api/objects/decidiq/meeting?')
		const params = new URLSearchParams(url.split('?')[1])
		expect(params.get('lifecycle')).toBe('closed')
		expect(params.get('archivalDossier[isnull]')).toBe('true')
	})

	it('reads a property the register declares on the meeting', () => {
		const fragment144 = read('lib/Settings/register.d/144-a-meeting-names-its-archival-dossier.json')
		const property = fragment144.components.schemas.Meeting.properties.archivalDossier
		expect(fragment144.components.schemas.Meeting.slug).toBe('meeting')
		expect(property).toMatchObject({ type: 'string', format: 'uuid', $ref: 'archival-dossier', nullable: true })
	})
})
