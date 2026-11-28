// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Who opened the papers of a confidentiality restriction
 * (embargo-geheimhouding, REQ-EMB-007). Pure, so a unit test runs it; the
 * widget is RestrictedPaperViewsTab.vue. The server decides who may read the
 * list (the imposing body's staff); this file only shapes what it answers.
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The views route of one restriction.
 *
 * @param {string} restrictionId The confidentiality restriction id.
 * @return {string} The URL.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
 */
export function viewsUrl(restrictionId) {
	return generateUrl(
		`/apps/decidiq/api/confidentiality/restrictions/${encodeURIComponent(String(restrictionId))}/views`,
	)
}

/**
 * The openings as table rows, newest first, whatever order the server sent.
 *
 * A row names the reader, the time, and the paper: the document record when
 * there is one, else the file id. An entry without a reader or a time is not
 * an opening and is left out.
 *
 * @param {Array<object>} views The `views` list the endpoint answers.
 * @return {Array<{key: string, reader: string, at: string, paper: string, agendaItem: string}>} The rows.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
 */
export function viewRows(views) {
	if (!Array.isArray(views)) {
		return []
	}

	const rows = views
		.filter((view) => typeof view?.actor === 'string' && view.actor !== '' && typeof view?.at === 'string' && view.at !== '')
		.map((view, index) => {
			const fileId = Number(view.fileId) || 0
			const document = typeof view.document === 'string' ? view.document : ''
			return {
				key: `${view.at}-${view.actor}-${fileId}-${index}`,
				reader: view.actor,
				at: view.at,
				paper: document !== '' ? document : (fileId > 0 ? `#${fileId}` : ''),
				agendaItem: typeof view.agendaItem === 'string' ? view.agendaItem : '',
			}
		})

	return rows.sort((one, two) => (one.at < two.at ? 1 : one.at > two.at ? -1 : 0))
}

/**
 * The time of an opening as the reader's locale writes it.
 *
 * @param {string} at The ISO 8601 time.
 * @param {string} [locale] The locale, for a test.
 * @return {string} The time, or the raw value when it is no date.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
 */
export function viewTime(at, locale = undefined) {
	const date = new Date(at)
	if (Number.isNaN(date.getTime())) {
		return String(at ?? '')
	}

	return date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * What to say when the list cannot be read.
 *
 * The server's own message wins (a 403 names who may see the list); without
 * one the fallback is used.
 *
 * @param {object} error The axios error.
 * @param {string} fallback The text when the server said nothing.
 * @return {string} The message.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
 */
export function viewsRefusal(error, fallback) {
	const message = error?.response?.data?.message
	return typeof message === 'string' && message !== '' ? message : fallback
}
