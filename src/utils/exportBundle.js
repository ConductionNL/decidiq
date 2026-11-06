// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Export with attachments (motions-export-with-attachments).
 *
 * The Motions and Decisions lists declare a bulk action whose handler is one
 * of the functions below. The handler opens the export dialog with the
 * selection; the dialog reads the list's filter from the address, where
 * CnIndexPage keeps it, so "all rows matching the filter" means every
 * matching row and not only the page on screen.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */

/**
 * Query keys CnIndexPage uses for itself rather than for a filter.
 */
const RESERVED = new Set(['action', 'page', 'limit'])

/**
 * The list's filter and search text, read from the route query and merged
 * over the page's own fixed filter.
 *
 * @param {object} query The route query.
 * @param {object} baseFilter The page's fixed filter (the Motions page keeps decisionType motion).
 * @return {{filter: object, search: string}} What the export endpoint takes.
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */
export function filterFromQuery(query = {}, baseFilter = {}) {
	const filter = { ...baseFilter }
	for (const [key, value] of Object.entries(query || {})) {
		if (
			key.startsWith('_')
			|| RESERVED.has(key)
			|| value === undefined
			|| value === null
			|| value === ''
		) {
			continue
		}
		filter[key] = value
	}
	const search = typeof query?._search === 'string' ? query._search : ''
	return { filter, search }
}

/**
 * The request body for the export endpoint.
 *
 * @param {object} options What the clerk chose.
 * @param {string} options.format `pdf` or `zip`.
 * @param {string} options.scope `selected` or `filter`.
 * @param {Array<string>} options.selectedIds The selected rows.
 * @param {object} options.query The route query.
 * @param {string} options.list `Motions` or `Decisions`.
 * @param {object} options.baseFilter The page's fixed filter.
 * @return {object} The body.
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */
export function exportRequest({
	format,
	scope,
	selectedIds = [],
	query = {},
	list,
	baseFilter = {},
}) {
	if (scope === 'selected') {
		return { format, list, ids: [...selectedIds] }
	}
	return { format, list, ...filterFromQuery(query, baseFilter) }
}

/**
 * The bulk action handlers, keyed by the name the manifest gives them.
 *
 * @param {object} state The reactive dialog state App.vue renders from.
 * @return {object} `exportMotionsWithAttachments` and `exportDecisionsWithAttachments`.
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */
export function createExportBundleHandlers(state) {
	const open =
		(list, baseFilter) =>
		({ selectedIds = [] } = {}) => {
			state.list = list
			state.baseFilter = baseFilter
			state.selectedIds = [...selectedIds]
			state.open = true
		}
	return {
		exportMotionsWithAttachments: open('Motions', { decisionType: 'motion' }),
		exportDecisionsWithAttachments: open('Decisions', {}),
	}
}
