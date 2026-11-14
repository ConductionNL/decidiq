// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Annotations on meeting documents (document-annotations): the addresses of
 * the annotation API, which files can be annotated, and the geometry that
 * turns a selection, a click or a stroke on a rendered page into the
 * page-relative anchors the server stores (every coordinate between 0 and 1
 * of the page's width and height, so a mark lands in the same place at any
 * zoom).
 *
 * Who sees an annotation is decided by the server; nothing here filters for
 * privacy. The filters below only narrow what the server already returned.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */

import { generateRemoteUrl, generateUrl } from '@nextcloud/router'

/**
 * The annotation types.
 */
export const TYPES = ['highlight', 'sticky-note', 'drawing']

/**
 * The colours, each drawn with a Nextcloud colour variable.
 */
export const COLORS = {
	yellow: 'var(--color-warning)',
	blue: 'var(--color-info)',
	red: 'var(--color-error)',
	green: 'var(--color-success)',
}

/**
 * The address of a document's annotations.
 *
 * @param {number|string} fileId The Nextcloud file id.
 * @return {string} The URL.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 */
export function annotationsUrl(fileId) {
	return generateUrl(`/apps/decidiq/api/documents/${Number(fileId)}/annotations`)
}

/**
 * The address of one annotation.
 *
 * @param {string} id The annotation.
 * @return {string} The URL.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 */
export function annotationUrl(id) {
	return generateUrl(`/apps/decidiq/api/annotations/${encodeURIComponent(id)}`)
}

/**
 * The address of a meeting's counts per agenda item.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-005-annotation-counts-on-agenda-item-rows
 */
export function countsUrl(meetingId) {
	return generateUrl(
		`/apps/decidiq/api/meetings/${encodeURIComponent(meetingId)}/annotation-counts`,
	)
}

/**
 * The address of the caller's own annotations as a download.
 *
 * @param {string} format json or csv.
 * @return {string} The URL.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
 */
export function exportUrl(format) {
	const kind = format === 'csv' ? 'csv' : 'json'
	return generateUrl(`/apps/decidiq/api/annotations/export?format=${kind}`)
}

/**
 * The address that deletes all of the caller's own annotations.
 *
 * @return {string} The URL.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
 */
export function ownUrl() {
	return generateUrl('/apps/decidiq/api/annotations/own')
}

/**
 * The WebDAV address of a file's content, by its path in the user's files.
 *
 * @param {string} userId The user.
 * @param {string} path The file's path below the user's files.
 * @return {string} The URL.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */
export function davUrl(userId, path) {
	const segments = String(path || '')
		.split('/')
		.filter((segment) => segment !== '')
		.map((segment) => encodeURIComponent(segment))
	return generateRemoteUrl(
		`dav/files/${encodeURIComponent(userId)}/${segments.join('/')}`,
	)
}

/**
 * Whether a file from OpenRegister's files API is a PDF, the only kind the
 * annotator draws on.
 *
 * @param {object} file The file.
 * @return {boolean} True for a PDF.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */
export function isPdf(file) {
	if (!file) return false
	const type = String(
		file.mimetype || file.mimeType || file.type || '',
	).toLowerCase()
	if (type === 'application/pdf') return true
	const name = String(file.title || file.name || file.path || '').toLowerCase()
	return type === '' && name.endsWith('.pdf')
}

/**
 * The PDF files of a list, as rows the annotation tab shows.
 *
 * @param {Array<object>} files Files from OpenRegister's files API.
 * @return {Array<{id: number, name: string}>} One row per PDF.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */
export function pdfRows(files) {
	return (Array.isArray(files) ? files : [])
		.filter((file) => isPdf(file) && Number(file.id) > 0)
		.map((file) => ({
			id: Number(file.id),
			name: String(file.title || file.name || file.path || file.id),
		}))
}

/**
 * The visibility choices a user can pick: faction only when the server says
 * the user sits in one.
 *
 * @param {boolean} canShareWithFaction What the server said.
 * @param {function(string, string): string} t The translate function.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 */
export function visibilityOptions(canShareWithFaction, t) {
	const options = [{ id: 'private', label: t('decidiq', 'Private') }]
	if (canShareWithFaction === true) {
		options.push({ id: 'faction', label: t('decidiq', 'My faction') })
	}
	options.push({ id: 'body', label: t('decidiq', 'Whole body') })
	return options
}

/**
 * A number clamped between 0 and 1, rounded to five decimals.
 *
 * @param {number} value The number.
 * @return {number} The clamped number.
 */
function unit(value) {
	const number = Number(value)
	if (!Number.isFinite(number)) return 0
	return Math.round(Math.min(1, Math.max(0, number)) * 100000) / 100000
}

/**
 * A point on the page, relative to the page's box.
 *
 * @param {number} clientX The pointer's x on screen.
 * @param {number} clientY The pointer's y on screen.
 * @param {{left: number, top: number, width: number, height: number}} page The page's box on screen.
 * @return {{x: number, y: number}} The point, each between 0 and 1.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
 */
export function relativePoint(clientX, clientY, page) {
	if (!page || !(page.width > 0) || !(page.height > 0)) return { x: 0, y: 0 }
	return {
		x: unit((clientX - page.left) / page.width),
		y: unit((clientY - page.top) / page.height),
	}
}

/**
 * The quads of a text selection: one per rectangle of the selection that
 * overlaps the page, as the four corners x1,y1 (top left), x2,y2 (top
 * right), x3,y3 (bottom left), x4,y4 (bottom right).
 *
 * @param {Array<{left: number, top: number, right: number, bottom: number}>} rects The selection's rectangles on screen.
 * @param {{left: number, top: number, width: number, height: number}} page The page's box on screen.
 * @return {Array<Array<number>>} The quads.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
 */
