/**
 * Pure, DOM-free computed logic for the v2 dashboard widgets.
 *
 * Every governance-domain calculation a widget needs (set-difference for
 * pending votes, overdue detection, <24h urgency, lifecycle grouping,
 * outcome-null active count, badge mapping, health-series assembly) lives
 * here as a side-effect-free function taking its inputs explicitly (the
 * "now" clock is always a parameter, never `Date.now()` inside the fn) so
 * it is unit-testable under vitest's `node` environment without mounting
 * the SFC. The `.vue` widgets are thin presentational shells that import
 * these helpers — see tests/vitest/dashboard/*.spec.js.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

/** One day in milliseconds — the urgency threshold for votes/meetings. */
export const DAY_MS = 24 * 60 * 60 * 1000

/** Action-item statuses that take an item out of the "overdue" pool. */
export const CLOSED_TASK_STATUSES = ['completed', 'cancelled']

/**
 * Motion lifecycle stages shown on the running-processes widget, in order.
 *
 * ADR-005 Decision.lifecycle vocabulary. This list previously read
 * `['submitted', 'under-discussion', 'voting']` — a THIRD vocabulary, matching
 * neither the retired Motion states nor the Decision enum ('under-discussion'
 * appears nowhere else in the codebase). Since the widget passes this array
 * straight to `getMotions({ lifecycle: ... })`, it filtered on values no stored
 * object can hold, and the running-processes widget was permanently empty.
 *
 * `draft` is deliberately excluded: an unsubmitted motion is not yet a running
 * process. Terminal states (decided/enacted/archived/withdrawn) are excluded
 * for the same reason in the other direction.
 */
export const RUNNING_MOTION_LIFECYCLES = ['proposed', 'deliberating', 'voting']

/**
 * Parse a date-ish value to epoch milliseconds, or NaN when unparseable.
 *
 * @param {string|number|Date|null|undefined} value A date string/number/Date.
 *
 * @return {number} Epoch ms, or NaN.
 */
export function toTime(value) {
	if (value === null || value === undefined || value === '') {
		return NaN
	}
	return new Date(value).getTime()
}

// --- Pending votes (REQ-009 KPI / REQ-012 list) ------------------------------

/**
 * Resolve the current Nextcloud user to their participant record id.
 *
 * @param {Array<object>} participants Participant objects.
 * @param {string} uid The current user's NC uid.
 *
 * @return {string|number|null} The matching participant id, or null when the
 *   user has no participant record (observer/guest/admin — Decision 4).
 */
export function resolveParticipantId(participants, uid) {
	if (!uid || !Array.isArray(participants)) {
		return null
	}
	const match = participants.find((p) => p && p.nextcloudUserId === uid)
	return match ? match.id : null
}

/**
 * Set-difference: open voting rounds the participant has NOT yet voted in.
 *
 * No participant record (id null) ⇒ empty (the user is not a voting member,
 * so they see no pending votes rather than an over-count — Decision 4).
 *
 * @param {Array<object>} openRounds Voting rounds with lifecycle "open".
 * @param {Array<object>} votes The participant's cast votes.
 * @param {string|number|null} participantId The current participant id.
 *
 * @return {Array<object>} Open rounds awaiting this participant's vote.
 */
export function pendingVotingRounds(openRounds, votes, participantId) {
	if (participantId === null || participantId === undefined || !Array.isArray(openRounds)) {
		return []
	}
	const votedRoundIds = new Set(
		(Array.isArray(votes) ? votes : [])
			.filter((v) => v && String(v.participant) === String(participantId))
			.map((v) => String(v.votingRound)),
	)
	return openRounds.filter((r) => r && !votedRoundIds.has(String(r.id)))
}

/**
 * Whether a voting round's deadline falls inside the active dashboard window.
 * An empty / absent range (the "All" preset) matches everything; a bounded
 * range excludes rounds with no parseable votingDeadline (consistent with the
 * declarative `dateField BETWEEN from..to` filter the stat KPIs apply).
 *
 * @param {object} round A voting-round object (uses `votingDeadline`).
 * @param {{from?: string, to?: string}|null} range The dashboard date window.
 *
 * @return {boolean} True when the round is in range (or no range is set).
 */
export function withinDeadlineRange(round, range) {
	if (!range || (!range.from && !range.to)) {
		return true
	}
	const t = toTime(round && round.votingDeadline)
	if (Number.isNaN(t)) {
		return false
	}
	const from = range.from ? toTime(range.from) : -Infinity
	const to = range.to ? toTime(range.to) : Infinity
	return t >= from && t <= to
}

