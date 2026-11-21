// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A member's own declarations on the My declarations page
 * (interests-and-integrity, REQ-INT-010, design D3). Pure functions: the page
 * fetches the people, other positions and gifts through standard OpenRegister
 * list queries and keeps only the current user's own here. Every write the
 * page makes is a whole object (OpenRegister saveObject is PUT-semantic), so
 * the helpers that change a record return it with every other field carried
 * forward, never a partial patch.
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */

import { ENDED, refId } from './integrityCompliance.js'

/** The lifecycle state a newly declared position starts in (the schema's `initial`). */
export const REPORTED = 'reported'

/** The states a position can be ended from (the schema's transitions to `ended`). */
export const ENDABLE_STATES = ['public', 'internal']

/** The kinds of gift registration the schema knows. */
export const GIFT_TYPES = ['gift', 'invitation']

/** The decisions on a gift the schema knows. */
export const GIFT_DECISIONS = ['accepted', 'refused', 'transferred']

/** The longest free text accepted in the dialogs. */
export const MAX_TEXT_LENGTH = 255

/**
 * A date as `YYYY-MM-DD` in local time, the format the schema's `date` fields take.
 *
 * @param {Date} [now] The moment.
 * @return {string} The day.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification
 */
export function dayOf(now = new Date()) {
	const month = String(now.getMonth() + 1).padStart(2, '0')
	const day = String(now.getDate()).padStart(2, '0')
	return `${now.getFullYear()}-${month}-${day}`
}

/**
 * The ids of the people the current user is: every person whose Nextcloud
 * user id is the user's.
 *
 * @param {Array<object>} people The people.
 * @param {string} uid The current user's id.
 * @return {Array<string>} Their person ids.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function ownPersonIds(people, uid) {
	if (!uid) return []
	return (Array.isArray(people) ? people : [])
		.filter((person) => person && String(person.nextcloudUserId || '') === uid)
		.map((person) => refId(person))
		.filter((id) => id !== '')
}

/**
 * The records that belong to one of the given people.
 *
 * @param {Array<object>} records The positions or gifts.
 * @param {Array<string>} personIds The current user's person ids.
 * @param {string} field The field naming the holder (`person` or `recipient`).
 * @return {Array<object>} The user's own records.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function ownRecords(records, personIds, field) {
	if (!Array.isArray(personIds) || personIds.length === 0) return []
	return (Array.isArray(records) ? records : []).filter(
		(record) => record && personIds.includes(refId(record[field])),
	)
}

/**
 * Whether a position may be ended now: the schema ends a position only from
 * a disclosure state, not while it still waits to be processed.
 *
 * @param {object|null|undefined} position The position.
 * @return {boolean} True when it can be ended.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative
 */
export function canEnd(position) {
	return ENDABLE_STATES.includes(position?.lifecycle)
}

/**
 * Whether the member can still confirm a position: any position that has not ended.
 *
 * @param {object|null|undefined} position The position.
 * @return {boolean} True when it can be confirmed.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification
 */
export function canConfirm(position) {
	return Boolean(position) && position.lifecycle !== ENDED
}

/**
 * The position confirmed as still accurate today: `reviewedAt` is set and no
 * other field changes.
 *
 * @param {object} position The position.
 * @param {Date} [now] The moment of confirmation.
 * @return {object} The whole position to save.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification
 */
export function confirmedReview(position, now = new Date()) {
	return { ...position, reviewedAt: dayOf(now) }
}

/**
 * The position ended on a day: the lifecycle moves to `ended` and the end date is recorded.
 *
 * @param {object} position The position.
 * @param {string} endDate The day it ended (`YYYY-MM-DD`).
 * @return {object} The whole position to save.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative
 */
export function endedPosition(position, endDate) {
	return { ...position, endDate, lifecycle: ENDED }
}

/**
 * Whether an end date is acceptable: a day, not before the position started.
 *
 * @param {object} position The position.
 * @param {string} endDate The day typed in the dialog.
 * @return {boolean} True when it can be saved.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative
 */
export function endDateIsValid(position, endDate) {
	if (typeof endDate !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(endDate)) return false
	const start = String(position?.startDate || '')
	return start === '' || endDate >= start.slice(0, 10)
}

/**
 * Whether a text field holds some text within the length limit.
 *
 * @param {unknown} value The value.
 * @return {boolean} True when it is filled in.
 */
