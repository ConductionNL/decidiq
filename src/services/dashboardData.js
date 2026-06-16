/**
 * dashboardData — centralised OpenRegister fetch helpers for the v2
 * dashboard widgets (Decision 3).
 *
 * Each named helper wraps `useObjectStore().fetchCollection(type, params)`
 * with the OR filter parameters that widget needs, so the eleven widget
 * SFCs never hand-roll OR query conventions and unit tests can mock this
 * single module instead of the Pinia store. Every helper resolves to the
 * results array (fetchCollection returns `data.results || data`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { useObjectStore } from '../store/store.js'

/**
 * Fetch meetings, optionally narrowed by lifecycle.
 *
 * @param {object} [params] Extra OR filter params (e.g. { lifecycle: 'scheduled' }).
 *
 * @return {Promise<Array<object>>} Meeting objects.
 */
export function getMeetings(params = {}) {
	return useObjectStore().fetchCollection('meeting', params)
}

/**
 * Fetch voting rounds, optionally narrowed by lifecycle (e.g. "open").
 *
 * @param {object} [params] Extra OR filter params.
 *
 * @return {Promise<Array<object>>} Voting-round objects.
 */
export function getVotingRounds(params = {}) {
	return useObjectStore().fetchCollection('voting-round', params)
}

/**
 * Fetch votes, optionally narrowed by participant.
 *
 * @param {object} [params] Extra OR filter params (e.g. { participant: id }).
 *
 * @return {Promise<Array<object>>} Vote objects.
 */
export function getVotes(params = {}) {
	return useObjectStore().fetchCollection('vote', params)
}

/**
 * Fetch action items, optionally narrowed by assignee / status.
 *
 * @param {object} [params] Extra OR filter params.
 *
 * @return {Promise<Array<object>>} Action-item objects.
 */
export function getActionItems(params = {}) {
	return useObjectStore().fetchCollection('action-item', params)
}

/**
 * Fetch motions, optionally narrowed by lifecycle.
 *
 * @param {object} [params] Extra OR filter params.
 *
 * @return {Promise<Array<object>>} Motion objects.
 */
export function getMotions(params = {}) {
	return useObjectStore().fetchCollection('motion', params)
}

/**
 * Fetch decisions, optionally sorted / paginated.
 *
 * @param {object} [params] Extra OR filter params (e.g. { _order: ..., _limit: 10 }).
 *
 * @return {Promise<Array<object>>} Decision objects.
 */
export function getDecisions(params = {}) {
	return useObjectStore().fetchCollection('decision', params)
}

/**
 * Fetch participants (used to resolve the current NC user → participant id).
 *
 * @param {object} [params] Extra OR filter params.
 *
 * @return {Promise<Array<object>>} Participant objects.
 */
export function getParticipants(params = {}) {
	return useObjectStore().fetchCollection('participant', params)
}

/**
 * Fetch minutes, optionally narrowed by lifecycle (e.g. "review").
 *
 * @param {object} [params] Extra OR filter params.
 *
 * @return {Promise<Array<object>>} Minutes objects.
 */
export function getMinutes(params = {}) {
	return useObjectStore().fetchCollection('minutes', params)
}

/**
 * Fetch governance bodies (used by the empty-state detection).
 *
 * @param {object} [params] Extra OR filter params.
 *
 * @return {Promise<Array<object>>} Governance-body objects.
 */
export function getGovernanceBodies(params = {}) {
	return useObjectStore().fetchCollection('governance-body', params)
}
