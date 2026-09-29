/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Formalities (hamerstukken, age-07): agenda items the chair adopts together
 * without debate. An item is one when `isFormality` is set (or it still
 * carries the older `hamerstuk` tag) and stays pending until the live
 * screen records `formalityOutcome`.
 *
 * @spec openspec/changes/agenda-formalities-hamerstukken/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
 */

/**
 * Whether an item is a formality that has not been adopted yet.
 *
 * @param {object} item The agenda item
 * @return {boolean}
 * @spec openspec/changes/agenda-formalities-hamerstukken/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
 */
export function isPendingFormality(item) {
	if (!item || item.formalityOutcome) return false
	return item.isFormality === true || (item.tags ?? []).includes('hamerstuk')
}

/**
 * The formalities not yet adopted, in agenda order.
 *
 * @param {Array<object>} items The meeting's agenda items
 * @return {Array<object>}
 * @spec openspec/changes/agenda-formalities-hamerstukken/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
 */
export function pendingFormalities(items) {
	return (items ?? [])
		.filter(isPendingFormality)
		.sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
}

/**
 * The endpoint that marks an item as a formality, before generateUrl().
 *
 * @param {string} meetingId The meeting id
 * @param {string} itemId The agenda item id
 * @return {string}
 * @spec openspec/changes/agenda-formalities-hamerstukken/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
 */
export function formalityUrl(meetingId, itemId) {
	return `/apps/decidiq/api/agendas/${encodeURIComponent(meetingId)}/items/${encodeURIComponent(itemId)}/formality`
}