/**
 * Filter pending voting rounds to those whose deadline is in the active window.
 *
 * @param {Array<object>} rounds Pending voting rounds.
 * @param {{from?: string, to?: string}|null} range The dashboard date window.
 *
 * @return {Array<object>} Rounds within the window (all when no range set).
 */
export function pendingInRange(rounds, range) {
	return (Array.isArray(rounds) ? rounds : []).filter((r) => withinDeadlineRange(r, range))
}

// --- Urgency / countdown (REQ-011 / REQ-012) ---------------------------------

/**
 * Whether a deadline is less than 24h away (red urgency indicator).
 * A deadline already in the past is still urgent.
 *
 * @param {string|number|Date} deadline The deadline.
 * @param {number} now Epoch ms "now".
 *
 * @return {boolean} True when under 24h remain (or it has passed).
 */
export function isUrgent(deadline, now) {
	const t = toTime(deadline)
	if (Number.isNaN(t)) {
		return false
	}
	return t - now < DAY_MS
}

/**
 * Human countdown bucket for a deadline relative to now.
 *
 * @param {string|number|Date} deadline The deadline.
 * @param {number} now Epoch ms "now".
 *
 * @return {{ key: string, hours: number }} A coarse bucket the SFC maps to a
 *   translated label ("today"/"tomorrow"/"Less than 24 hours remaining").
 */
export function countdownBucket(deadline, now) {
	const t = toTime(deadline)
	if (Number.isNaN(t)) {
		return { key: 'unknown', hours: NaN }
	}
	const hours = (t - now) / (60 * 60 * 1000)
	if (hours < 0) {
		return { key: 'overdue', hours }
	}
	if (hours < 24) {
		return { key: 'today', hours }
	}
	if (hours < 48) {
		return { key: 'tomorrow', hours }
	}
	return { key: 'later', hours }
}

// --- Meetings (REQ-008 KPI / REQ-011 list) -----------------------------------

/**
 * Whether a meeting is scheduled at or after "now".
 *
 * @param {object} meeting A meeting object.
 * @param {number} now Epoch ms "now".
 *
 * @return {boolean} True when scheduledDate >= now.
 */
export function isUpcoming(meeting, now) {
	const t = toTime(meeting && meeting.scheduledDate)
	return !Number.isNaN(t) && t >= now
}

/**
 * Upcoming meetings, ascending by scheduledDate.
 *
 * @param {Array<object>} meetings Meetings (already lifecycle=scheduled).
 * @param {number} now Epoch ms "now".
 *
 * @return {Array<object>} Filtered + sorted upcoming meetings.
 */
export function upcomingMeetings(meetings, now) {
	return (Array.isArray(meetings) ? meetings : [])
		.filter((m) => isUpcoming(m, now))
		.sort((a, b) => toTime(a.scheduledDate) - toTime(b.scheduledDate))
}

// --- Action items (REQ-002 / REQ-010) ----------------------------------------

/**
 * Whether an action item is overdue: past due AND not completed/cancelled.
 *
 * @param {object} item An action-item object.
 * @param {number} now Epoch ms "now".
 *
 * @return {boolean} True when overdue.
 */
export function isOverdue(item, now) {
	if (!item || CLOSED_TASK_STATUSES.includes(item.taskStatus)) {
		return false
	}
	const t = toTime(item.dueDate)
	return !Number.isNaN(t) && t < now
}

/**
 * Action items overdue right now.
 *
 * @param {Array<object>} items Action items.
 * @param {number} now Epoch ms "now".
 *
 * @return {Array<object>} Overdue items.
 */
export function overdueActions(items, now) {
	return (Array.isArray(items) ? items : []).filter((i) => isOverdue(i, now))
}

/**
 * Action items sorted by dueDate ascending (undated items last).
 *
 * @param {Array<object>} items Action items.
 *
 * @return {Array<object>} A new sorted array.
 */
export function sortByDueDate(items) {
	return (Array.isArray(items) ? items : []).slice().sort((a, b) => {
		const ta = toTime(a.dueDate)
		const tb = toTime(b.dueDate)
		if (Number.isNaN(ta)) {
			return Number.isNaN(tb) ? 0 : 1
		}
		if (Number.isNaN(tb)) {
			return -1
		}
		return ta - tb
	})
}

// --- Motions (REQ-001) -------------------------------------------------------

/**
 * Group in-flight motions by lifecycle stage (reduce).
 *
 * @param {Array<object>} motions Motions with running lifecycles.
 *
 * @return {Object<string, Array<object>>} Map keyed by every stage in
 *   RUNNING_MOTION_LIFECYCLES (always present, possibly empty).
 */
