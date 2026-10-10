// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Incoming documents (agenda-incoming-documents-list, age-13). Since
 * documents-as-agenda-items an incoming letter is an agenda item whose type
 * is an incoming document type: a type flagged `incomingDocument`, or, for a
 * type stored before that flag existed, one of the two seeded kinds. These
 * helpers only arrange what was read; the server owns the objects.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */

/**
 * The seeded incoming document kinds, recognised by slug when a type carries
 * no `incomingDocument` value of its own.
 */
export const INCOMING_TYPE_SLUGS = [
	'type-ingekomen-stuk',
	'type-raadsinformatiebrief',
]

/**
 * The id a reference holds: a uuid string or an expanded object.
 *
 * @param {string|object|null|undefined} value The stored reference.
 * @return {string|null} The id, or null.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */
export function referenceId(value) {
	const id = value && typeof value === 'object' ? (value.id ?? value.uuid) : value
	return typeof id === 'string' && id !== '' ? id : null
}

/**
 * Whether an agenda item type is an incoming document type.
 *
 * @param {object|null|undefined} type The agenda item type.
 * @return {boolean} True for an incoming document type.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */
export function isIncomingType(type) {
	if (!type || typeof type !== 'object') return false
	if (typeof type.incomingDocument === 'boolean') return type.incomingDocument
	const slug = type.slug ?? type['@self']?.slug
	return INCOMING_TYPE_SLUGS.includes(slug)
}

/**
 * The incoming document types among the agenda item types, by id.
 *
 * @param {Array<object>} types The agenda item types.
 * @return {Map<string, object>} Incoming types keyed by id.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */
export function incomingTypes(types) {
	const byId = new Map()
	for (const type of Array.isArray(types) ? types : []) {
		const id = referenceId(type)
		if (id !== null && isIncomingType(type)) byId.set(id, type)
	}
	return byId
}

/**
 * The agenda items that are incoming documents.
 *
 * @param {Array<object>} items The agenda items.
 * @param {Map<string, object>} types Incoming types keyed by id.
 * @return {Array<object>} The incoming document items.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */
export function incomingItems(items, types) {
	return (Array.isArray(items) ? items : []).filter(
		(item) => item && types.has(referenceId(item.type)),
	)
}

/**
 * The incoming documents that wait for a meeting.
 *
 * @param {Array<object>} items The agenda items.
 * @param {Map<string, object>} types Incoming types keyed by id.
 * @return {Array<object>} Incoming document items without a meeting.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#scenario-putting-a-letter-on-the-agenda
 */
export function waitingItems(items, types) {
	return incomingItems(items, types).filter(
		(item) => referenceId(item.meeting) === null,
	)
}

/**
 * Table rows for incoming document items.
 *
 * @param {Array<object>} items The incoming document items.
 * @param {Map<string, object>} types Incoming types keyed by id.
 * @return {Array<object>} Rows with id, typeLabel, title and lifecycle.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */
export function incomingRows(items, types) {
	return items.map((item) => ({
		id: referenceId(item),
		typeLabel: types.get(referenceId(item.type))?.name || '',
		title: item.title || '',
		lifecycle: item.lifecycle || '',
	}))
}

/**
 * What an incoming document is saved with when it is put on a meeting's
 * agenda: its own fields, the meeting, and the first free position after the
 * meeting's current items. Read-only keys (`id`, `uuid`, `@self`, `_...`) are
 * left out; the caller saves it under the item's id.
 *
 * @param {object} item The incoming document item.
 * @param {string} meetingId The meeting it goes on.
 * @param {Array<object>} meetingItems The meeting's current agenda items.
 * @return {object} The agenda item payload.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#scenario-putting-a-letter-on-the-agenda
 */
export function putOnAgendaPayload(item, meetingId, meetingItems) {
	const fields = Object.fromEntries(
		Object.entries(item || {}).filter(
			([key]) =>
				key !== 'id'
				&& key !== 'uuid'
				&& !key.startsWith('@')
				&& !key.startsWith('_'),
		),
	)
	const last = (Array.isArray(meetingItems) ? meetingItems : []).reduce(
		(max, other) =>
			Number.isInteger(other?.orderNumber) && other.orderNumber > max
				? other.orderNumber
				: max,
		0,
	)
	return { ...fields, meeting: meetingId, orderNumber: last + 1 }
}
