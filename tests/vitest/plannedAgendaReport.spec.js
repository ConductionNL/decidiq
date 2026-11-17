// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Download report on the long-term agenda: the list's filters go to the
 * endpoint, the saved file opens, and the filinq note or a refusal is shown.
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
import {
	createPlannedAgendaReportHandlers,
	refusalMessage,
	REPORT_PATH,
	reportRequest,
} from '../../src/utils/plannedAgendaReport.js'

function setup({ post, query = {} } = {}) {
	const deps = {
		post: post || vi.fn(async () => ({ data: { format: 'pdf', fileId: 42, note: null } })),
		generateUrl: (path) => `/index.php${path}`,
		open: vi.fn(),
		query: () => query,
		notify: vi.fn(),
	}
	return { deps, handler: createPlannedAgendaReportHandlers(deps).downloadPlannedAgendaReport }
}

describe('reportRequest', () => {
	it('keeps the filters and search text and drops the page keys', () => {
		expect(reportRequest({ owner: 'p1', lifecycle: 'planned', page: '2', _search: 'warmte', _order: 'x', author: '' }))
			.toEqual({ filter: { owner: 'p1', lifecycle: 'planned' }, search: 'warmte' })
	})
})

describe('downloadPlannedAgendaReport', () => {
	it('posts the active filters and opens the saved PDF', async () => {
		const { deps, handler } = setup({ query: { owner: 'p1', parentItem: 'IS NULL' } })

		await handler()

		expect(deps.post).toHaveBeenCalledWith(`/index.php${REPORT_PATH}`, { filter: { owner: 'p1', parentItem: 'IS NULL' }, search: '' })
		expect(deps.open).toHaveBeenCalledWith('/index.php/f/42')
		expect(deps.notify).toHaveBeenCalledWith('success', 't:The report was saved in your Files.')
	})

	it('shows the filinq note when the HTML was saved instead', async () => {
		const post = vi.fn(async () => ({ data: { format: 'html', fileId: 7, note: 'filinq is not installed, so the report was saved as HTML' } }))
		const { deps, handler } = setup({ post })

		await handler()

		expect(deps.notify).toHaveBeenCalledWith('warning', 't:filinq is not installed, so the report was saved as HTML')
		expect(deps.open).toHaveBeenCalledWith('/index.php/f/7')
	})

	it('shows the refusal and opens nothing when no item matches', async () => {
		const post = vi.fn(async () => {
			throw Object.assign(new Error('422'), { response: { status: 422, data: { message: 'No items match these filters' } } })
		})
		const { deps, handler } = setup({ post })

		await handler()

		expect(deps.notify).toHaveBeenCalledWith('error', 't:No items match these filters')
		expect(deps.open).not.toHaveBeenCalled()
	})
})

describe('refusalMessage', () => {
	it('shows another refusal as the server wrote it', () => {
		expect(refusalMessage({ response: { data: { message: 'Sign in to download a report.' } } })).toBe('Sign in to download a report.')
	})

	it('falls back to a general sentence without a message', () => {
		expect(refusalMessage(new Error('network'))).toBe('t:The report could not be made. Try again.')
	})
})
