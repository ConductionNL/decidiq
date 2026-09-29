// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Start an agenda from a template or copy items from an earlier meeting
// (agenda-templates-and-copy, age-04 and pla-14). A copied item keeps what
// it is and leaves behind what happened to it; lib/Service/AgendaItemCopier.php
// keeps the same field list for the meeting series.
//
// @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md

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
 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
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
 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
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
 * Number copies in order from startAt, for one meeting.
 *
 * @param {Array<object>} entries Items or template entries, in order
 * @param {string} meetingId The meeting that gets them
 * @param {number} startAt The first order number
 * @param {Array<string>} fields The fields to keep
 * @return {Array<object>} Agenda item objects
 */
function numbered(entries, meetingId, startAt, fields) {
	return entries
		.filter((e) => String(e.title || '').trim() !== '')
		.map((entry, index) => ({
			itemType: 'informational',
			...keep(entry, fields),
			meeting: meetingId,
			orderNumber: startAt + index,
		}))
}

/**
 * The agenda items a template adds to a meeting.
 *
 * @param {object} template The agenda template
 * @param {string} meetingId The meeting UUID
 * @param {number} startAt The first order number
 * @return {Array<object>} Agenda item objects
 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
 */
export function itemsFromTemplate(template, meetingId, startAt = 1) {
	return numbered(
		(template && template.items) || [],
		meetingId,
		startAt,
		TEMPLATE_FIELDS,
	)
}

/**
 * The agenda items copied from an earlier meeting: the picked ones (all
 * when none are picked), in the earlier order, after the last item.
 *
 * @param {Array<object>} items The earlier meeting's items
 * @param {string} fromMeetingId The earlier meeting
 * @param {string} meetingId The meeting that gets the copies
 * @param {number} startAt The first order number
 * @param {Array<string>} pickedIds The item ids to copy, or [] for all
 * @return {Array<object>} Agenda item objects
 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 */
export function itemsFromMeeting(
	items,
	fromMeetingId,
	meetingId,
	startAt = 1,
	pickedIds = [],
) {
	const picked = new Set(pickedIds || [])
	const own = (items || [])
		.filter((i) => refId(i.meeting) === fromMeetingId)
		.filter((i) => picked.size === 0 || picked.has(refId(i)))
		.sort((a, b) => (Number(a.orderNumber) || 0) - (Number(b.orderNumber) || 0))
	return numbered(own, meetingId, startAt, KEPT_FIELDS)
}

/**
 * A template made from a meeting's agenda, in its order.
 *
 * @param {string} name The template name
 * @param {Array<object>} items The agenda items
 * @return {object} The agenda template
 * @spec openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
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
