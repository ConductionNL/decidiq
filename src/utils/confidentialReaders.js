// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The named portal readers of a confidential agenda item
 * (portal-voting-record-and-confidential-papers, REQ-PSH-002). Pure, so a
 * unit test runs it; the widget is AgendaItemReadersTab.vue.
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The readers route of one item.
 *
 * @param {string} itemId The agenda item id.
 * @param {string} [personId] A reader, for the delete.
 * @return {string}
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 */
export function readersUrl(itemId, personId = '') {
	const base = `/apps/decidiq/api/agenda-items/${encodeURIComponent(itemId)}/readers`
	return generateUrl(personId ? `${base}/${encodeURIComponent(personId)}` : base)
}

/**
 * Every name a row answers to.
 *
 * @param {object} row The row.
 * @return {string[]}
 */
function idsOf(row) {
	return [row?.id, row?.uuid, row?.['@self']?.id, row?.slug].filter(
		(value) => typeof value === 'string' && value !== '',
	)
}

/**
 * The item's readers with their names, in the item's order.
 *
 * @param {object} item The agenda item.
 * @param {object[]} persons The person rows.
 * @return {Array<{id: string, name: string}>}
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 */
export function readerRows(item, persons) {
	const readers = Array.isArray(item?.authorisedPersons) ? item.authorisedPersons : []
	return readers
		.filter((id) => typeof id === 'string' && id !== '')
		.map((id) => {
			const person = (persons || []).find((row) => idsOf(row).includes(id))
			return { id, name: String(person?.name || id) }
		})
}

/**
 * The people the griffie can still add: everyone not yet a reader.
 *
 * @param {object} item The agenda item.
 * @param {object[]} persons The person rows.
 * @return {Array<{id: string, label: string}>}
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 */
export function personOptions(item, persons) {
	const readers = Array.isArray(item?.authorisedPersons) ? item.authorisedPersons : []
	return (persons || [])
		.filter((row) => idsOf(row).length > 0 && !idsOf(row).some((id) => readers.includes(id)))
		.map((row) => ({ id: idsOf(row)[0], label: String(row.name || idsOf(row)[0]) }))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * Whether readers can be added: only while the item is not public.
 *
 * @param {object} item The agenda item.
 * @return {boolean}
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 */
export function readersOpen(item) {
	return item?.public === false
}

/**
 * The add request's body; the number travels without spaces and is never kept.
 *
 * @param {string} person The person id.
 * @param {string} identityType `bsn` or `kvk`.
 * @param {string} number The number as typed.
 * @return {{person: string, identityType: string, number: string}}
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 */
export function addBody(person, identityType, number) {
	return {
		person: String(person || ''),
		identityType: identityType === 'kvk' ? 'kvk' : 'bsn',
		number: String(number || '').replace(/\s+/g, ''),
	}
}

/**
 * The id a relation field holds, whether it is stored as an id or as an object.
 *
 * @param {string|object|null|undefined} value The relation value.
 * @return {string} The id, or '' when there is none.
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public
 */
export function relationId(value) {
	if (typeof value === 'string') return value
	if (value && typeof value === 'object') return idsOf(value)[0] || ''
	return ''
}

/**
 * Who may read a closed item, as the item page says it under Kerngegevens
 * (board DcAgendapunt: "Alleen voor leden van {orgaan} en wie de griffie
 * toevoegt"). Null while the item is public, so nothing is shown.
 *
 * @param {object} item The agenda item.
 * @param {object|null} body The governance body of the item's meeting, when it could be read.
 * @return {{bodyName: string}|null} The body's name, '' when it is not known.
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public
 */
export function closedItemAudience(item, body) {
	if (!readersOpen(item)) return null
	const name = typeof body?.name === 'string' ? body.name.trim() : ''
	return { bodyName: name }
}
