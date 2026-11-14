/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The entry sheet of a roll call round (voting-named-paper-vote-entry, task 5):
 * the chair or the secretary records each active member's choice by name, or a
 * whole party at once, and a refused list names the members concerned.
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	activeMembers,
	applyPartyChoice,
	isRollCall,
	namedVoteEntries,
	partiesOf,
	recordNamedVotes,
	refusedNames,
} from '../../src/utils/namedVotes.js'

const posted = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: async (url, body) => {
			posted.push({ url, body })
			return { data: { votingRoundId: 'r-1', recorded: body.entries.length } }
		},
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url.replace('{id}', params.id),
}))

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

const MEMBERS = [
	{ id: 'p-1', displayName: 'Doornbos', party: 'GroenLinks', leftAt: '' },
	{ id: 'p-2', displayName: 'Bakker', party: 'VVD', leftAt: '' },
	{ id: 'p-3', displayName: 'Yilmaz', party: 'GroenLinks', leftAt: '' },
	{ id: 'p-4', displayName: 'De Wit', party: 'VVD', leftAt: '2026-03-01T10:00:00Z' },
	{ id: 'p-5', displayName: 'Jansen', party: '' },
]

beforeEach(() => {
	posted.length = 0
})

describe('roll call round', () => {
	it('is told apart by its voting method only', () => {
		expect(isRollCall({ votingMethod: 'roll-call' })).toBe(true)
		expect(isRollCall({ votingMethod: 'show-of-hands' })).toBe(false)
		expect(isRollCall({ votingMethod: 'for-against-abstain' })).toBe(false)
		expect(isRollCall(null)).toBe(false)
	})
})

describe('the entry sheet', () => {
	it('lists the members who have not left, in order', () => {
		expect(activeMembers(MEMBERS).map((m) => m.id)).toEqual([
			'p-1',
			'p-2',
			'p-3',
			'p-5',
		])
		expect(activeMembers(null)).toEqual([])
		expect(activeMembers([{ displayName: 'no id' }])).toEqual([])
	})

	it('offers each party once, and none for a member without one', () => {
		expect(partiesOf(activeMembers(MEMBERS))).toEqual(['GroenLinks', 'VVD'])
	})

	it('sets one party to a choice and leaves the other members alone', () => {
		const members = activeMembers(MEMBERS)
		const choices = applyPartyChoice(
			{ 'p-2': 'against' },
			members,
			'GroenLinks',
			'for',
		)
		expect(choices).toEqual({ 'p-1': 'for', 'p-2': 'against', 'p-3': 'for' })
	})

	it('clears a party when its choice is not a vote value', () => {
		const members = activeMembers(MEMBERS)
		expect(
			applyPartyChoice({ 'p-1': 'for', 'p-3': 'for' }, members, 'GroenLinks', 'maybe'),
		).toEqual({ 'p-1': '', 'p-3': '' })
	})

	it('does not change the map it was given', () => {
		const before = { 'p-1': 'against' }
		applyPartyChoice(before, activeMembers(MEMBERS), 'GroenLinks', 'for')
		expect(before).toEqual({ 'p-1': 'against' })
	})

	it('sends one entry per listed member with a vote, in sheet order', () => {
		const members = activeMembers(MEMBERS)
		expect(
			namedVoteEntries(
				{ 'p-3': 'against', 'p-1': 'for', 'p-2': '', 'p-5': 'maybe', 'p-4': 'for' },
				members,
			),
		).toEqual([
			{ participantId: 'p-1', value: 'for' },
			{ participantId: 'p-3', value: 'against' },
		])
	})

	it('names the refused members, falling back to the id of an unknown one', () => {
		expect(
			refusedNames(
				{ message: 'x', participantIds: ['p-3', 'ghost'] },
				activeMembers(MEMBERS),
			),
		).toEqual(['Yilmaz', 'ghost'])
		expect(refusedNames({ message: 'closed' }, MEMBERS)).toEqual([])
		expect(refusedNames(null, MEMBERS)).toEqual([])
	})
})

describe('saving the sheet', () => {
	it('posts the entries to the round named-votes endpoint', async () => {
		const entries = [{ participantId: 'p-1', value: 'for' }]
		const result = await recordNamedVotes('r-1', entries)
		expect(posted).toEqual([
			{
				url: '/apps/decidiq/api/voting-rounds/r-1/named-votes',
				body: { entries },
			},
		])
		expect(result).toEqual({ votingRoundId: 'r-1', recorded: 1 })
		expect(read('appinfo', 'routes.php')).toMatch(
			/'url' => '\/api\/voting-rounds\/\{id\}\/named-votes',\s+'verb' => 'POST'/,
		)
	})

	it('sends when the vote was held only when it is known', async () => {
		await recordNamedVotes('r-2', [], '2026-03-12T21:40:00+01:00')
		expect(posted[0].body).toEqual({
			entries: [],
			heldAt: '2026-03-12T21:40:00+01:00',
		})
	})
})

describe('the voting round panel', () => {
	const panel = read('src', 'components', 'VotingRoundPanel.vue')

	it('offers roll call when a round is opened', () => {
		expect(panel).toContain('<option value="roll-call">')
	})

	it('hides the cast buttons on a roll call and shows a notice instead', () => {
		const buttons = panel.slice(
			panel.indexOf('class="decidiq-vote-buttons"') - 250,
			panel.indexOf('class="decidiq-vote-buttons"'),
		)
		expect(buttons).toContain('!isRollCallRound')
		expect(panel).toMatch(/v-if="isRoundOpen && isRollCallRound"\s+class="decidiq-roll-call-notice"/)
	})

	it('opens the entry sheet in a modal of its own for those who may enter a tally', () => {
		expect(panel).toMatch(
			/v-if="isRoundOpen && isRollCallRound && permissions\.canEnterTally"/,
		)
		expect(panel).toContain('<RecordedVotesModal')
		const modal = read('src', 'modals', 'RecordedVotesModal.vue')
		expect(modal).toContain('recordNamedVotes')
		// Every NcSelect carries a real label (WCAG 1.3.1, 4.1.2).
		const selects = modal.match(/<NcSelect[\s\S]*?\/>/g) || []
		expect(selects.length).toBeGreaterThan(0)
		for (const select of selects) expect(select).toContain(':inputLabel=')
	})

	it('keeps the paper vote entry off a roll call round', () => {
		const canEnter = panel.slice(panel.indexOf('canEnterPaperVotes() {'))
		expect(canEnter.slice(0, 300)).toContain('!isRollCall(round)')
	})
})
