// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the organisations that take part in a shared body
 * (bodies-shared-body-participations, matrix row bod-13): the payload the
 * participation dialog writes, ending a participation, and the seats each
 * organisation has filled with members sitting on its behalf.
 *
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */

import { startOfDay } from './bodyMembership.js'

/**
 * Whether a body is a shared body (a joint arrangement of organisations).
 *
 * @param {object|null} body A GovernanceBody object.
 *
 * @return {boolean} True for bodyType shared-body.
 *
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
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
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
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
 * The BodyParticipation payload the participation dialog saves. Fields the
 * secretary left empty are left out, so an empty weight is not stored as 0.
 *
 * @param {object} fields The dialog fields.
 * @param {string} fields.sharedBody The shared body's id.
 * @param {string} fields.participant The participating organisation's id.
 * @param {string|number} [fields.seats] Number of seats.
 * @param {string|number} [fields.votingWeight] Voting weight agreed in the arrangement.
 * @param {string|Date} [fields.accessionDate] Date of accession.
 * @param {string|Date} [fields.exitDate] Date of withdrawal.
 * @param {string} [fields.label] Optional label.
 * @param {string} [fields.id] The id when editing.
 *
 * @return {object} The payload.
 *
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function buildParticipationPayload({
	sharedBody,
	participant,
	seats = '',
	votingWeight = '',
	accessionDate = '',
	exitDate = '',
	label = '',
	id = '',
}) {
	const payload = { sharedBody, participant }
	const seatCount = numberOrNull(seats)
	if (seatCount !== null) payload.seats = Math.trunc(seatCount)
	const weight = numberOrNull(votingWeight)
	if (weight !== null) payload.votingWeight = weight
	const from = startOfDay(accessionDate)
	if (from) payload.accessionDate = from
	const until = startOfDay(exitDate)
	if (until) payload.exitDate = until
	if (label && String(label).trim()) payload.label = String(label).trim()
	if (id) payload.id = id
	return payload
}

/**
 * The payload that ends a participation today: the stored fields with the
 * withdrawal date set to the start of today. The participation stays on
 * record (history), it is not deleted.
 *
 * @param {object} participation The stored BodyParticipation.
 * @param {Date} [now] Today.
 *
 * @return {object} The payload.
 *
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function endParticipationPayload(participation, now = new Date()) {
	return buildParticipationPayload({
		id: participation.id,
		sharedBody: participation.sharedBody,
		participant: participation.participant,
		seats: participation.seats,
		votingWeight: participation.votingWeight,
		accessionDate: participation.accessionDate
			? new Date(participation.accessionDate)
			: '',
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
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function participationRows(
	participations,
	memberships,
	bodiesById,
	now = new Date(),
) {
	const filledBy = {}
	for (const membership of memberships || []) {
		if (!membership?.onBehalfOf || !fillsSeat(membership, now)) continue
		filledBy[membership.onBehalfOf] = (filledBy[membership.onBehalfOf] || 0) + 1
	}
	return (participations || [])
		.map((participation) => ({
			id: participation.id,
			participant: participation.participant,
			name: bodyName(bodiesById, participation.participant),
			seats: numberOrNull(participation.seats),
			votingWeight: numberOrNull(participation.votingWeight),
			accessionDate: participation.accessionDate || '',
			exitDate: participation.exitDate || '',
			filled: filledBy[participation.participant] || 0,
			active: isActiveParticipation(participation, now),
			source: participation,
		}))
		.sort(
			(a, b) =>
				Number(b.active) - Number(a.active) || a.name.localeCompare(b.name),
		)
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
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
export function onBehalfOfOptions(participations, bodiesById, now = new Date()) {
	const seen = new Set()
	const options = []
	for (const participation of participations || []) {
		const id = participation?.participant
		if (!id || seen.has(id) || !isActiveParticipation(participation, now)) continue
		seen.add(id)
		options.push({ id, label: bodyName(bodiesById, id) })
	}
	return options.sort((a, b) => a.label.localeCompare(b.label))
}