export function groupMotionsByLifecycle(motions) {
	const seed = RUNNING_MOTION_LIFECYCLES.reduce((acc, k) => {
		acc[k] = []
		return acc
	}, {})
	return (Array.isArray(motions) ? motions : []).reduce((acc, m) => {
		if (m && acc[m.lifecycle]) {
			acc[m.lifecycle].push(m)
		}
		return acc
	}, seed)
}

// --- Decisions (REQ-003 list / REQ-013 active count) -------------------------

/**
 * Whether a decision is still active (undecided): outcome is null/absent.
 * Outcome enum is [adopted, rejected]; null ⇒ undecided ⇒ active.
 *
 * @param {object} decision A decision object.
 *
 * @return {boolean} True when undecided.
 */
export function isActiveDecision(decision) {
	return !!decision && (decision.outcome === null || decision.outcome === undefined || decision.outcome === '')
}

/**
 * Count of active (undecided) decisions.
 *
 * @param {Array<object>} decisions Decisions.
 *
 * @return {number} How many have a null outcome.
 */
export function activeDecisionCount(decisions) {
	return (Array.isArray(decisions) ? decisions : []).filter(isActiveDecision).length
}

/**
 * Recent decisions, decisionDate DESC, capped at `limit`.
 *
 * @param {Array<object>} decisions Decisions.
 * @param {number} [limit] Max rows (default 10).
 *
 * @return {Array<object>} Newest-first, truncated.
 */
export function recentDecisions(decisions, limit = 10) {
	return (Array.isArray(decisions) ? decisions : [])
		.slice()
		.sort((a, b) => toTime(b.decisionDate) - toTime(a.decisionDate))
		.slice(0, limit)
}

/**
 * Map a decision outcome to its badge label key + status-badge variant.
 *
 * @param {string|null} outcome The outcome enum value (adopted/rejected/null).
 *
 * @return {{ label: string, variant: string }} English label key + variant.
 */
export function outcomeBadge(outcome) {
	switch (outcome) {
	case 'adopted':
		return { label: 'Adopted', variant: 'success' }
	case 'rejected':
		return { label: 'Rejected', variant: 'error' }
	case null:
	case undefined:
	case '':
		return { label: 'Undecided', variant: 'default' }
	default:
		return { label: outcome, variant: 'default' }
	}
}

/**
 * Map a decision publication status to its badge label key.
 * isPublished enum: [internal, public, confidential].
 *
 * @param {string|null} isPublished The publication status.
 *
 * @return {{ label: string, variant: string }} English label key + variant.
 */
export function publicationBadge(isPublished) {
	switch (isPublished) {
	case 'internal':
		return { label: 'Internal', variant: 'default' }
	case 'public':
		return { label: 'Public', variant: 'success' }
	case 'confidential':
		return { label: 'Confidential', variant: 'warning' }
	default:
		return { label: isPublished || '', variant: 'default' }
	}
}

// --- Governance health (REQ-004) ---------------------------------------------

/**
 * Meetings usable as health data points: both materialized metrics present,
 * ascending by scheduledDate, capped at 12 most recent.
 *
 * @param {Array<object>} meetings Meetings.
 *
 * @return {Array<object>} Up to 12 meetings with both metrics non-null.
 */
export function healthDataPoints(meetings) {
	const usable = (Array.isArray(meetings) ? meetings : []).filter((m) =>
		m
		&& m.quorumPercentage !== null && m.quorumPercentage !== undefined
		&& m.actionItemCompletionRate !== null && m.actionItemCompletionRate !== undefined,
	)
	return usable
		.sort((a, b) => toTime(a.scheduledDate) - toTime(b.scheduledDate))
		.slice(-12)
}

/**
 * Whether there are enough points to draw the chart (≥2 — Decision 5).
 *
 * @param {Array<object>} points Output of healthDataPoints().
 *
 * @return {boolean} True when at least 2 data points exist.
 */
export function hasEnoughHealthData(points) {
	return Array.isArray(points) && points.length >= 2
}

/**
 * Assemble the two live chart series + x-axis categories from data points.
 * Never fabricates values — empty in, empty out (Decision 5).
 *
 * @param {Array<object>} points Output of healthDataPoints().
 *
 * @return {{ series: Array<{name: string, data: number[]}>, categories: string[] }}
 *   ApexCharts-shaped series for quorum % and action-item completion %.
 */
export function healthSeries(points) {
	const rows = Array.isArray(points) ? points : []
	return {
		series: [
			{ name: 'Quorum %', data: rows.map((m) => m.quorumPercentage) },
			{ name: 'Action item completion %', data: rows.map((m) => m.actionItemCompletionRate) },
		],
		categories: rows.map((m) => m.scheduledDate),
	}
}
