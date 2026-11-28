// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Read what points at a goal: its commitments, action items, planned agenda
 * items, decisions and sub-goals (goals-linked-to-decisions Task 4).
 *
 * Read straight from OpenRegister and kept out of the shared object store on
 * purpose: the store keeps one collection per type, so a goal page reading
 * `action-item?goal=<id>` would replace whatever list another widget on the
 * same page holds (see AgendaBuilder.loadRecurringItems for the defect this
 * caused once).
 *
 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-011-the-goal-page-lists-what-contributes-to-it
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CONTRIBUTION_KINDS } from '../utils/goalPage.js'

/** The page size of one read; a goal with more contributions than this is not expected. */
export const READ_LIMIT = 500

/**
 * The objects of one schema whose `field` is `value`.
 *
 * @param {string} register The register slug.
 * @param {string} schema The schema slug.
 * @param {object} filter The property filter.
 * @return {Promise<Array<object>>} The objects; an unreadable list is empty.
 */
export async function fetchObjects(register, schema, filter) {
	const { data } = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`),
		// `_limit` is the page size; a bare `limit` is a property filter.
		{ params: { ...filter, _limit: READ_LIMIT } },
	)
	return data?.results ?? (Array.isArray(data) ? data : [])
}

/**
 * Every object of the four contributing kinds that names the goal, by kind.
 * A kind whose read fails is empty rather than failing the page.
 *
 * @param {string} goalId The goal's UUID.
 * @param {string} [register] The register slug.
 * @return {Promise<Object<string, Array<object>>>} The objects per kind.
 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-011-the-goal-page-lists-what-contributes-to-it
 */
export async function fetchContributions(goalId, register = 'decidiq') {
	const lists = await Promise.all(CONTRIBUTION_KINDS.map(async (kind) => {
		try {
			return await fetchObjects(register, kind.schema, { goal: goalId })
		} catch {
			return []
		}
	}))
	const byKind = {}
	CONTRIBUTION_KINDS.forEach((kind, index) => {
		byKind[kind.kind] = lists[index]
	})
	return byKind
}

/**
 * The sub-goals of a goal: the goals whose `parentGoal` is it.
 *
 * @param {string} goalId The goal's UUID.
 * @param {string} [register] The register slug.
 * @return {Promise<Array<object>>} The sub-goals; empty when unreadable.
 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
 */
export async function fetchSubgoals(goalId, register = 'decidiq') {
	try {
		return await fetchObjects(register, 'goal', { parentGoal: goalId })
	} catch {
		return []
	}
}

/**
 * One goal.
 *
 * @param {string} goalId The goal's UUID.
 * @param {string} [register] The register slug.
 * @return {Promise<?object>} The goal, or null when it cannot be read.
 * @spec openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal
 */
export async function fetchGoal(goalId, register = 'decidiq') {
	try {
		const { data } = await axios.get(
			generateUrl(`/apps/openregister/api/objects/${encodeURIComponent(register)}/goal/${encodeURIComponent(goalId)}`),
		)
		return data && typeof data === 'object' ? data : null
	} catch {
		return null
	}
}
