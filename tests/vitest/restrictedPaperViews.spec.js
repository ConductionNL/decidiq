/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who opened the papers of a confidentiality restriction
 * (embargo-geheimhouding task 7, REQ-EMB-007).
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import {
	viewRows,
	viewsRefusal,
	viewsUrl,
	viewTime,
} from '../../src/utils/restrictedPaperViews.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

describe('restricted paper views', () => {
	it('reads the views route of the restriction', () => {
		expect(viewsUrl('r 1')).toBe('/index.php/apps/decidiq/api/confidentiality/restrictions/r%201/views')
	})

	it('lists the openings newest first, naming the document or the file', () => {
		const rows = viewRows([
			{ actor: 'j.jansen', at: '2026-11-20T09:00:00+00:00', document: 'doc-1', agendaItem: 'item-1', fileId: 12 },
			{ actor: 'p.bos', at: '2026-11-21T10:00:00+00:00', document: '', agendaItem: 'item-1', fileId: 34 },
		])

		expect(rows.map((row) => row.reader)).toEqual(['p.bos', 'j.jansen'])
		expect(rows[0].paper).toBe('#34')
		expect(rows[1].paper).toBe('doc-1')
		expect(rows[1].agendaItem).toBe('item-1')
		expect(new Set(rows.map((row) => row.key)).size).toBe(2)
	})

	it('leaves out entries that name no reader or no time, and answers nothing for no list', () => {
		expect(viewRows([{ actor: '', at: '2026-11-21T10:00:00Z' }, { actor: 'x', at: '' }, null])).toEqual([])
		expect(viewRows(undefined)).toEqual([])
		expect(viewRows({ views: [] })).toEqual([])
	})

	it('writes the time in the locale and keeps a value that is no date', () => {
		expect(viewTime('2026-11-21T10:00:00Z', 'en-GB')).toContain('2026')
		expect(viewTime('not a date')).toBe('not a date')
	})

	it('shows the server refusal, else the fallback', () => {
		const refused = { response: { status: 403, data: { message: 'Only the chair may see who opened these papers.' } } }
		expect(viewsRefusal(refused, 'fallback')).toBe('Only the chair may see who opened these papers.')
		expect(viewsRefusal({ response: { status: 503, data: {} } }, 'fallback')).toBe('fallback')
		expect(viewsRefusal(new Error('network'), 'fallback')).toBe('fallback')
	})

	it('is placed on the confidentiality page and registered for the manifest', () => {
		const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.d/embargo-geheimhouding.json'), 'utf8'))
		const page = manifest.pages.find((one) => one.id === 'ConfidentialityRestrictionDetail')
		const widget = page.config.widgets.find((one) => one.component === 'RestrictedPaperViewsTab')
		expect(widget).toBeDefined()
		expect(widget.type).toBe('custom')
		expect(page.config.layout.some((cell) => cell.widgetId === widget.id)).toBe(true)

		const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
		expect(registry).toMatch(/RestrictedPaperViewsTab: page\(RestrictedPaperViewsTab\)/)
	})
})