function filled(value) {
	if (typeof value !== 'string') return false
	const trimmed = value.trim()
	return trimmed !== '' && trimmed.length <= MAX_TEXT_LENGTH
}

/**
 * Whether the form for a new position is complete.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be saved.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-001-nevenfunctie-schema-on-openregister
 */
export function positionFormIsValid(form) {
	return Boolean(form)
		&& refId(form.governanceBody) !== ''
		&& filled(form.organisation)
		&& filled(form.role)
		&& /^\d{4}-\d{2}-\d{2}$/.test(String(form.startDate || ''))
}

/**
 * A new position declared today by the given person, in the schema's initial state.
 *
 * @param {object} form The dialog's fields.
 * @param {string} personId The declaring person.
 * @param {Date} [now] The moment of declaration.
 * @return {object} The position to save.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-001-nevenfunctie-schema-on-openregister
 */
export function newPosition(form, personId, now = new Date()) {
	const position = {
		person: personId,
		governanceBody: refId(form.governanceBody),
		organisation: form.organisation.trim(),
		role: form.role.trim(),
		remunerated: form.remunerated === true,
		qualitateQua: form.qualitateQua === true,
		startDate: form.startDate,
		declaredAt: dayOf(now),
		lifecycle: REPORTED,
	}
	if (filled(form.hoursIndication)) position.hoursIndication = form.hoursIndication.trim()
	return position
}

/**
 * Whether the form for a gift registration is complete.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be saved.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-005-geschenk-schema-on-openregister
 */
export function giftFormIsValid(form) {
	if (!form) return false
	return refId(form.governanceBody) !== ''
		&& GIFT_TYPES.includes(form.type)
		&& GIFT_DECISIONS.includes(form.decision)
		&& filled(form.giver)
		&& filled(form.description)
		&& valueIsValid(form.estimatedValue)
		&& /^\d{4}-\d{2}-\d{2}$/.test(String(form.receivedOn || ''))
}

/**
 * Whether an estimated value is a number of zero or more.
 *
 * @param {unknown} value The value typed in the dialog.
 * @return {boolean} True when it is acceptable.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-005-geschenk-schema-on-openregister
 */
export function valueIsValid(value) {
	if (value === '' || value === null || value === undefined) return false
	const amount = Number(value)
	return Number.isFinite(amount) && amount >= 0
}

/**
 * A gift registered today by the given person as its recipient.
 *
 * @param {object} form The dialog's fields.
 * @param {string} personId The receiving person.
 * @param {Date} [now] The moment of registration.
 * @return {object} The gift to save.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-005-geschenk-schema-on-openregister
 */
export function newGift(form, personId, now = new Date()) {
	const gift = {
		recipient: personId,
		governanceBody: refId(form.governanceBody),
		type: form.type,
		giver: form.giver.trim(),
		description: form.description.trim(),
		estimatedValue: Number(form.estimatedValue),
		receivedOn: form.receivedOn,
		decision: form.decision,
		declaredAt: dayOf(now),
	}
	if (filled(form.notes)) gift.notes = form.notes.trim()
	return gift
}

/** The three forms the declaration dialog shows. */
export const DIALOG_KINDS = ['position', 'gift', 'end']

/**
 * The empty form of a dialog kind, with the body preselected when there is only one.
 *
 * @param {string} kind One of DIALOG_KINDS.
 * @param {Array<{id: string}>} [bodies] The governance bodies to choose from.
 * @param {Date} [now] Today, the default end date.
 * @return {object} The form's fields.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function emptyForm(kind, bodies = [], now = new Date()) {
	if (kind === 'end') return { endDate: dayOf(now) }
	const governanceBody = bodies.length === 1 ? bodies[0].id : ''
	if (kind === 'gift') {
		return { governanceBody, type: 'gift', giver: '', description: '', estimatedValue: '', receivedOn: '', decision: 'accepted', notes: '' }
	}
	return { governanceBody, organisation: '', role: '', hoursIndication: '', startDate: '', remunerated: false, qualitateQua: false }
}

/**
 * Whether a dialog's form can be saved.
 *
 * @param {string} kind One of DIALOG_KINDS.
 * @param {object} form The form's fields.
 * @param {object} [position] The position being ended, for `end`.
 * @return {boolean} True when it is complete.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function formIsValid(kind, form, position = null) {
	if (kind === 'end') return endDateIsValid(position, form?.endDate)
	if (kind === 'gift') return giftFormIsValid(form)
	return positionFormIsValid(form)
}
