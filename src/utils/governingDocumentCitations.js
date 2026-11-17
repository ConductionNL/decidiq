// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Governing-document citations (governing-documents-register, REQ-GDR-005):
// the `{ document, version?, article? }` entries a decision keeps in
// `citesGoverningDocuments`, adding and removing one, the in-force date a
// governing-document page asks about, and the URLs of decidiq's own
// governing-document endpoints. Plain functions, so Vitest reads them without
// a Vue transform; the tabs and the version timeline call these.
//
// @spec openspec/changes/governing-documents-register

/**
 * A reference as a uuid string: a plain id, or an object carrying one.
 *
 * @param {string|number|object|null|undefined} value The stored reference.
 * @return {string} The id, or an empty string.
 * @spec openspec/changes/governing-documents-register
 */
export function referenceId(value) {
	if (value && typeof value === 'object') {
		return String(value.id ?? value.uuid ?? value['@self']?.id ?? '')
	}
	if (typeof value === 'string' || typeof value === 'number') {
		return String(value)
	}
	return ''
}

/**
 * The citations a decision carries, each with a document id; anything else
 * stored there is dropped.
 *
 * @param {object|null} decision The decision.
 * @return {Array<{document: string, version: string, article: string}>} The citations.
 * @spec openspec/changes/governing-documents-register
 */
export function citationsOf(decision) {
	const stored = decision?.citesGoverningDocuments
	if (!Array.isArray(stored)) return []
	return stored
		.filter((entry) => entry && typeof entry === 'object')
		.map((entry) => ({
			document: referenceId(entry.document),
			version: referenceId(entry.version),
			article: typeof entry.article === 'string' ? entry.article.trim() : '',
		}))
		.filter((entry) => entry.document !== '')
}

/**
 * A citation as it is stored: the document, and the version and article
 * only when they are given.
 *
 * @param {{document: string, version?: string, article?: string}} citation The citation.
 * @return {object} The stored shape.
 * @spec openspec/changes/governing-documents-register
 */
export function storedCitation(citation) {
	const stored = { document: referenceId(citation.document) }
	const version = referenceId(citation.version)
	const article = String(citation.article ?? '').trim()
	if (version) stored.version = version
	if (article) stored.article = article
	return stored
}

/**
 * The citations after adding one. A citation already there (the same
 * document, version and article) is not added twice.
 *
 * @param {Array<object>} citations The current citations.
 * @param {{document: string, version?: string, article?: string}} citation The new citation.
 * @return {Array<object>} The citations to store.
 * @throws {Error} When the citation names no document.
 * @spec openspec/changes/governing-documents-register
 */
export function withCitation(citations, citation) {
	const added = storedCitation(citation)
	if (!added.document) {
		throw new Error('A citation needs a governing document.')
	}
	const kept = citations.map(storedCitation)
	const key = citationKey(added)
	if (kept.some((entry) => citationKey(entry) === key)) return kept
	return [...kept, added]
}

/**
 * The citations after removing the one at an index.
 *
 * @param {Array<object>} citations The current citations.
 * @param {number} index The index to remove.
 * @return {Array<object>} The citations to store.
 * @spec openspec/changes/governing-documents-register
 */
export function withoutCitation(citations, index) {
	return citations.filter((_, i) => i !== index).map(storedCitation)
}

/**
 * The key two equal citations share.
 *
 * @param {object} citation The citation.
 * @return {string} The key.
 */
function citationKey(citation) {
	return [citation.document, citation.version || '', (citation.article || '').toLowerCase()].join('|')
}

/**
 * The URL path of the version of a document in force on a date.
 *
 * @param {string} documentId The document uuid.
 * @param {string} date The date, YYYY-MM-DD; empty for today.
 * @return {string} The app path, before generateUrl.
 * @spec openspec/changes/governing-documents-register
 */
export function inForcePath(documentId, date = '') {
	const path = `/apps/decidiq/api/governing-documents/${encodeURIComponent(documentId)}/in-force`
	return date ? `${path}?date=${encodeURIComponent(date)}` : path
}

/**
 * The URL path of the decisions citing a document.
 *
 * @param {string} documentId The document uuid.
 * @return {string} The app path, before generateUrl.
 * @spec openspec/changes/governing-documents-register
 */
export function citedByPath(documentId) {
	return `/apps/decidiq/api/governing-documents/${encodeURIComponent(documentId)}/cited-by`
}

/**
 * Whether a value is a calendar date written YYYY-MM-DD.
 *
 * @param {string} value The value.
 * @return {boolean} True for a real date.
 * @spec openspec/changes/governing-documents-register
 */
export function isCalendarDate(value) {
	if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
	const [y, m, d] = value.split('-').map(Number)
	const date = new Date(Date.UTC(y, m - 1, d))
	return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d
}

/**
 * The display name of a governing document.
 *
 * @param {object|null} document The document.
 * @return {string} Its citation title, or its id.
 * @spec openspec/changes/governing-documents-register
 */
export function documentLabel(document) {
	if (!document) return ''
	return String(document.citationTitle || document.title || document.name || referenceId(document))
}
