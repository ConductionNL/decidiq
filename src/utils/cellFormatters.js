/**
 * App-registered cell-formatter registry for decidiq's manifest-driven
 * index pages. Passed as the `formatters` prop to `CnAppRoot` (see
 * src/App.vue), which merges it under `BUILT_IN_FORMATTERS` into the
 * `cnFormatters` registry provided to descendant `CnDataTable` /
 * `CnCellRenderer` instances. A column opts in via
 * `"formatter": "<id>"` (see `@conduction/nextcloud-vue`'s
 * CnCellRenderer.md "Column formatters").
 *
 * Each entry is `(value, row, property, options) => string|number` —
 * pure data shaping, safe against null/empty/non-parseable inputs (the
 * contract is "return the original value rather than throw").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/ux-debt-rendering/design.md#decision-2-year-formatting-via-a-new-app-registered-formatter-not-a-nc-vue-change
 */

/**
 * Render a calendar/financial-year integer as plain digits, never grouped
 * with a thousands separator. `Intl.NumberFormat`'s default `useGrouping`
 * would render `2026` as `"2,026"` — there is no column-level `format`
 * escape hatch for that in the current `@conduction/nextcloud-vue`
 * release, so this is a small additive app-side formatter instead.
 *
 * @param {number|string|null|undefined} value The raw year value.
 * @return {string} The year as plain digits, or the original value
 * (stringified) when it is not a finite number.
 */
function plainYear(value) {
	const n = Number(value)
	if (
		value === null
		|| value === undefined
		|| value === ''
		|| !Number.isFinite(n)
	) {
		return value === null || value === undefined ? '' : String(value)
	}
	return String(Math.trunc(n))
}

export default {
	plainYear,
}
