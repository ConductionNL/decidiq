/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Document accessibility check, the parts the badge, the scan detail and the
 * publication-gate dialog share: what a status is called and how it looks,
 * the fix for each finding in the reader's language, which report is a
 * file's current one, and what the publisher answers the gate.
 *
 * The scan is a heuristic: the labels say so, and nothing here claims
 * PDF/UA conformance.
 *
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-002-per-document-accessibility-status-stored-and-badged
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-004-remediation-guidance-per-finding
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The statuses a report can have, best first. */
export const STATUSES = ['pass', 'warnings', 'fail', 'not-scanned']

/**
 * The badge of a status: its text and the CSS variable it is drawn in.
 * The text carries the meaning; the colour only repeats it.
 *
 * @param {string} status The report status; anything else reads as not scanned.
 * @return {{status: string, label: string, color: string}} The badge.
 */
export function statusBadge(status) {
	switch (status) {
	case 'pass':
		return { status, label: t('decidiq', 'Accessible (heuristic check)'), color: 'var(--color-success)' }
	case 'warnings':
		return { status, label: t('decidiq', 'Accessible with warnings (heuristic check)'), color: 'var(--color-warning)' }
	case 'fail':
		return { status, label: t('decidiq', 'Not accessible (heuristic check)'), color: 'var(--color-error)' }
	default:
		return { status: 'not-scanned', label: t('decidiq', 'Not checked yet'), color: 'var(--color-text-maxcontrast)' }
	}
}

/**
 * What a finding means and how to fix it in the source document, in the
 * reader's language. The texts are the server's own (same catalogue keys),
 * so a report scanned in another language still reads in this one.
 *
 * @param {string} code The finding code.
 * @return {string} The guidance, or '' for an unknown code.
 */
export function guidanceFor(code) {
	const guidance = {
		'not-tagged': t('decidiq', 'The PDF has no structure tags, so screen readers cannot follow headings, lists and tables. Export it again from the source document with "tagged PDF" or "accessibility" switched on.'),
		'no-language': t('decidiq', 'The PDF does not state its language. Set the document language in the source document, or in the PDF properties, before exporting.'),
		'no-title': t('decidiq', 'The PDF has no document title. Fill in the title in the document properties of the source document and export again.'),
		'no-bookmarks': t('decidiq', 'This long document has no bookmarks. Use headings in the source document and export with bookmarks switched on.'),
		'no-text-layer': t('decidiq', 'This looks like a scanned document without a text layer. Export it again from the source document, or run text recognition (OCR) before uploading.'),
		'missing-alt-text': t('decidiq', 'Some images have no alternative text. Add a description to each meaningful image in the source document and export again.'),
		'too-large': t('decidiq', 'The file is too large to scan. Split it into smaller documents or reduce the image resolution.'),
		'parse-failure': t('decidiq', 'The file could not be read as a PDF. Export it again from the source document.'),
		'unsupported-format': t('decidiq', 'Only PDF documents are checked. Publish a tagged PDF version of this document where possible.'),
	}
	return Object.hasOwn(guidance, code) ? guidance[code] : ''
}

/**
 * The findings of a report as the detail shows them.
 *
 * @param {object|null} report The report.
 * @return {Array<{code: string, severity: string, evidence: string, guidance: string}>} The findings.
 */
export function findingRows(report) {
	const findings = Array.isArray(report?.findings) ? report.findings : []
	return findings
		.filter((finding) => finding && typeof finding.code === 'string')
		.map((finding) => ({
			code: finding.code,
			severity: String(finding.severity || 'info'),
			evidence: String(finding.evidence || ''),
			guidance: guidanceFor(finding.code) || String(finding.guidance || ''),
		}))
}

/**
 * Each file's current report: the newest by scannedAt, as the server keeps it.
 *
 * @param {Array<object>} reports Reports, any order.
 * @return {Map<number, object>} File id to report.
 */
export function currentReports(reports) {
	const current = new Map()
	for (const report of Array.isArray(reports) ? reports : []) {
		const fileId = reportFileId(report)
		if (fileId === null) continue
		if (isNewer(report, current.get(fileId))) {
			current.set(fileId, report)
		}
	}
	return current
}

/**
 * The file a report is about, or null when it names no valid file.
 *
 * @param {object} report The report.
 * @return {number|null} The file id.
 */
function reportFileId(report) {
	const fileId = Number(report?.fileId)
	return Number.isFinite(fileId) && fileId > 0 ? fileId : null
}

/**
 * Whether a report was scanned after the one held so far (or none is held).
 *
 * @param {object} report The candidate.
 * @param {object|undefined} held The report held so far.
 * @return {boolean} True when the candidate replaces it.
 */
function isNewer(report, held) {
	return !held || String(report.scannedAt || '') > String(held.scannedAt || '')
}

/**
 * The OpenRegister address listing the reports of one source object.
 *
 * @param {string} sourceObject The meeting or agenda item.
 * @return {string} The URL.
 */
export function reportsUrl(sourceObject) {
	return generateUrl('/apps/openregister/api/objects/decidiq/accessibility-scan-report')
		+ '?sourceObject=' + encodeURIComponent(sourceObject) + '&_limit=200'
}

/** The on-demand scan endpoint. */
export function scanUrl() {
	return generateUrl('/apps/decidiq/api/accessibility/scan')
}

/**
 * The gate's refusal in a publish answer, or null when it is something else.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The answer body.
 * @return {{mode: string, documents: Array<object>}|null} The refusal.
 */
export function gateRefusal(status, data) {
	if (status !== 409 || data?.error !== 'accessibility-gate') return null
	return {
		mode: data.mode === 'block' ? 'block' : 'warn',
		documents: Array.isArray(data.documents) ? data.documents : [],
	}
}

/**
 * Whether the publisher may go ahead: warn needs nothing more, block a reason.
 *
 * @param {string} mode `warn` or `block`.
 * @param {string} reason The reason typed.
 * @return {boolean}
 */
export function canPublishPastGate(mode, reason) {
	if (mode === 'block') return String(reason || '').trim() !== ''
	return true
}

/**
 * The fields a publish request carries once the publisher answered the gate.
 *
 * @param {string} mode `warn` or `block`.
 * @param {string} reason The reason, for block.
 * @return {object} The request fields.
 */
export function gateAnswer(mode, reason) {
	if (mode === 'block') {
		return { accessibilityOverride: { reason: String(reason || '').trim() } }
	}
	return { accessibilityAcknowledged: true }
}
