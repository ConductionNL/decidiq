/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A named vote taken on paper, entered by the chair or the secretary (board
 * DcStemmenInvoeren). The server casts each ballot as the member's own vote
 * and reports the ones it refused.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const VALUES = ['for', 'against', 'abstain']

/**
 * The ballots to send: one per member with a choice.
 *
 * @param {Record<string, string>} choices The choice per participant id.
 * @return {Array<{participant: string, value: string}>} The rows.
 */
export function paperVoteRows(choices) {
	return Object.entries(choices || {})
		.filter(
			([participant, value]) => participant !== '' && VALUES.includes(value),
		)
		.map(([participant, value]) => ({ participant, value }))
}

/**
 * Save the ballots of one round.
 *
 * @param {string} roundId The voting round id.
 * @param {Record<string, string>} choices The choice per participant id.
 * @return {Promise<{recorded: number, refused: Array<{participant: string, message: string}>}>} The server's answer.
 */
export async function recordPaperVotes(roundId, choices) {
	const { data } = await axios.post(
		generateUrl('/apps/decidiq/api/voting-rounds/{id}/recorded-votes', {
			id: roundId,
		}),
		{ votes: paperVoteRows(choices) },
	)
	return data
}
