// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Review status of registered other positions (nevenfuncties) for the
 * dashboard KPI and the per-body compliance panel (interests-and-integrity,
 * REQ-INT-010, design D3/D7). Pure functions: the callers fetch two standard
 * OpenRegister lists (memberships and ancillary positions) and join them
 * here, client-side. Assistive only: nothing here blocks, scores or notifies.
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */

/** The lifecycle state of a position that has ended (terminal). */
export const ENDED = 'ended'

/** The lifecycle states a position can be in before it ends. */
export const OPEN_STATES = ['reported', 'public', 'internal']

/** A review older than this many months is no longer current. */
export const REVIEW_MAX_AGE_MONTHS = 12

/** Compliance status: the member has no position registered for the body. */
export const STATUS_NONE = 'none'

/** Compliance status: the member's positions all have an outdated review. */
export const STATUS_STALE = 'stale'

/** Compliance status: at least one position was reviewed within the window. */
export const STATUS_CURRENT = 'current'

/**
 * The moment a review must be younger than to count as current: the given
 * moment, REVIEW_MAX_AGE_MONTHS calendar months back.
 *
 * @param {number} now The current time in milliseconds.
 * @return {number} The cut-off time in milliseconds.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function reviewCutoff(now) {
	const cutoff = new Date(now)
	cutoff.setMonth(cutoff.getMonth() - REVIEW_MAX_AGE_MONTHS)
	return cutoff.getTime()
}

/**
 * A date value as milliseconds, or NaN when it is empty or not a date.
 *
 * @param {unknown} value The date value.
 * @return {number} The time, or NaN.
 */
function toTime(value) {
	if (value === null || value === undefined || value === '') return NaN
	return new Date(value).getTime()
}

/**
 * The last time a position was looked at: its review, or its declaration
 * when it was never reviewed (the same rule as the annual rappel, REQ-INT-008).
 *
 * @param {object} position The ancillary position.
 * @return {number} The time in milliseconds, or NaN when it has neither.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function lastReviewTime(position) {
	const reviewed = toTime(position?.reviewedAt)
	return Number.isNaN(reviewed) ? toTime(position?.declaredAt) : reviewed
}

/**
 * Whether a position that has not ended needs its annual review: its last
 * review (or declaration) is older than the cut-off, or it has neither date.
 * An ended position never does.
 *
 * @param {object} position The ancillary position.
 * @param {number} now The current time in milliseconds.
 * @return {boolean} True when the review is overdue.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function isReviewOverdue(position, now) {
	if (!position || position.lifecycle === ENDED) return false
	const last = lastReviewTime(position)
	return Number.isNaN(last) || last < reviewCutoff(now)
}

/**
 * The positions whose annual review is overdue: what the dashboard KPI counts.
 *
 * @param {Array<object>} positions The ancillary positions.
 * @param {number} now The current time in milliseconds.
 * @return {Array<object>} The overdue positions.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function overdueReviews(positions, now) {
	return (Array.isArray(positions) ? positions : []).filter((position) =>
		isReviewOverdue(position, now),
	)
}

/**
 * An id from a reference: a plain id, or an object carrying `id`/`uuid`.
 *
 * @param {unknown} ref The reference.
 * @return {string} The id, or '' when there is none.
 */
export function refId(ref) {
	if (ref === null || ref === undefined) return ''
	if (typeof ref === 'object') return String(ref.id || ref.uuid || '')
	return String(ref)
}

/**
 * Whether a membership runs at the given moment: started (or no start date)
 * and not yet ended (or no end date).
 *
 * @param {object} membership The membership.
 * @param {number} now The current time in milliseconds.
 * @return {boolean} True when it is active.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function isActiveMembership(membership, now) {
	if (!membership) return false
	const start = toTime(membership.startDate)
	const end = toTime(membership.endDate)
	if (!Number.isNaN(start) && start > now) return false
	if (!Number.isNaN(end) && end < now) return false
	return true
}

/**
 * The compliance rows for one body: one row per person with an active
 * membership, with the review status of that person's positions in the body.
 * Ended positions do not count as a declaration. Rows needing attention
 * (no declaration, then outdated review) come first, then by name.
 *
 * @param {Array<object>} memberships The body's memberships.
 * @param {Array<object>} positions The body's ancillary positions.
 * @param {number} now The current time in milliseconds.
 * @param {Object<string, string>} [names] Person id → display name.
 * @return {Array<{person: string, name: string, status: string, positions: number}>} The rows.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function complianceRows(memberships, positions, now, names = {}) {
	const byPerson = new Map()
	for (const position of Array.isArray(positions) ? positions : []) {
		if (!position || position.lifecycle === ENDED) continue
		const person = refId(position.person)
		if (!person) continue
		if (!byPerson.has(person)) byPerson.set(person, [])
		byPerson.get(person).push(position)
	}

	const seen = new Set()
	const rows = []
	for (const membership of Array.isArray(memberships) ? memberships : []) {
		if (!isActiveMembership(membership, now)) continue
		const person = refId(membership.person)
		if (!person || seen.has(person)) continue
		seen.add(person)
		const own = byPerson.get(person) || []
		let status = STATUS_NONE
		if (own.length > 0) {
			status = own.some((position) => !isReviewOverdue(position, now))
				? STATUS_CURRENT
				: STATUS_STALE
		}
		rows.push({
			person,
			name: names[person] || person,
			status,
			positions: own.length,
		})
	}

	const rank = { [STATUS_NONE]: 0, [STATUS_STALE]: 1, [STATUS_CURRENT]: 2 }
	return rows.sort(
		(a, b) => rank[a.status] - rank[b.status] || a.name.localeCompare(b.name),
	)
}

/**
 * The route the KPI opens: the ancillary-positions index narrowed to the
 * positions that have not ended and whose review date lies before the
 * cut-off (`@today-365d`, resolved by the index page's filter grammar).
 * Positions that were declared but never reviewed carry no review date, so
 * the index cannot select them with one filter; the compliance panel on that
 * page lists their holders.
 *
 * @return {{name: string, query: object}} The route.
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
export function overdueReviewsRoute() {
	return {
		name: 'AncillaryPositions',
		query: {
			lifecycle: [...OPEN_STATES],
			'reviewedAt[lt]': '@today-365d',
		},
	}
}
