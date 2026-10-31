/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Office papers converted to PDF (agenda-office-files-to-pdf, matrix row
 * age-17): turns an object's `paperRenditions` into the entries the Papers
 * widget shows. A member sees each paper once, as its PDF; the chair and the
 * secretariat also get the original and a Try again for a failed conversion.
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */
import { generateUrl } from '@nextcloud/router'

/**
 * The kind of Office file, for the "Original (Word)" label.
 */
const KINDS = {
	doc: 'Word',
	docx: 'Word',
	odt: 'Word',
	rtf: 'Word',
	xls: 'Excel',
	xlsx: 'Excel',
	ods: 'Excel',
	ppt: 'PowerPoint',
	pptx: 'PowerPoint',
	odp: 'PowerPoint',
}

/**
 * A file name without its extension, and the Office kind of the extension.
 *
 * @param {string} name The file name.
 * @return {{title: string, kind: string}} The title and the kind ('' when unknown).
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */
export function splitName(name) {
	const text = String(name || '')
	const dot = text.lastIndexOf('.')
	if (dot <= 0) return { title: text, kind: '' }
	return {
		title: text.slice(0, dot),
		kind: KINDS[text.slice(dot + 1).toLowerCase()] || '',
	}
}

/**
 * The link that opens a Nextcloud file by id.
 *
 * @param {number} fileId The file id.
 * @return {string} The URL.
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */
export function fileUrl(fileId) {
	return generateUrl(`/f/${fileId}`)
}

/**
 * The entries of the Papers widget, one per Office paper.
 *
 * @param {Array<object>} renditions The object's `paperRenditions`.
 * @param {boolean} canManage Whether the caller is chair, secretary or admin.
 * @return {Array<{key: number, title: string, pdfUrl: string, originalUrl: string, originalKind: string, failure: string, canRetry: boolean}>} The entries.
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */
export function paperEntries(renditions, canManage) {
	return (Array.isArray(renditions) ? renditions : [])
		.filter((entry) => entry && Number.isInteger(entry.sourceFileId))
		.map((entry) => {
			const { title, kind } = splitName(entry.sourceName)
			const converted = Number.isInteger(entry.pdfFileId)
			return {
				key: entry.sourceFileId,
				title,
				pdfUrl: converted ? fileUrl(entry.pdfFileId) : '',
				// Without a PDF the original is the paper, for everyone.
				originalUrl:
					canManage || !converted ? fileUrl(entry.sourceFileId) : '',
				originalKind: kind,
				failure: converted ? '' : String(entry.failure || ''),
				canRetry: canManage && !converted,
			}
		})
}

/**
 * The address that queues a new conversion of one paper.
 *
 * @param {string} schema 'agenda-item' or 'meeting'.
 * @param {string} objectId The object uuid.
 * @param {number} fileId The Office paper's file id.
 * @return {string} The URL.
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */
export function retryUrl(schema, objectId, fileId) {
	return generateUrl(
		`/apps/decidiq/api/papers/${schema}/${objectId}/${fileId}/convert`,
	)
}

/**
 * The administrator switch as the admin page shows it. Conversion is on
 * unless the setting reads as off, the same words OfficePaperAddedListener
 * treats as off.
 *
 * @param {object} settings GET /api/settings.
 * @return {{on: boolean, needsFilinq: boolean}}
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off
 */
export function conversionSetting(settings) {
	const value = String(settings?.convert_office_papers ?? 'true')
		.trim()
		.toLowerCase()
	return {
		on: !['false', '0', 'no', 'off'].includes(value),
		needsFilinq: settings?.filinq !== true,
	}
}

/**
 * The PUT /api/settings body for the switch.
 *
 * @param {boolean} on Whether conversion is on.
 * @return {{convert_office_papers: string}}
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off
 */
export function conversionPayload(on) {
	return { convert_office_papers: on ? 'true' : 'false' }
}
