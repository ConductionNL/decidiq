// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * motions-export-with-attachments (matrix rows mot-16, pub-17): the Motions
 * and Decisions lists offer Export with attachments, for the selected rows or
 * every row matching the list's filter.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	createExportBundleHandlers,
	exportRequest,
	filterFromQuery,
} from '../../src/utils/exportBundle.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('export with attachments (REQ-MXP-001, REQ-MXP-002)', () => {
	it("reads the list filter from the address, without the list's own keys", () => {
		const { filter, search } = filterFromQuery(
			{
				submittedAt: '>2026-01-01',
				lifecycle: 'adopted',
				_order: '[]',
				_search: 'housing',
				action: 'create',
				empty: '',
			},
			{ decisionType: 'motion' },
		)
		expect(filter).toEqual({
			decisionType: 'motion',
			submittedAt: '>2026-01-01',
			lifecycle: 'adopted',
		})
		expect(search).toBe('housing')
	})

	it('sends the selection for selected rows and the filter for all matching rows', () => {
		const query = { submittedAt: '>2026-01-01' }
		expect(
			exportRequest({
				format: 'zip',
				scope: 'selected',
				selectedIds: ['m-3', 'm-1'],
				query,
				list: 'Motions',
				baseFilter: { decisionType: 'motion' },
			}),
		).toEqual({ format: 'zip', list: 'Motions', ids: ['m-3', 'm-1'] })
		expect(
			exportRequest({
				format: 'pdf',
				scope: 'filter',
				selectedIds: ['m-3'],
				query,
				list: 'Motions',
				baseFilter: { decisionType: 'motion' },
			}),
		).toEqual({
			format: 'pdf',
			list: 'Motions',
			filter: { decisionType: 'motion', submittedAt: '>2026-01-01' },
			search: '',
		})
	})

	it('opens the dialog with the selection for the list it was started from', () => {
		const state = { open: false }
		const handlers = createExportBundleHandlers(state)
		handlers.exportMotionsWithAttachments({
			actionId: 'export-bundle',
			selectedIds: ['m-1', 'm-2'],
			count: 2,
		})
		expect(state).toEqual({
			open: true,
			list: 'Motions',
			baseFilter: { decisionType: 'motion' },
			selectedIds: ['m-1', 'm-2'],
		})
		handlers.exportDecisionsWithAttachments({ selectedIds: [] })
		expect(state.list).toBe('Decisions')
		expect(state.baseFilter).toEqual({})
	})

	it('declares the bulk action on both lists, with a handler the app provides', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const page = (id) => manifest.pages.find((p) => p.id === id)
		expect(page('Motions').config.bulkActions).toEqual([
			{
				id: 'export-bundle',
				label: 'Export with attachments',
				icon: 'FilePdfBox',
				handler: 'exportMotionsWithAttachments',
			},
		])
		expect(page('Decisions').config.bulkActions).toEqual([
			{
				id: 'export-bundle',
				label: 'Export with attachments',
				icon: 'FilePdfBox',
				handler: 'exportDecisionsWithAttachments',
			},
		])
		const app = read('src/App.vue')
		expect(app).toContain('createExportBundleHandlers')
		expect(app).toContain('<ExportBundleModal')
		expect(read('src/icons.js')).toContain(
			"import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'",
		)
	})
})
