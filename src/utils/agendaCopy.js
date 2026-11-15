// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Start an agenda from a template or copy items from an earlier meeting
// (agenda-templates-and-copy, age-04 and pla-14). A copied item keeps what
// it is and leaves behind what happened to it; lib/Service/AgendaItemCopier.php
// keeps the same field list for the meeting series.
//
// @spec openspec/specs/agenda-builder/spec.md

/** The fields a copied agenda item keeps (AgendaItemCopier::KEPT_FIELDS). */
export const KEPT_FIELDS = [
	'title',
	'itemType',
	'description',
	'estimatedDuration',
	'type',
	'typeFields',
	'isFormality',
]

/** The fields a template entry holds. */
const TEMPLATE_FIELDS = KEPT_FIELDS.filter((f) => f !== 'typeFields')

/**
 * Read a reference that is a bare uuid or an expanded object.
 *
 * @param {string|object|null} ref The reference
 * @return {string} The uuid, or ''
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 */
export function refId(ref) {
	if (!ref) return ''
	if (typeof ref === 'string') return ref
	return String(ref.id || ref.uuid || '')
}

/**
 * The order number after the last item of an agenda.
 *
 * @param {Array<object>} items The agenda items
 * @return {number} The next order number
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 */
export function nextOrderNumber(items) {
	const numbers = (items || []).map((i) => Number(i.orderNumber) || 0)
	return numbers.length ? Math.max(...numbers) + 1 : 1
}

/**
 * Keep the listed fields that carry a value.
 *
 * @param {object} source The item or template entry
 * @param {Array<string>} fields The fields to keep
 * @return {object} The kept fields
 */
function keep(source, fields) {
	const out = {}
	for (const field of fields) {
		if (
			source[field] !== undefined
			&& source[field] !== null
			&& source[field] !== ''
		) {
			out[field] = source[field]
		}
	}
	return out
}

/**
 * Number the entries with a title as agenda items of the meeting, from the
 * placement's first order number on.
 *
 * @param {Array<object>} entries The entries, already cut to the kept fields
 * @param {AgendaPlacement} placement Where the items go
 * @return {Array<object>} Agenda item objects
 */
function numbered(entries, placement) {
	return entries
		.filter((e) => String(e.title || '').trim() !== '')
		.map((entry, index) => ({
			itemType: 'informational',
			...entry,
			meeting: placement.meetingId,
			orderNumber: placement.startAt + index,
		}))
}

/**
 * The agenda items a template adds to a meeting.
 *
 * @param {object} template The agenda template
 * @param {string} meetingId The meeting UUID
 * @param {number} startAt The first order number
 * @return {Array<object>} Agenda item objects
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
 */
export function itemsFromTemplate(template, meetingId, startAt = 1) {
	const entries = ((template && template.items) || []).map((entry) =>
		keep(entry, TEMPLATE_FIELDS),
	)
	return numbered(entries, { meetingId, startAt })
}

/**
 * Where copied agenda items go: the meeting and the first order number.
 *
 * @typedef {object} AgendaPlacement
 * @property {string} meetingId The meeting that gets the items
 * @property {number} startAt The first order number
 */

/**
 * A copy of agenda items from an earlier meeting into this one.
 *
 * @typedef {object} AgendaCopyRequest
 * @property {string} fromMeetingId The earlier meeting
 * @property {string} meetingId The meeting that gets the copies
 * @property {number} [startAt] The first order number (1 when left out)
 * @property {Array<string>} [pickedIds] The item ids to copy, or [] for all
 */

/**
 * The agenda items copied from an earlier meeting: the picked ones (all
 * when none are picked), in the earlier order, after the last item.
 *
 * @param {Array<object>} items The earlier meeting's items
 * @param {AgendaCopyRequest} request What to copy where
 * @return {Array<object>} Agenda item objects
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 */
export function itemsFromMeeting(items, request) {
	const { fromMeetingId, meetingId, startAt = 1, pickedIds = [] } = request
	const picked = new Set(pickedIds || [])
	const own = (items || [])
		.filter((i) => refId(i.meeting) === fromMeetingId)
		.filter((i) => picked.size === 0 || picked.has(refId(i)))
		.sort((a, b) => (Number(a.orderNumber) || 0) - (Number(b.orderNumber) || 0))
	const entries = own.map((entry) => keep(entry, KEPT_FIELDS))
	return numbered(entries, { meetingId, startAt })
}

/**
 * A template made from a meeting's agenda, in its order.
 *
 * @param {string} name The template name
 * @param {Array<object>} items The agenda items
 * @return {object} The agenda template
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
 */
export function templateFromItems(name, items) {
	const ordered = [...(items || [])]
		.filter((i) => !i.parentItem)
		.sort((a, b) => (Number(a.orderNumber) || 0) - (Number(b.orderNumber) || 0))
	return {
		name: String(name || '').trim(),
		items: ordered
			.filter((i) => String(i.title || '').trim() !== '')
			.map((i) => keep(i, TEMPLATE_FIELDS)),
	}
}
