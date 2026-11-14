/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The figures of the document accessibility report for one body and period,
 * and their CSV (document-accessibility-check REQ-005). The dashboard tiles
 * show the same figures declaratively; this module only reads them again for
 * the export, because the shared export has no room for the heuristic-scan
 * disclaimer the report must carry.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The scan results, in the order the report lists them. */
export const STATUSES = ['pass', 'warnings', 'fail', 'not-scanned']

const TOKEN = /^@(?:workspace|page)\.([A-Za-z0-9_]+)(\?)?$/

/**
 * One filter value with its `@workspace.<key>` token read from the page.
 *
 * @param {unknown} value The value.
 * @param {object} workspace The page's workspace context.
 * @return {{keep: boolean, value: unknown}} Whether the clause stays, and its value.
 */
function resolveValue(value, workspace) {
	if (typeof value !== 'string') {
		return { keep: true, value }
	}
	const match = value.match(TOKEN)
	if (match === null) {
		return { keep: true, value }
	}
	const found = (workspace || {})[match[1]]
	if (found === undefined || found === null || found === '') {
		// An optional token with no value drops its clause (show all); a
		// required one keeps the clause unmatched, so nothing is counted.
		return match[2] === '?' ? { keep: false, value: null } : { keep: true, value }
	}
	return { keep: true, value: found }
}

/**
 * A filter map with its workspace tokens read, unset optional clauses dropped.
 *
 * @param {object} filter `{ field: value | { op: value } }`.
 * @param {object} workspace The page's workspace context (`governanceBody`, `dateFrom`, `dateTo`, …).
 * @return {object} The resolved filter.
 */
export function resolveWorkspaceFilter(filter, workspace) {
	const out = {}
	for (const [field, value] of Object.entries(filter || {})) {
		if (value && typeof value === 'object' && !Array.isArray(value)) {
			const inner = {}
			for (const [op, operand] of Object.entries(value)) {
				const r = resolveValue(operand, workspace)
				if (r.keep) {
					inner[op] = r.value
				}
			}
			if (Object.keys(inner).length > 0) {
				out[field] = inner
			}
			continue
		}
		const r = resolveValue(value, workspace)
		if (r.keep) {
			out[field] = r.value
		}
	}
	return out
}

/**
 * The query parameters of a list request for a filter: `field=value`, `field[op]=value`.
 *
 * @param {object} filter The resolved filter.
 * @return {object} The parameters, asking for one row (only the total is read).
 */
export function listParams(filter) {
	const params = { _limit: 1 }
	for (const [field, value] of Object.entries(filter || {})) {
		if (value && typeof value === 'object' && !Array.isArray(value)) {
			for (const [op, operand] of Object.entries(value)) {
				params[`${field}[${op}]`] = operand
			}
		} else {
			params[field] = value
		}
	}
	return params
}

/**
 * A share as a percentage with one decimal, null without a total.
 *
 * @param {number} count The count.
 * @param {number} total The total.
 * @return {number|null}
 */
export function shareOf(count, total) {
	if (!(Number(total) > 0)) {
		return null
	}
	return Math.round((Number(count) / Number(total)) * 1000) / 10
}

/**
 * The rows of the report: each status with its count and share.
 *
 * @param {Record<string, number>} counts The count per status.
 * @return {Array<{status: string, count: number, share: (number|null)}>}
 */
export function reportRows(counts) {
	const total = STATUSES.reduce((sum, status) => sum + (Number(counts?.[status]) || 0), 0)
	return STATUSES.map((status) => {
		const count = Number(counts?.[status]) || 0
		return { status, count, share: shareOf(count, total) }
	})
}

/**
 * One CSV cell: quoted when it needs to be, and never read as a formula.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
export function csvCell(value) {
	let text = value === null || value === undefined ? '' : String(value)
	if (/^[=+\-@\t\r]/.test(text)) {
		text = `'${text}`
	}
	if (/[",\r\n;]/.test(text)) {
		return `"${text.replace(/"/g, '""')}"`
	}
	return text
}

/**
 * The CSV of the report.
 *
 * @param {object} report The report.
 * @param {string} report.title The report title.
 * @param {Array<[string, string]>} report.scope Label and value of what the figures cover (body, period).
 * @param {{result: string, documents: string, share: string}} report.headers The column headers.
 * @param {Array<{label: string, count: number, share: (number|null)}>} report.rows The rows, labelled.
 * @param {{label: string, count: (number|null)}} report.overrides The override count.
 * @param {string} report.disclaimer The heuristic-scan disclaimer.
 * @return {string} The CSV, CRLF-separated.
 */
export function reportCsv(report) {
	const lines = [[report.title]]
	for (const [label, value] of report.scope || []) {
		lines.push([label, value])
	}
	lines.push([])
	lines.push([report.headers.result, report.headers.documents, report.headers.share])
	for (const row of report.rows || []) {
		lines.push([row.label, row.count, row.share === null ? '' : `${row.share}%`])
	}
	lines.push([])
	lines.push([report.overrides.label, report.overrides.count === null ? '' : report.overrides.count])
	lines.push([])
	lines.push([report.disclaimer])
	return lines.map((cells) => cells.map(csvCell).join(',')).join('\r\n') + '\r\n'
}

/**
 * How many objects of a schema match a filter, read from the list total.
 *
 * @param {string} register The register slug.
 * @param {string} schema The schema slug.
 * @param {object} filter The resolved filter.
 * @return {Promise<number|null>} The total, null when the answer has none.
 */
export async function countOf(register, schema, filter) {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/{register}/{schema}', { register, schema }),
		{ params: listParams(filter) },
	)
	return typeof data?.total === 'number' ? data.total : null
}

/**
 * Read the figures for the page's body and period.
 *
 * @param {object} content The widget content: `register`, `schema`, `filter`, and `overrides: {schema, filter}`.
 * @param {object} workspace The page's workspace context.
 * @return {Promise<{counts: Record<string, number>, overrides: (number|null)}>}
 */
export async function readFigures(content, workspace) {
	const register = content.register || 'decidiq'
	const base = resolveWorkspaceFilter(content.filter, workspace)
	const counts = {}
	for (const status of STATUSES) {
		counts[status] = (await countOf(register, content.schema, { ...base, status })) || 0
	}
	let overrides = null
	if (content.overrides && content.overrides.schema) {
		overrides = await countOf(register, content.overrides.schema, resolveWorkspaceFilter(content.overrides.filter, workspace))
	}
	return { counts, overrides }
}
