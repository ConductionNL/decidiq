/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Entering a named vote taken on paper (board DcStemmenInvoeren): the chair
 * or the secretary picks for, against or abstain per member and saves them
 * in one request; a member without a choice is left out.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { paperVoteRows, recordPaperVotes } from '../../src/utils/paperVotes.js'

const posted = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: async (url, body) => {
			posted.push({ url, body })
			return { data: { recorded: body.votes.length, refused: [] } }
		},
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url.replace('{id}', params.id),
}))

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

describe('paper votes', () => {
	it('send one row per member with a choice, in the order of the list', () => {
		expect(
			paperVoteRows({
				'p-1': 'for',
				'p-2': '',
				'p-3': 'abstain',
				'p-4': 'maybe',
			}),
		).toEqual([
			{ participant: 'p-1', value: 'for' },
			{ participant: 'p-3', value: 'abstain' },
		])
	})

	it('post to the round recorded-votes endpoint', async () => {
		const result = await recordPaperVotes('r-1', { 'p-1': 'against' })
		expect(posted).toEqual([
			{
				url: '/apps/decidiq/api/voting-rounds/r-1/recorded-votes',
				body: { votes: [{ participant: 'p-1', value: 'against' }] },
			},
		])
		expect(result).toEqual({ recorded: 1, refused: [] })
		expect(read('appinfo', 'routes.php')).toContain(
			"'url' => '/api/voting-rounds/{id}/recorded-votes', 'verb' => 'POST'",
		)
	})

	it('are entered from the voting round, by the chair or the secretary, in a dialog of its own', () => {
		const panel = read('src', 'components', 'VotingRoundPanel.vue')
		expect(panel).toContain('<RecordVotesDialog')
		expect(panel).toMatch(
			/v-if="isRoundOpen && isChairOrSecretary && canEnterPaperVotes"/,
		)
		expect(
			fs.existsSync(
				path.join(ROOT, 'src', 'dialogs', 'RecordVotesDialog.vue'),
			),
		).toBe(true)
	})
})
