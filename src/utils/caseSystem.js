// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The case system on the agenda item, the minutes page and the meeting page
// (platform-case-system-document-exchange, matrix rows plt-23 and plt-24).
// Pure helpers, so the screens stay thin and vitest can cover the rules.

import { generateUrl } from '@nextcloud/router'

/** Minutes states from which the meeting file can be sent. */
export const SENDABLE_MINUTES = ['approved', 'signed', 'published']

/**
 * A decidiq API URL.
 *
 * @param {string} path Path under /apps/decidiq/api.
 * @return {string} The URL.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */
export function apiUrl(path) {
	return generateUrl(`/apps/decidiq/api${path}`)
}

/**
 * The OpenRegister list URL of a meeting's exchange records.
 *
 * @param {string} meetingId The meeting.
 * @return {string} The URL.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 */
export function recordsUrl(meetingId) {
	return generateUrl(
		`/apps/openregister/api/objects/decidiq/case-exchange-record?meeting=${encodeURIComponent(meetingId)}&_limit=100`,
	)
}

/**
 * How many lines of a record are in each status.
 *
 * @param {object} record A CaseExchangeRecord.
 * @return {{pending: number, sent: number, failed: number}} The counts.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 */
export function lineCounts(record) {
	const counts = { pending: 0, sent: 0, failed: 0 }
	for (const line of record?.lines || []) {
		if (line && line.status in counts) counts[line.status]++
	}
	return counts
}

/**
 * Whether "Send again" applies: a send with at least one failed line.
 *
 * @param {object} record A CaseExchangeRecord.
 * @return {boolean} True when some line failed.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 */
export function canSendAgain(record) {
	return record?.direction === 'send' && lineCounts(record).failed > 0
}

/**
 * Whether the minutes allow sending the meeting file.
 *
 * @param {object} minutes The minutes.
 * @return {boolean} True from approval on.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 */
export function canSendMeetingFile(minutes) {
	return SENDABLE_MINUTES.includes(minutes?.lifecycle)
}

/**
 * The case as one line of text: title and number.
 *
 * @param {object|null} caseReference The item's caseReference.
 * @return {string} Such as "Omgevingsvisie 2040 (Z-2026-00412)", or ''.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
 */
export function caseLabel(caseReference) {
	if (!caseReference?.url) return ''
	const number = caseReference.identification || ''
	const title = caseReference.title || ''
	if (title && number) return `${title} (${number})`
	return title || number || caseReference.url
}

/**
 * The ticked documents that still need fetching.
 *
 * @param {Array<object>} documents The case's documents, each with url and fetched.
 * @param {Array<string>} chosen The ticked urls.
 * @return {Array<string>} The urls to fetch.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
 */
export function toFetch(documents, chosen) {
	const fetched = new Set(
		(documents || []).filter((d) => d.fetched).map((d) => d.url),
	)
	return [...new Set(chosen || [])].filter((url) => url && !fetched.has(url))
}

/**
 * The records newest first.
 *
 * @param {Array<object>} records CaseExchangeRecords.
 * @return {Array<object>} Sorted copy.
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 */
export function newestFirst(records) {
	return [...(records || [])].sort((a, b) =>
		String(b.requestedAt || '').localeCompare(String(a.requestedAt || '')),
	)
}
