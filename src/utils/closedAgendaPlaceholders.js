// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Closed agenda items the reader may not read, as rows that hold their place.
 *
 * The read rules hide a non-public item from whoever may not read it, so the
 * reader's agenda has a gap where it sits. The server answers the order
 * numbers of those items (GET /api/meetings/{id}/closed-items) and nothing
 * else; these rows fill the gaps as "Besloten punt" with the number only.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */

const PLACEHOLDER_PREFIX = 'closed-item-'

/**
 * Whether a row holds the place of a closed item the reader cannot read.
 *
 * @param {?object} row An agenda row.
 * @return {boolean} True for a placeholder.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */
export function isClosedPlaceholder(row) {
	return row?.closedPlaceholder === true
}

/**
 * The agenda items with a placeholder for each hidden closed item.
 *
 * A number the reader's own items already hold gets no placeholder, so a
 * stale answer never doubles a row. A sub-item's placeholder keeps the
 * parent the server named (only ever one the reader can read).
 *
 * @param {Array<object>|null} items The items the reader can read.
 * @param {Array<object>|null} closedItems The server's `closedItems`.
 * @param {string} title The translated "Besloten punt".
 * @return {Array<object>} The items followed by the placeholders.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */
export function withClosedPlaceholders(items, closedItems, title) {
	const list = items || []
	const taken = new Set(list.map((item) => Number(item?.orderNumber)))
	const placeholders = []
	for (const closed of closedItems || []) {
		const number = Number(closed?.orderNumber)
		if (!Number.isFinite(number) || taken.has(number)) continue
		taken.add(number)
		placeholders.push({
			id: `${PLACEHOLDER_PREFIX}${number}`,
			orderNumber: number,
			parentItem: closed.parentItem || null,
			title,
			public: false,
			closedPlaceholder: true,
		})
	}
	return [...list, ...placeholders]
}
