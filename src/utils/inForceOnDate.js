/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Which delegations are in force on date X" as one OpenRegister list query
 * (REQ-DMR-004): in force, valid from X or earlier, and valid until X or later
 * or open-ended. The index page merges its route query into the list fetch,
 * so writing these four keys into the address is the whole filter: no
 * per-row resolution, and a reload or a shared link lands on the same list.
 */

/** The validity-window keys this filter owns in the route query. */
export const IN_FORCE_KEYS = ['status', 'validFrom[lte]', 'validTo[gte]', 'validTo[orNull]']

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

/**
 * A date as `YYYY-MM-DD` in the user's own calendar day.
 *
 * @param {Date|string|null|undefined} value A Date, an ISO date string or nothing.
 * @return {string} The day, or '' when there is none.
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
 */
export function isoDay(value) {
	if (typeof value === 'string') {
		return ISO_DATE.test(value) ? value : ''
	}
	if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
		return ''
	}
	const pad = (n) => String(n).padStart(2, '0')
	return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
}

/**
 * The four-key query for the delegations in force on one day.
 *
 * @param {string} day The day, `YYYY-MM-DD`.
 * @return {object} The query keys.
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
 */
export function inForceOnDateQuery(day) {
	return {
		status: 'effective',
		'validFrom[lte]': day,
		'validTo[gte]': day,
		'validTo[orNull]': 'true',
	}
}

/**
 * The day a route query asks about, when it carries this filter whole.
 *
 * @param {object|null|undefined} query The route query.
 * @return {string} The day, or '' when the query is not this filter.
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
 */
export function dayFromQuery(query) {
	if (!query || typeof query !== 'object') {
		return ''
	}
	const day = isoDay(query['validFrom[lte]'])
	if (day === '' || query['validTo[gte]'] !== day || query.status !== 'effective') {
		return ''
	}
	return day
}

/**
 * The route query with the in-force filter set to one day, or taken off.
 * Every other key (search, sort, view, other filters) is kept.
 *
 * @param {object|null|undefined} query The current route query.
 * @param {string} day The day, `YYYY-MM-DD`, or '' to clear the filter.
 * @return {object} The next route query.
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
 */
export function withInForceDay(query, day) {
	const next = { ...(query || {}) }
	if (dayFromQuery(next) !== '' || day !== '') {
		for (const key of IN_FORCE_KEYS) {
			delete next[key]
		}
	}
	return day === '' ? next : { ...next, ...inForceOnDateQuery(day) }
}
