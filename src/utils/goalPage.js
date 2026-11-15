// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The goal page (board DcDoel): its progress lines, what contributes to it,
 * its parent and its steps (goals-linked-to-decisions Task 4).
 *
 * Pure functions, so the widgets that draw them stay thin and Vitest can test
 * the rules without a Vue transform. Every label is an English source string
 * the widget passes through `t('decidiq', …)`.
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
 */

/** How many contributions the list shows before "View all n". */
export const CONTRIBUTIONS_PREVIEW = 5

/**
 * The four kinds of object that can name a goal, with the field each one is
 * read by. `route` is the page id of the object's own detail page.
 */
export const CONTRIBUTION_KINDS = [
	{ kind: 'commitment', schema: 'governance-commitment', label: 'Commitment', route: 'CommitmentDetail', what: 'text', term: 'deadline', status: 'lifecycle' },
	{ kind: 'action-item', schema: 'action-item', label: 'Action item', route: 'ActionItemDetail', what: 'title', term: 'dueDate', status: 'taskStatus' },
	{ kind: 'planned-agenda-item', schema: 'planned-agenda-item', label: 'Planned agenda', route: 'PlannedAgendaDetail', what: 'subject', term: 'plannedPeriod', status: 'lifecycle' },
	{ kind: 'decision', schema: 'decision', label: 'Decision', route: 'DecisionDetail', what: 'title', term: 'decisionDate', status: 'lifecycle' },
]

/**
 * The id of an object as OpenRegister answers it.
 *
 * @param {?object} object The object.
 * @return {string} Its UUID, or ''.
 */
export function objectIdOf(object) {
	if (!isObject(object)) {
		return ''
	}
	const id = object.id ?? object.uuid ?? selfIdOf(object)
	return String(id ?? '')
}

/**
 * The id in an object's `@self` block, if it has one.
 *
 * @param {object} object The object.
 * @return {*} The id, or undefined.
 */
function selfIdOf(object) {
	return object['@self'] ? object['@self'].id : undefined
}

/**
 * A finite number, or null.
 *
 * @param {*} value The candidate.
 * @return {?number} The number.
 */
function toNumber(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const number = Number(value)
	return Number.isFinite(number) ? number : null
}

/**
 * How many of a list match a test.
 *
 * @param {Array<object>} list The list.
 * @param {Function} test The test.
 * @return {number} The count.
 */
function countWhere(list, test) {
	return (Array.isArray(list) ? list : []).filter((item) => item && test(item)).length
}

/**
 * The progress card's lines, as "x of y". A line whose total is zero is left
 * out, so a goal without commitments shows no commitment line.
 *
 * @param {?object} goal The goal.
 * @param {{commitments?: Array, actionItems?: Array, subgoals?: Array}} lists What points at the goal.
 * @return {Array<{key: string, label: string, current: number, total: number, unit: string}>} The lines.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
 */
export function progressLines(goal, lists = {}) {
	const g = goal || {}
	const target = toNumber(g.targetValue)
	const lines = [
		{
			key: 'target',
			label: 'Target value',
			current: toNumber(g.currentValue) ?? 0,
			total: target ?? 0,
			unit: unitOf(g),
		},
		{
			key: 'commitments',
			label: 'Commitments settled',
			current: countWhere(lists.commitments, (c) => c.lifecycle === 'disposed'),
			total: countWhere(lists.commitments, () => true),
			unit: '',
		},
		{
			key: 'action-items',
			label: 'Action items done',
			current: countWhere(lists.actionItems, (a) => a.taskStatus === 'completed'),
			total: countWhere(lists.actionItems, () => true),
			unit: '',
		},
		{
			key: 'subgoals',
			label: 'Sub-goals reached',
			current: countWhere(lists.subgoals, (s) => s.status === 'achieved'),
			total: countWhere(lists.subgoals, () => true),
			unit: '',
		},
	]
	return lines.filter((line) => line.total > 0)
}

/**
 * A goal's unit, trimmed, '' when it has none.
 *
 * @param {object} goal The goal.
 * @return {string} The unit.
 */
function unitOf(goal) {
	return typeof goal.unit === 'string' ? goal.unit.trim() : ''
}

/**
 * The share of a line that is done, 0 to 100, for its bar.
 *
 * @param {{current: number, total: number}} line The line.
 * @return {number} The percentage, capped at 100.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
 */
export function linePercent(line) {
	if (!line || !(line.total > 0)) {
		return 0
	}
	return Math.max(0, Math.min(100, Math.round((line.current / line.total) * 100)))
}

