// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for a body's step-down schedule (rooster van aftreden) and the
 * term dates of a membership (bodies-term-of-office-and-step-down, bod-07).
 *
 * The schedule is computed on read from the body's memberships and position
 * holds and is never stored (REQ-CTM-008, REQ-TOS-002). Kept out of the
 * widget's .vue so Vitest can run it without an SFC transform.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md
 */

import { startOfDay } from './bodyMembership.js'

/** The window filter values, the first being the default. */
export const STEP_DOWN_WINDOWS = ['next-12-months', 'this-term', 'all']

/** Herbenoembaar values a schedule line can carry. */
export const REAPPOINTABLE = {
	yes: 'yes',
	no: 'no',
	lastTerm: 'last-term',
}

/**
 * Epoch ms of a date-like value, NaN when empty or unreadable.
 *
 * @param {Date|string|number|null|undefined} value The value.
 * @return {number} Epoch ms or NaN.
 */
function timeOf(value) {
	if (value === null || value === undefined || value === '') return NaN
	return new Date(value).getTime()
}

/**
 * The start of the local day of a moment.
 *
 * @param {Date|string|number} now The moment.
 * @return {number} Epoch ms of that day's midnight.
 */
function startOfLocalDay(now) {
	const date = new Date(now)
	return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

/**
 * The default "Termijn begint" of a new member: the start of the body's
 * current term, or today when the body has none (REQ-TOS-001).
 *
 * @param {object|null} body The governance body.
 * @param {Date} [now] Today.
 * @return {Date} The date the form starts on.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-001-a-members-term-is-entered-when-the-member-is-added
 */
export function defaultTermStart(body, now = new Date()) {
	const start = timeOf(body?.termStart)
	return Number.isNaN(start) ? new Date(now) : new Date(start)
}

/**
 * The last moment a window reaches, or null when it is open-ended.
 *
 * "next-12-months" ends twelve months from today; "this-term" ends with the
 * body's current term (open-ended when the body has no term end); "all" is
 * open-ended. An unknown window falls back to the default.
 *
 * @param {string} window One of STEP_DOWN_WINDOWS.
 * @param {object|null} body The governance body.
 * @param {Date} [now] Today.
 * @return {number|null} Epoch ms, or null for no upper bound.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
 */
export function windowUntil(window, body, now = new Date()) {
	if (window === 'all') return null
	if (window === 'this-term') {
		const end = timeOf(body?.termEnd)
		return Number.isNaN(end) ? null : end
	}
	const until = new Date(now)
	until.setMonth(until.getMonth() + 12)
	return until.getTime()
}

/**
 * Herbenoembaar of a position hold: "no" when the position type is not
 * reappointable (or the hold itself says it may not be), "last-term" when
 * the term number reached the type's maximum consecutive terms, "yes"
 * otherwise (REQ-TOS-003).
 *
 * @param {object} hold The PositionHold.
 * @param {object|null} positionType Its PositionType.
 * @return {string} One of REAPPOINTABLE.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-003-herbenoembaar-follows-the-positions-rules
 */
export function holdReappointable(hold, positionType) {
	if (positionType?.reappointable === false || hold?.reappointable === false) {
		return REAPPOINTABLE.no
	}
	const max = Number(positionType?.maxConsecutiveTerms)
	const term = Number(hold?.termNumber ?? 1)
	if (Number.isInteger(max) && max > 0 && term >= max) {
		return REAPPOINTABLE.lastTerm
	}
	return REAPPOINTABLE.yes
}

/**
 * The schedule line of a membership.
 *
 * @param {object} membership The Membership.
 * @param {Object<string, object>} personsById People by id.
 * @return {object} The line.
 */
function membershipLine(membership, personsById) {
	return {
		id: `membership:${membership.id}`,
		kind: 'membership',
		objectId: membership.id,
		person: membership.person || '',
		name: personsById[membership.person]?.name || '',
		position: '',
		termNumber: null,
		endDate: membership.endDate,
		reappointable: REAPPOINTABLE.yes,
	}
}

/**
 * The schedule line of a position hold, its holder found through the
 * membership it is held from.
 *
 * @param {object} hold The PositionHold.
 * @param {object} lookups Memberships, people and position types by id.
 * @param {object} lookups.membershipsById Memberships by id.
 * @param {object} lookups.personsById People by id.
 * @param {object} lookups.positionTypesById Position types by id.
 * @return {object} The line.
 */
function holdLine(hold, { membershipsById, personsById, positionTypesById }) {
	const membership = membershipsById[hold.membership] || null
	const positionType = positionTypesById[hold.position] || null
	const person = membership?.person || ''
	return {
		id: `position-hold:${hold.id}`,
		kind: 'position-hold',
		objectId: hold.id,
		person,
		name: personsById[person]?.name || '',
		position: positionType?.name || '',
		termNumber: hold.termNumber ?? 1,
		endDate: hold.endDate,
		reappointable: holdReappointable(hold, positionType),
	}
}

/**
 * Every membership and position hold of a body whose end date falls from
 * today up to the window's end, soonest first (REQ-TOS-002). A line without
 * an end date has no place on the schedule. Equal end dates order by name.
 * Writes nothing.
 *
 * @param {object} sources The body's objects.
 * @param {Array<object>} [sources.memberships] The body's memberships.
 * @param {Array<object>} [sources.holds] The body's position holds.
 * @param {Object<string, object>} [sources.personsById] People by id.
 * @param {Object<string, object>} [sources.positionTypesById] Position types by id.
 * @param {object} [options] The window.
 * @param {number|null} [options.until] Last moment included, null for no bound.
 * @param {Date} [options.now] Today.
 * @return {Array<object>} The schedule lines.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
 */
export function stepDownLines(
	{ memberships = [], holds = [], personsById = {}, positionTypesById = {} },
	{ until = null, now = new Date() } = {},
) {
	const membershipsById = Object.fromEntries(
		(memberships || []).map((membership) => [membership.id, membership]),
	)
	const lookups = { membershipsById, personsById, positionTypesById }
	const from = startOfLocalDay(now)
	const lines = [
		...(memberships || []).map((membership) =>
			membershipLine(membership, personsById),
		),
		...(holds || []).map((hold) => holdLine(hold, lookups)),
	]
	return lines
		.map((line) => ({ ...line, time: timeOf(line.endDate) }))
		.filter(
			(line) =>
				!Number.isNaN(line.time)
				&& line.time >= from
				&& (until === null || line.time <= until),
		)
		.sort((a, b) => a.time - b.time || a.name.localeCompare(b.name))
}

/**
 * The term dates of a membership as the payload's ISO values, an empty end
 * meaning no end. Refuses an end before the start.
 *
 * @param {string} startIso The start, ISO ('' for none).
 * @param {string} endIso The end, ISO ('' for none).
 * @return {{startDate: string, endDate: string, valid: boolean}} The term.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-001-a-members-term-is-entered-when-the-member-is-added
 */
export function membershipTerm(startIso, endIso) {
	const start = timeOf(startIso)
	const end = timeOf(endIso)
	const valid = Number.isNaN(start) || Number.isNaN(end) || end >= start
	return { startDate: startIso || '', endDate: endIso || '', valid }
}

/**
 * Everything the step-down schedule of one body reads: the body, its
 * memberships, position holds and position types (by id), and the people of
 * the memberships (by id; an unreadable one is left out). Reads only.
 *
 * @param {string} bodyId The governance body id.
 * @param {(type: string) => object} storeFor The relation store of a type (ensureRelationType).
 * @return {Promise<{body: ?object, memberships: Array<object>, holds: Array<object>, positionTypesById: object, personsById: object}>}
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
 */
export async function loadStepDownData(bodyId, storeFor) {
	const scope = (limit) => ({ governanceBody: bodyId, _limit: limit })
	const [body, memberships, holds, positionTypes] = await Promise.all([
		storeFor('governance-body').fetchObject('governance-body', bodyId),
		storeFor('membership').fetchCollection('membership', scope(500)),
		storeFor('position-hold').fetchCollection('position-hold', scope(500)),
		storeFor('position-type').fetchCollection('position-type', scope(200)),
	])
	const people = storeFor('person')
	const ids = [...new Set((memberships || []).map((m) => m.person).filter(Boolean))]
	const found = await Promise.all(ids.map((id) => people.fetchObject('person', id).catch(() => null)))
	return {
		body: body || null,
		memberships: memberships || [],
		holds: holds || [],
		positionTypesById: Object.fromEntries((positionTypes || []).map((type) => [type.id, type])),
		personsById: Object.fromEntries(ids.map((id, i) => [id, found[i]]).filter(([, person]) => person)),
	}
}

/**
 * The term a dialog's two date pickers give: each picked date as its ISO day,
 * refused when the end lies before the start.
 *
 * @param {?Date} startDate The picked start.
 * @param {?Date} endDate The picked end.
 * @return {{startDate: string, endDate: string, valid: boolean}} The term.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-001-a-members-term-is-entered-when-the-member-is-added
 */
export function pickedTerm(startDate, endDate) {
	return membershipTerm(startOfDay(startDate), startOfDay(endDate))
}

/**
 * A date as the user's short date.
 *
 * @param {?string} value An ISO date or date-time.
 * @return {string} The date, or '' when there is none or it does not parse.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
 */
export function shortDate(value) {
	const time = value ? new Date(value).getTime() : NaN
	return Number.isNaN(time) ? '' : new Date(time).toLocaleDateString()
}
