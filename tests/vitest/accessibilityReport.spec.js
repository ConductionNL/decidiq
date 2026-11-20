/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The accessibility report's figures for one body and period, and its CSV
 * with the heuristic-scan disclaimer (document-accessibility-check REQ-005).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => `/index.php${path.replace(/\{(\w+)\}/g, (_, k) => params[k])}`,
}))

import {
	csvCell,
	listParams,
	readFigures,
	reportCsv,
	reportRows,
	resolveWorkspaceFilter,
	shareOf,
} from '../../src/utils/accessibilityReport.js'

const FILTER = {
	governanceBody: '@workspace.governanceBody?',
	scannedAt: { gte: '@workspace.dateFrom?', lte: '@workspace.dateTo?' },
}

describe('resolveWorkspaceFilter', () => {
	it('reads the body and the period from the page', () => {
		expect(resolveWorkspaceFilter(FILTER, { governanceBody: 'raad', dateFrom: '2026-01-01', dateTo: '2026-06-30' })).toEqual({
			governanceBody: 'raad',
			scannedAt: { gte: '2026-01-01', lte: '2026-06-30' },
		})
	})

	it('drops what the page leaves unset, so all bodies and all time count', () => {
		expect(resolveWorkspaceFilter(FILTER, { dateFrom: '', dateTo: '2026-06-30' })).toEqual({ scannedAt: { lte: '2026-06-30' } })
		expect(resolveWorkspaceFilter(FILTER, {})).toEqual({})
	})

	it('keeps a required token it cannot read, and plain values as written', () => {
		expect(resolveWorkspaceFilter({ governanceBody: '@workspace.governanceBody', status: 'fail' }, {})).toEqual({
			governanceBody: '@workspace.governanceBody',
			status: 'fail',
		})
	})
})

describe('listParams', () => {
	it('asks for one row and sends operators in brackets', () => {
		expect(listParams({ status: 'fail', scannedAt: { gte: '2026-01-01' } })).toEqual({ _limit: 1, status: 'fail', 'scannedAt[gte]': '2026-01-01' })
	})
})

describe('shares', () => {
	it('is a percentage with one decimal, and none without a total', () => {
		expect(shareOf(1, 3)).toBe(33.3)
		expect(shareOf(0, 0)).toBeNull()
	})

	it('lists every status in report order with its share of all checked documents', () => {
		expect(reportRows({ pass: 6, fail: 2, warnings: 1, 'not-scanned': 1 })).toEqual([
			{ status: 'pass', count: 6, share: 60 },
			{ status: 'warnings', count: 1, share: 10 },
			{ status: 'fail', count: 2, share: 20 },
			{ status: 'not-scanned', count: 1, share: 10 },
		])
		expect(reportRows({}).map((row) => row.share)).toEqual([null, null, null, null])
	})
})

describe('csv', () => {
	it('quotes what needs quoting and never starts a cell with a formula', () => {
		expect(csvCell('Gemeenteraad')).toBe('Gemeenteraad')
		expect(csvCell('Raad, commissie')).toBe('"Raad, commissie"')
		expect(csvCell('He said "no"')).toBe('"He said ""no"""')
		expect(csvCell('=SUM(A1)')).toBe('\'=SUM(A1)')
		expect(csvCell(null)).toBe('')
		expect(csvCell(4)).toBe('4')
	})

	it('carries the scope, the figures, the overrides and the disclaimer', () => {
		const csv = reportCsv({
			title: 'Document accessibility',
			scope: [['Governance body', 'Gemeenteraad'], ['Period', '2026-01-01 – 2026-06-30']],
			headers: { result: 'Result', documents: 'Documents', share: 'Share' },
			rows: [{ label: 'Passed', count: 6, share: 60 }, { label: 'Not checked', count: 0, share: null }],
			overrides: { label: 'Published past the check', count: 2 },
			disclaimer: 'Heuristic check, not a PDF/UA audit.',
		})

		expect(csv.split('\r\n')).toEqual([
			'Document accessibility',
			'Governance body,Gemeenteraad',
			'Period,2026-01-01 – 2026-06-30',
			'',
			'Result,Documents,Share',
			'Passed,6,60%',
			'Not checked,0,',
			'',
			'Published past the check,2',
			'',
			'"Heuristic check, not a PDF/UA audit."',
			'',
		])
	})
})

describe('readFigures', () => {
	beforeEach(() => {
		http.get.mockReset()
	})

	it('counts each status and the overrides for the body and period', async () => {
		const totals = { pass: 6, warnings: 1, fail: 2, 'not-scanned': 0 }
		http.get.mockImplementation((url, { params }) => Promise.resolve({
			data: { total: url.endsWith('/accessibility-override') ? 2 : totals[params.status] },
		}))

		const figures = await readFigures({
			register: 'decidiq',
			schema: 'accessibility-scan-report',
			filter: FILTER,
			overrides: { schema: 'accessibility-override', filter: { governanceBody: '@workspace.governanceBody?', overriddenAt: { gte: '@workspace.dateFrom?' } } },
		}, { governanceBody: 'raad', dateFrom: '2026-01-01' })

		expect(figures).toEqual({ counts: totals, overrides: 2 })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/decidiq/accessibility-scan-report', {
			params: { _limit: 1, governanceBody: 'raad', 'scannedAt[gte]': '2026-01-01', status: 'fail' },
		})
		expect(http.get).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/objects/decidiq/accessibility-override', {
			params: { _limit: 1, governanceBody: 'raad', 'overriddenAt[gte]': '2026-01-01' },
		})
		expect(http.get).toHaveBeenCalledTimes(5)
	})

	it('reads no overrides when none are declared, and a missing total as zero documents', async () => {
		http.get.mockResolvedValue({ data: {} })

		expect(await readFigures({ schema: 'accessibility-scan-report' }, {})).toEqual({
			counts: { pass: 0, warnings: 0, fail: 0, 'not-scanned': 0 },
			overrides: null,
		})
		expect(http.get).toHaveBeenCalledTimes(4)
	})
})
