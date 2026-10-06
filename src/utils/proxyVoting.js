// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Proxy (volmacht) voting in the voting round panel: which proxies the user
 * holds on a round, and the cast request for a vote on someone's behalf.
 *
 * GET /api/voting-rounds/{id}/proxy answers for the logged-in user, resolved
 * on the server the same way the cast endpoint resolves them, so a held
 * proxy's `participantId` is exactly the `delegatorId` the cast accepts.
 *
 * @spec openspec/specs/voting-system/spec.md
 */

/** Nothing held, nothing given: the state before the answer, and after an error. */
export const NO_PROXIES = Object.freeze({ held: [], granted: null })

/**
 * The app-relative path of a round's proxy read (and grant / revoke).
 *
 * @param {string} roundId Voting round UUID.
 *
 * @return {string} The path to pass to generateUrl().
 *
 * @spec openspec/specs/voting-system/spec.md
 */
export function proxiesPath(roundId) {
	return `/apps/decidiq/api/voting-rounds/${encodeURIComponent(roundId)}/proxy`
}

/**
 * Read the server's answer, dropping anything malformed.
 *
 * @param {?object} payload The JSON body of the proxy read.
 *
 * @return {{held: Array<{participantId: string, displayName: string}>, granted: ?string}} The proxies.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
export function readProxies(payload) {
	const held = Array.isArray(payload?.held)
		? payload.held
			.filter((entry) => typeof entry?.participantId === 'string' && entry.participantId !== '')
			.map((entry) => ({
				participantId: entry.participantId,
				displayName: entry.displayName || entry.participantId,
			}))
		: []
	const granted = typeof payload?.granted === 'string' && payload.granted !== ''
		? payload.granted
		: null
	return { held, granted }
}

/**
 * The proxy part of a cast request: the user's own vote when `delegatorId` is
 * empty, a vote on the delegator's behalf otherwise.
 *
 * @param {string} delegatorId The delegator's participant UUID, or '' for the user's own vote.
 *
 * @return {{isProxy: boolean, delegatorId: ?string}} The body fields.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
export function proxyCastFields(delegatorId) {
	if (!delegatorId) {
		return { isProxy: false, delegatorId: null }
	}
	return { isProxy: true, delegatorId }
}

/**
 * Who the next vote is for once a vote is cast: the user when they have not
 * voted yet, else the first held proxy not yet used, else '' (nothing left).
 *
 * @param {boolean} ownVoteCast Whether the user cast their own vote.
 * @param {Array<{participantId: string}>} held The proxies held.
 * @param {Array<string>} proxyVotesCast Delegator UUIDs already voted for.
 *
 * @return {string} The delegator UUID to vote for next, or '' for the user's own vote.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
export function nextCastTarget(ownVoteCast, held, proxyVotesCast) {
	if (!ownVoteCast) return ''
	const open = held.find((entry) => !proxyVotesCast.includes(entry.participantId))
	return open ? open.participantId : ''
}
