// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The meeting calendar by audience and body (planning-activity-calendar-by-audience).
 *
 * The calendar asks the server for the visible grid only (six weeks around the
 * month), narrowed by body and by the meeting types that carry the chosen
 * audience, instead of the first 500 meetings of all time.
 *
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
 */

/**
 * The audiences a kind of meeting can declare, in display order.
 */
export const AUDIENCES = [
	'council',
	'executive',
	'joint-arrangement',
	'residents',
	'staff',
]

/**
 * The option that shows meetings whose type declares no audience.
 */
export const NO_AUDIENCE = 'none'

/**
 * Most meetings one grid asks for. Six weeks of meetings, not all of time.
 */
export const GRID_LIMIT = 1000

/**
 * Milliseconds in one day.
 */
const DAY_MS = 24 * 60 * 60 * 1000

/**
 * The first and last moment of the 42-cell, Monday-first grid of a month.
 *
 * @param {number} year The year.
 * @param {number} month The month, 0 based.
 * @return {{from: string, to: string}} ISO timestamps.
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
 */
export function gridRange(year, month) {
	const first = new Date(year, month, 1)
	const lead = (first.getDay() + 6) % 7
	const start = new Date(first.getTime() - lead * DAY_MS)
	const end = new Date(start.getTime() + 42 * DAY_MS - 1)
	return { from: start.toISOString(), to: end.toISOString() }
}

/**
 * The ids of the meeting types whose audiences hold the given one.
 *
 * @param {Array<object>} types The meeting types.
 * @param {string} audience The audience.
 * @return {Array<string>} Type ids.
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
 */
export function typesFor(types, audience) {
	return (types || [])
		.filter(
			(type) =>
				Array.isArray(type.audiences) && type.audiences.includes(audience),
		)
		.map((type) => type.id ?? type['@self']?.id)
		.filter(Boolean)
}

/**
 * The query parameters for one grid.
 *
 * @param {object} options The view.
 * @param {number} options.year The year.
 * @param {number} options.month The month, 0 based.
 * @param {string} [options.audience] An audience, NO_AUDIENCE, or empty for all.
 * @param {string} [options.body] A governance body id, or empty for all.
 * @param {Array<object>} [options.types] The meeting types.
 * @return {object|null} Params for fetchCollection, or null when the audience matches no type.
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
 */
export function calendarParams({
	year,
	month,
	audience = '',
	body = '',
	types = [],
}) {
	const { from, to } = gridRange(year, month)
	const params = {
		'scheduledDate[gte]': from,
		'scheduledDate[lte]': to,
		_limit: GRID_LIMIT,
	}
	if (body) {
		params.governanceBody = body
	}
	if (audience && audience !== NO_AUDIENCE) {
		const ids = typesFor(types, audience)
		if (ids.length === 0) {
			return null
		}
		params.type = ids
	}
	return params
}

/**
 * Narrow a fetched grid to the meetings whose type declares no audience,
 * when that option is chosen. The grid is complete (bounded by GRID_LIMIT),
 * so this is not a filter over a partial page.
 *
 * @param {Array<object>} meetings The grid's meetings.
 * @param {string} audience The chosen audience.
 * @param {Array<object>} types The meeting types.
 * @return {Array<object>} The meetings to show.
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
 */
export function withoutAudience(meetings, audience, types) {
	if (audience !== NO_AUDIENCE) {
		return meetings
	}
	const declared = new Set(
		(types || [])
			.filter(
				(type) => Array.isArray(type.audiences) && type.audiences.length > 0,
			)
			.map((type) => type.id ?? type['@self']?.id),
	)
	return meetings.filter((meeting) => !meeting.type || !declared.has(meeting.type))
}
