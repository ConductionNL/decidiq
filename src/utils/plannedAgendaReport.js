// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Download report on the long-term agenda (planning-long-term-agenda-tracks-and-report).
 *
 * The page's header action names `downloadPlannedAgendaReport`. The handler
 * posts the list's active filters, read from the address where CnIndexPage
 * keeps them, to the report endpoint, which renders a PDF of exactly those
 * rows through filinq and saves it in the user's Files. The handler then
 * opens the file. Without filinq the endpoint saves the HTML and answers a
 * note, which the page shows.
 *
 * The request, the URL builder, the navigation and the messages are passed
 * in, so the module runs under vitest with only the translator mocked.
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */

import { translate as t } from '@nextcloud/l10n'
import { filterFromQuery } from './exportBundle.js'

/**
 * The report endpoint, relative to the app.
 */
export const REPORT_PATH = '/apps/decidiq/api/planned-agenda/report'

/**
 * The request body for the report endpoint: the list's filter and search text.
 *
 * @param {object} query The route query.
 * @return {{filter: object, search: string}} The body.
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
export function reportRequest(query = {}) {
	return filterFromQuery(query, {})
}

/**
 * The refusal's own sentence, or a general one. The refusal the page expects,
 * a filter that selects nothing, is translated here; any other sentence the
 * server wrote is shown as it came.
 *
 * @param {object} error The request's error.
 * @return {string} What to tell the user.
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
export function refusalMessage(error) {
	const message = error?.response?.data?.message
	if (message === 'No items match these filters') {
		return t('decidiq', 'No items match these filters')
	}
	if (typeof message === 'string' && message !== '') {
		return message
	}
	return t('decidiq', 'The report could not be made. Try again.')
}

/**
 * Build the Download report header-action handler.
 *
 * @param {object} deps What the handler needs.
 * @param {function(string, object): Promise<{data: object}>} deps.post Posts JSON.
 * @param {function(string): string} deps.generateUrl Builds an instance URL.
 * @param {function(string): void} deps.open Opens a URL.
 * @param {function(): object} deps.query The current route query.
 * @param {function(string, string): void} deps.notify Shows a message: kind `success`, `warning` or `error`.
 * @return {{downloadPlannedAgendaReport: function(): Promise<void>}} The handler, keyed by its manifest name.
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
export function createPlannedAgendaReportHandlers({ post, generateUrl, open, query, notify }) {
	return {
		/**
		 * Make the report for the list as filtered and open it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
		 */
		async downloadPlannedAgendaReport() {
			let data
			try {
				;({ data } = await post(generateUrl(REPORT_PATH), reportRequest(query() || {})))
			} catch (error) {
				notify('error', refusalMessage(error))
				return
			}

			if (data?.format === 'html') {
				notify('warning', t('decidiq', 'filinq is not installed, so the report was saved as HTML'))
			} else {
				notify('success', t('decidiq', 'The report was saved in your Files.'))
			}

			if (data?.fileId) {
				open(generateUrl(`/f/${data.fileId}`))
			}
		},
	}
}
