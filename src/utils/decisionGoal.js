// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The goal a decision serves, as the decision page shows it
 * (goals-linked-to-decisions REQ-010).
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
 */

/** The goal states a decision may be linked to: a finished goal is not offered. */
export const OFFERED_GOAL_STATES = ['draft', 'active', 'at-risk']

/**
 * The goal id a decision points at, or '' when it serves no goal.
 *
 * @param {?object} decision The decision.
 * @return {string} The goal UUID.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
 */
export function goalIdOf(decision) {
	const id = decision && decision.goal
	return typeof id === 'string' ? id.trim() : ''
}

/**
 * The link to a goal's own page, the route GoalDetail declares (/goals/:id).
 *
 * @param {string} goalId The goal UUID.
 * @return {?object} A router location, null without a goal.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
 */
export function goalLocation(goalId) {
	if (!goalId) {
		return null
	}
	return { name: 'GoalDetail', params: { id: goalId } }
}

/**
 * The label of a goal: its title, else its id while the title is unknown.
 *
 * @param {?object} goal The goal, null while it loads or when it is gone.
 * @param {string} goalId The goal UUID.
 * @return {string} The label.
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
 */
export function goalLabel(goal, goalId) {
	const title = goal && typeof goal.title === 'string' ? goal.title.trim() : ''
	return title || goalId
}
