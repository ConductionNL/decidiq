// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for a body's members: from when to when, contact details and
 * factions (bodies-membership-terms-contacts-and-factions).
 *
 * @spec openspec/specs/governance-bodies/spec.md
 */

import {
	buildMemberRow,
	isActiveMembership,
} from '../components/tabs/useRelationStore.js'

/**
 * A date picked in a date field (YYYY-MM-DD or a Date) as the start of that
 * day in the user's time zone, in ISO form.
 *
 * @param {string|Date} value The picked date.
 *
 * @return {string} The ISO date-time, or '' when no date was picked.
 *
 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-001-a-membership-records-from-when-to-when
 */
export function startOfDay(value) {
	if (!value) return ''
	if (value instanceof Date) {
		return new Date(
			value.getFullYear(),
			value.getMonth(),
			value.getDate(),
		).toISOString()
	}
	const [year, month, day] = String(value).split('-').map(Number)
	if (!year || !month || !day) return ''
	return new Date(year, month - 1, day).toISOString()
}

/**
 * The member rows to show: current members, or with past on, the members
 * who left, each with the dates they sat from and to.
 *
 * @param {Array<object>} memberships The body's memberships.
 * @param {Object<string, object>} personsById The people, by id.
 * @param {{past: boolean}} options Whether to list past members.
 *
 * @return {Array<object>} The rows.
 *
 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-001-a-membership-records-from-when-to-when
 */
export function memberRowsFor(memberships, personsById = {}, { past = false } = {}) {
	return (memberships || [])
		.filter((membership) =>
			past ? !isActiveMembership(membership) : isActiveMembership(membership),
		)
		.map((membership) =>
			buildMemberRow(membership, personsById[membership.person]),
		)
}

/**
 * A ContactDetail for a person or for a body.
 *
 * @param {{type: string, value: string, personId?: string, bodyId?: string, id?: string}} fields The detail.
 *
 * @return {object} The object to save.
 *
 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies
 */
export function contactDetailPayload({
	type,
	value,
	personId = '',
	bodyId = '',
	id = '',
}) {
	const payload = { type, value: String(value ?? '').trim() }
	if (personId) payload.person = personId
	if (bodyId) payload.governanceBody = bodyId
	if (id) payload.id = id
	return payload
}

/**
 * The email and phone a member row shows: the first of each kind.
 *
 * @param {Array<{type: string, value: string}>} details The person's contact details.
 *
 * @return {{email: string, phone: string}} What the row shows.
 *
 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies
 */
export function contactSummary(details) {
	const first = (types) =>
		(details || []).find((d) => types.includes(d?.type))?.value || ''
	return { email: first(['email']), phone: first(['phone', 'cell']) }
}

/**
 * The factions of a body: its sub-bodies of type faction, as select options.
 *
 * @param {Array<object>} bodies Governance bodies.
 * @param {string} bodyId The council.
 *
 * @return {Array<{id: string, label: string}>} The factions.
 *
 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-003-members-belong-to-a-faction-with-its-own-workspace
 */
export function factionsOf(bodies, bodyId) {
	return (bodies || [])
		.filter(
			(body) => body?.bodyType === 'faction' && body?.parentBody === bodyId,
		)
		.map((body) => ({ id: body.id, label: body.name || body.id }))
}
