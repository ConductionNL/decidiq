// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A section's widget with the cell budget switched off.
 *
 * An object list fits its visible rows to the host grid cell, measured from
 * where its table starts to the cell's bottom. That is right for a list that
 * owns its cell and wrong for one stacked below another section in the same
 * cell: the budget floors to one row and the rest of the rows do not render.
 * Every widget in a sections panel shares the cell, so each renders every
 * fetched row and the panel scrolls. `limit` still caps the fetch.
 *
 * A widget that already says `fit` keeps what it says. The definition is
 * copied, never changed: the same one is read again on every render.
 *
 * @param {object} widget A section's widget definition.
 * @return {object} The same definition with `content.fit` defaulted to false.
 * @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md#requirement-req-sdp-004-the-blocks-of-a-decision-sit-behind-five-tabs-and-more
 */
export function unfittedWidget(widget) {
	const content = widget.content || {}
	if (Object.hasOwn(content, 'fit')) {
		return widget
	}
	return { ...widget, content: { ...content, fit: false } }
}