/**
 * The rows of "What contributes to this goal": every commitment, action item,
 * planned agenda item and decision that names it, in that order, each with a
 * link to its own page.
 *
 * @param {Object<string, Array<object>>} byKind The objects per kind (`commitment`, `action-item`, …).
 * @return {Array<{id: string, kind: string, kindLabel: string, what: string, term: string, status: string, location: ?object}>} The rows.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-011-the-goal-page-lists-what-contributes-to-it
 */
export function contributionRows(byKind = {}) {
	const rows = []
	for (const kind of CONTRIBUTION_KINDS) {
		const list = Array.isArray(byKind[kind.kind]) ? byKind[kind.kind] : []
		for (const object of list.filter(isObject)) {
			rows.push(contributionRow(kind, object))
		}
	}
	return rows
}

/**
 * Whether a value is a non-null object.
 *
 * @param {*} value The candidate.
 * @return {boolean} True for an object.
 */
function isObject(value) {
	return Boolean(value) && typeof value === 'object'
}

/**
 * A trimmed text field of an object, '' when absent.
 *
 * @param {object} object The object.
 * @param {string} field The field.
 * @return {string} The text.
 */
function textOf(object, field) {
	return String(object[field] ?? '').trim()
}

/**
 * One row of "What contributes to this goal".
 *
 * @param {object} kind The contribution kind.
 * @param {object} object The contributing object.
 * @return {{id: string, kind: string, kindLabel: string, what: string, term: string, status: string, location: ?object}} The row.
 */
function contributionRow(kind, object) {
	const id = objectIdOf(object)
	return {
		id: kind.kind + ':' + id,
		kind: kind.kind,
		kindLabel: kind.label,
		what: textOf(object, kind.what),
		term: textOf(object, kind.term),
		status: textOf(object, kind.status),
		location: id ? { name: kind.route, params: { id } } : null,
	}
}

/**
 * How many sub-goals a parent goal has and how many are reached, for the
 * parent card ("3 sub-goals, of which 1 reached").
 *
 * @param {Array<object>} subgoals The parent's sub-goals.
 * @return {{count: number, achieved: number}} The counts.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
 */
export function parentSummary(subgoals) {
	return {
		count: countWhere(subgoals, () => true),
		achieved: countWhere(subgoals, (s) => s.status === 'achieved'),
	}
}

/**
 * The parent goal's id, or '' for a top-level goal.
 *
 * @param {?object} goal The goal.
 * @return {string} The parent's UUID.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
 */
export function parentGoalIdOf(goal) {
	const id = goal && goal.parentGoal
	return typeof id === 'string' ? id.trim() : ''
}

/** The step a goal status stands on: at risk is still active, abandoned is on none. */
const STATUS_STEP = { draft: 0, active: 1, 'at-risk': 1, achieved: 2 }

/**
 * The goal's three steps, Draft, Active and Reached, each with its date and
 * whether it is done, the current step or still to come. An abandoned goal
 * is on none of them.
 *
 * Draft is dated by the record's creation, Active by `startDate` and
 * Achieved by `deadline`, the date the goal is to be reached by.
 *
 * @param {?object} goal The goal.
 * @return {Array<{key: string, label: string, date: string, status: string}>} The steps.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
 */
export function goalSteps(goal) {
	const g = goal || {}
	const self = g['@self'] || {}
	const reached = Object.hasOwn(STATUS_STEP, g.status) ? STATUS_STEP[g.status] : -1
	const steps = [
		{ key: 'draft', label: 'Draft', date: firstText(self.created, g.created) },
		{ key: 'active', label: 'Active', date: firstText(g.startDate) },
		{ key: 'achieved', label: 'Reached', date: firstText(g.deadline) },
	]
	return steps.map((step, index) => ({ ...step, status: stepStatus(index, reached, g.status) }))
}

/**
 * The first value that is set, as text; '' when none is.
 *
 * @param {...*} values The candidates, in order.
 * @return {string} The text.
 */
function firstText(...values) {
	return String(values.find((value) => value) || '')
}

/**
 * Whether one step is done, current or still to come.
 *
 * @param {number} index The step's position.
 * @param {number} reached The position the goal stands on, -1 for none.
 * @param {string} goalStatus The goal's status.
 * @return {string} 'done', 'current' or 'upcoming'.
 */
function stepStatus(index, reached, goalStatus) {
	if (reached >= 0 && index < reached) {
		return 'done'
	}
	if (index === reached) {
		return goalStatus === 'achieved' ? 'done' : 'current'
	}
	return 'upcoming'
}

/**
 * The date part of an ISO date or date-time, for display.
 *
 * @param {string} value The date.
 * @return {string} `YYYY-MM-DD`, or '' when there is none.
 */
export function shortDate(value) {
	const text = typeof value === 'string' ? value.trim() : ''
	const match = /^(\d{4}-\d{2}-\d{2})/.exec(text)
	return match ? match[1] : text
}