export function quadsFromRects(rects, page) {
	const quads = []
	for (const rect of Array.from(rects || [])) {
		if (!(rect.right > rect.left) || !(rect.bottom > rect.top)) continue
		const a = relativePoint(rect.left, rect.top, page)
		const b = relativePoint(rect.right, rect.bottom, page)
		if (b.x <= a.x || b.y <= a.y) continue
		quads.push([a.x, a.y, b.x, a.y, a.x, b.y, b.x, b.y])
		if (quads.length === 200) break
	}
	return quads
}

/**
 * A quad as an SVG rectangle in a box of the given size.
 *
 * @param {Array<number>} quad The quad.
 * @param {number} width The box width.
 * @param {number} height The box height.
 * @return {{x: number, y: number, width: number, height: number}} The rectangle.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */
export function quadRect(quad, width, height) {
	const xs = [quad[0], quad[2], quad[4], quad[6]]
	const ys = [quad[1], quad[3], quad[5], quad[7]]
	const left = Math.min(...xs)
	const top = Math.min(...ys)
	return {
		x: round(left * width),
		y: round(top * height),
		width: round((Math.max(...xs) - left) * width),
		height: round((Math.max(...ys) - top) * height),
	}
}

/**
 * A stroke as an SVG path in a box of the given size.
 *
 * @param {Array<Array<number>>} path The stroke's points.
 * @param {number} width The box width.
 * @param {number} height The box height.
 * @return {string} The path data, empty without points.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */
export function strokePath(path, width, height) {
	return (Array.isArray(path) ? path : [])
		.map(
			(point, index) =>
				`${index === 0 ? 'M' : 'L'}${round(point[0] * width)} ${round(point[1] * height)}`,
		)
		.join(' ')
}

/**
 * A number with two decimals.
 *
 * @param {number} value The number.
 * @return {number} The rounded number.
 */
function round(value) {
	return Math.round(value * 100) / 100
}

/**
 * Thin a stroke: drop points closer than `minimum` to the last one kept, and
 * keep at most 5000.
 *
 * @param {Array<Array<number>>} path The stroke.
 * @param {number} minimum The smallest step to keep.
 * @return {Array<Array<number>>} The thinned stroke.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
 */
export function thinStroke(path, minimum = 0.002) {
	const kept = []
	for (const point of Array.isArray(path) ? path : []) {
		const last = kept[kept.length - 1]
		if (
			last
			&& Math.abs(point[0] - last[0]) < minimum
			&& Math.abs(point[1] - last[1]) < minimum
		) {
			continue
		}
		kept.push([unit(point[0]), unit(point[1])])
		if (kept.length === 5000) break
	}
	return kept
}

/**
 * The annotations to draw on a page: those on that page made on the version
 * being shown.
 *
 * @param {Array<object>} annotations The annotations.
 * @param {number} page The page number.
 * @param {string} fileVersion The version on screen.
 * @return {Array<object>} The annotations to draw.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
 */
export function drawnOnPage(annotations, page, fileVersion) {
	return (annotations || []).filter(
		(annotation) =>
			Number(annotation.page) === Number(page)
			&& String(annotation.fileVersion) === String(fileVersion),
	)
}

/**
 * The panel's list: filtered by visibility and author, ordered by page and
 * then by when it was made.
 *
 * @param {Array<object>} annotations The annotations.
 * @param {{visibility?: string, author?: string}} filter The filter; empty values match all.
 * @return {Array<object>} The list.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */
export function panelList(annotations, filter = {}) {
	return (annotations || [])
		.filter(
			(annotation) =>
				(!filter.visibility || annotation.visibility === filter.visibility)
				&& (!filter.author || annotation.author === filter.author),
		)
		.slice()
		.sort(
			(a, b) =>
				Number(a.page) - Number(b.page)
				|| String(a.createdAt || '').localeCompare(
					String(b.createdAt || ''),
				),
		)
}

/**
 * The authors of a list, each once, in order of first appearance.
 *
 * @param {Array<object>} annotations The annotations.
 * @return {Array<string>} The author ids.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */
export function authorsOf(annotations) {
	return [
		...new Set(
			(annotations || [])
				.map((annotation) => annotation.author)
				.filter(Boolean),
		),
	]
}

/**
 * The version label of an annotation made on another version than the one
 * on screen, or null when it belongs to this one.
 *
 * @param {object} annotation The annotation.
 * @param {string} fileVersion The version on screen.
 * @return {string|null} Its label, such as v1.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
 */
export function otherVersionLabel(annotation, fileVersion) {
	if (!annotation || String(annotation.fileVersion) === String(fileVersion))
		return null
	return String(annotation.versionLabel || '?')
}

/**
 * The count of an agenda item, 0 when it has none.
 *
 * @param {object|null} counts Agenda item id to count, from the server.
 * @param {string} itemId The agenda item.
 * @return {number} The count.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-005-annotation-counts-on-agenda-item-rows
 */
export function countOf(counts, itemId) {
	const count = Number(counts?.[itemId] ?? 0)
	return Number.isFinite(count) && count > 0 ? count : 0
}

/**
 * The WebDAV address of an earlier version of a file, kept by Nextcloud's
 * file versions under the file id and the version's identifier.
 *
 * @param {string} userId The user.
 * @param {number|string} fileId The file id.
 * @param {string} fileVersion The version identifier.
 * @return {string} The URL.
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version
 */
export function versionUrl(userId, fileId, fileVersion) {
	return generateRemoteUrl(
		`dav/versions/${encodeURIComponent(userId)}/versions/${Number(fileId)}/${encodeURIComponent(fileVersion)}`,
	)
}
