// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the organisations that take part in a shared body
 * (bodies-shared-body-participations, matrix row bod-13): the payload the
 * participation dialog writes, ending a participation, and the seats each
 * organisation has filled with members sitting on its behalf.
 *
 * A participation is stored as a `representation` GovernanceBodyComposition
 * (configurable-types-domain-model task 3.5): the shared body is the
 * composite, the participating organisation the component, which sends
 * `seats` representatives. BodyParticipation is superseded.
 *
 * @spec openspec/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
 */


/**
 * The schema a participation is stored in.
 *
 * @type {string}
 */
export const PARTICIPATION_SCHEMA = 'governance-body-composition'

/**
 * The composition type of a participation: the organisation sends representatives.
 *
 * @type {string}
 */
export const PARTICIPATION_TYPE = 'representation'

/**
 * The collection query for the participations of one shared body.
 *
 * @param {string|number} sharedBodyId The shared body's id.
 *
 * @return {object} The query: its representation compositions.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
 */
export function participationQuery(sharedBodyId) {
	return {
		composite: String(sharedBodyId),
		compositionType: PARTICIPATION_TYPE,
		_limit: 200,
	}
}

/**
 * Whether a body is a shared body (a joint arrangement of organisations).
 *
 * @param {object|null} body A GovernanceBody object.
 *
 * @return {boolean} True for bodyType shared-body.
 *
 * @spec openspec/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function isSharedBody(body) {
	return body?.bodyType === 'shared-body'
}

/**
 * Whether a date-time lies after the given moment (an empty value never does).
 *
 * @param {string|null|undefined} value An ISO date-time.
 * @param {Date} now The moment to compare with.
 *
 * @return {boolean} True when the value is set and later than now.
 */
function isAfter(value, now) {
	if (!value) return false
	const time = new Date(value).getTime()
	return !Number.isNaN(time) && time > now.getTime()
}

/**
 * A participation is active while it has no withdrawal date, or the
 * withdrawal date still lies ahead (the schema's active window).
 *
 * @param {object} participation A BodyParticipation object.
 * @param {Date} [now] The moment to evaluate at.
 *
 * @return {boolean} True while the organisation takes part.
 *
 * @spec openspec/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function isActiveParticipation(participation, now = new Date()) {
	return !participation?.exitDate || isAfter(participation.exitDate, now)
}

/**
 * A membership fills a seat while it has no end date, or the end date still
 * lies ahead.
 *
 * @param {object} membership A Membership object.
 * @param {Date} now The moment to evaluate at.
 *
 * @return {boolean} True while the member sits.
 */
function fillsSeat(membership, now) {
	return !membership?.endDate || isAfter(membership.endDate, now)
}

/**
 * A picked or stored date as the calendar date the composition stores
 * (YYYY-MM-DD). A Date counts in the user's time zone; a string keeps the
 * day it names, so a stored date-time from before the move keeps its day.
 *
 * @param {string|Date|null|undefined} value The date.
 *
 * @return {string} The date, or '' when there is none or it is not a date.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
 */
export function calendarDate(value) {
	if (!value) return ''
	if (value instanceof Date) {
		return Number.isNaN(value.getTime()) ? '' : ymd(value)
	}
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value))
	if (!match) return ''
	const [year, month, day] = match.slice(1).map(Number)
	const date = new Date(year, month - 1, day)
	return date.getMonth() === month - 1 && date.getDate() === day ? ymd(date) : ''
}

/**
 * A Date's local calendar date as YYYY-MM-DD.
 *
 * @param {Date} date The date.
 *
 * @return {string} The calendar date.
 */
function ymd(date) {
	const pad = (number) => String(number).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * A number typed in a form field, or null when the field was left empty.
 *
 * @param {string|number|null|undefined} value The typed value.
 *
 * @return {number|null} The number, or null.
 */
function numberOrNull(value) {
	if (value === '' || value === null || value === undefined) return null
	const number = Number(value)
	return Number.isFinite(number) ? number : null
}

/**
 * The participation payload the participation dialog saves: a representation
 * GovernanceBodyComposition. Fields the secretary left empty are left out, so
 * an empty weight is not stored as 0.
 *
 * @param {object} fields The dialog fields.
 * @param {string} fields.composite The shared body's id.
 * @param {string} fields.component The participating organisation's id.
 * @param {string|number} [fields.seats] Number of seats.
 * @param {string|number} [fields.votingWeight] Voting weight agreed in the arrangement.
 * @param {string|Date} [fields.accessionDate] Date of accession.
 * @param {string|Date} [fields.exitDate] Date of withdrawal.
 * @param {string} [fields.label] Optional label.
 * @param {string} [fields.id] The id when editing.
 *
 * @return {object} The payload.
 *
 * @spec openspec/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function buildParticipationPayload(fields) {
	const payload = {
		composite: fields.composite,
		component: fields.component,
		compositionType: PARTICIPATION_TYPE,
	}
	const optional = optionalParticipationFields(fields)
	for (const [key, value] of Object.entries(optional)) {
		if (value !== null && value !== '') payload[key] = value
	}
	return payload
}

/**
 * The optional payload fields in payload order, each normalised to the value
 * the schema stores, or to null or '' when the secretary left it empty.
 *
 * @param {object} fields The form fields (see buildParticipationPayload).
 *
 * @return {object} The normalised optional fields.
 */
function optionalParticipationFields(fields) {
	const seatCount = numberOrNull(fields.seats)
	return {
		seats: seatCount === null ? null : Math.trunc(seatCount),
		votingWeight: numberOrNull(fields.votingWeight),
		accessionDate: calendarDate(fields.accessionDate),
		exitDate: calendarDate(fields.exitDate),
		label: String(fields.label || '').trim(),
		id: fields.id || null,
	}
}

/**
 * The payload that ends a participation today: the stored fields with the
 * withdrawal date set to the start of today. The participation stays on
 * record (history), it is not deleted.
 *
 * @param {object} participation The stored participation composition.
 * @param {Date} [now] Today.
 *
 * @return {object} The payload.
 *
 * @spec openspec/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function endParticipationPayload(participation, now = new Date()) {
	return buildParticipationPayload({
		id: participation.id,
		composite: participation.composite,
		component: participation.component,
		seats: participation.seats,
		votingWeight: participation.votingWeight,
		accessionDate: participation.accessionDate || '',
		exitDate: now,
		label: participation.label,
	})
}

/**
 * The display name of a GovernanceBody, falling back to its id.
 *
 * @param {object} bodiesById Bodies keyed by id.
 * @param {string} id The body's id.
 *
 * @return {string} The name.
 */
function bodyName(bodiesById, id) {
	const body = bodiesById?.[id]
	return body?.name || body?.title || String(id || '')
}

/**
 * One row per participation for the widget: the organisation, its seats and
 * how many of them are filled by members sitting on its behalf. Active
 * participations come first (by name), withdrawn ones after them.
 *
 * @param {Array<object>} participations The shared body's BodyParticipations.
 * @param {Array<object>} memberships The shared body's Memberships.
 * @param {object} bodiesById Participating bodies keyed by id.
 * @param {Date} [now] The moment to evaluate at.
 *
 * @return {Array<object>} The rows.
 *
 * @spec openspec/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function participationRows(
	participations,
	memberships,
	bodiesById,
	now = new Date(),
) {
	const filledBy = seatsFilledBy(memberships, now)
	return (participations || [])
		.map((participation) => ({
			...participationFields(participation, bodiesById),
			filled: filledBy[participation.component] || 0,
			active: isActiveParticipation(participation, now),
			source: participation,
		}))
		.sort(activeFirstByName)
}

/**
 * How many seats each participant has filled: the memberships sitting on its
 * behalf that still fill a seat at the moment, keyed by participant id.
 *
 * @param {Array<object>} memberships The shared body's Memberships.
 * @param {Date} now The moment to evaluate at.
 *
 * @return {object} Filled seat counts keyed by participant id.
 */
function seatsFilledBy(memberships, now) {
	const filledBy = {}
	for (const membership of memberships || []) {
		if (!fillsSeatOnBehalf(membership, now)) continue
		filledBy[membership.onBehalfOf] = (filledBy[membership.onBehalfOf] || 0) + 1
	}
	return filledBy
}

/**
 * Whether a membership sits on behalf of a participant and still fills its
 * seat at the moment.
 *
 * @param {object} membership The Membership.
 * @param {Date} now The moment to evaluate at.
 *
 * @return {boolean} True when it fills one of the participant's seats.
 */
function fillsSeatOnBehalf(membership, now) {
	return Boolean(membership?.onBehalfOf) && fillsSeat(membership, now)
}

/**
 * The stored fields of one participation as the widget row shows them.
 *
 * @param {object} participation The BodyParticipation.
 * @param {object} bodiesById Participating bodies keyed by id.
 *
 * @return {object} The row's own fields.
 */
function participationFields(participation, bodiesById) {
	return {
		id: participation.id,
		participant: participation.component,
		name: bodyName(bodiesById, participation.component),
		seats: numberOrNull(participation.seats),
		votingWeight: numberOrNull(participation.votingWeight),
		accessionDate: participation.accessionDate || '',
		exitDate: participation.exitDate || '',
	}
}

/**
 * Sort order of the widget rows: active participations first, then by name.
 *
 * @param {object} a A row.
 * @param {object} b Another row.
 *
 * @return {number} The comparison.
 */
function activeFirstByName(a, b) {
	return Number(b.active) - Number(a.active) || a.name.localeCompare(b.name)
}

/**
 * The organisations a new member of a shared body can sit on behalf of: the
 * active participants, by name.
 *
 * @param {Array<object>} participations The shared body's BodyParticipations.
 * @param {object} bodiesById Participating bodies keyed by id.
 * @param {Date} [now] The moment to evaluate at.
 *
 * @return {Array<{id: string, label: string}>} Select options.
 *
 * @spec openspec/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function onBehalfOfOptions(participations, bodiesById, now = new Date()) {
	const seen = new Set()
	const options = []
	const active = (participations || []).filter((participation) =>
		isActiveParticipation(participation, now),
	)
	for (const participation of active) {
		const id = participation?.component
		if (!id || seen.has(id)) continue
		seen.add(id)
		options.push({ id, label: bodyName(bodiesById, id) })
	}
	return options.sort((a, b) => a.label.localeCompare(b.label))
}
