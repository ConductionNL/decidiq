/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the result of a vote per member and per faction (vot-03).
 *
 * The votes widget used to read `caster` off each ballot, a field castVote()
 * never writes (the voter sits only in the ballot's relations), and fetched a
 * round's ballots with a `votingRound` filter no ballot matches. The server
 * now answers GET /api/voting-rounds/{id}/breakdown; these tests hold the
 * rows the widgets build from that answer, and the wiring, asserted against
 * the sources since this repo's vitest cannot mount a `.vue` file.
 *
 * @spec openspec/changes/voting-results-by-faction-and-member/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	breakdownUrl,
	factionRows,
	memberRows,
} from '../../src/utils/voteBreakdown.js'

function read(path) {
	return readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')
}

const openRound = {
	secret: false,
	totals: { for: 3, against: 2, abstain: 0 },
	members: [
		{
			participant: 'p-anna',
			name: 'Anna de Boer',
			faction: 'GroenLinks',
			value: 'for',
			castBy: null,
			castAt: '2026-10-14T20:01:00+02:00',
			ranking: null,
		},
		{
			participant: 'p-cor',
			name: 'Cor Visser',
			faction: 'VVD',
			value: 'against',
			castBy: null,
			castAt: null,
			ranking: null,
		},
		{
			participant: 'p-eva',
			name: 'Eva Mulder',
			faction: 'VVD',
			value: 'for',
			castBy: 'Dirk Jansen',
			castAt: null,
			ranking: null,
		},
	],
	factions: [
		{ faction: 'GroenLinks', for: 1, against: 0, abstain: 0 },
		{ faction: 'VVD', for: 1, against: 1, abstain: 0 },
	],
}

describe('the result per member', () => {
	it('names each voter with their faction and vote', () => {
		const rows = memberRows(openRound, { id: 'round-1' })
		expect(rows.map((r) => [r.voter, r.faction, r.value])).toEqual([
			['Anna de Boer', 'GroenLinks', 'for'],
			['Cor Visser', 'VVD', 'against'],
			['Eva Mulder', 'VVD', 'for'],
		])
		expect(rows[0].id).toBe('round-1:p-anna')
	})

	it('says who cast a proxy vote', () => {
		expect(memberRows(openRound, { id: 'round-1' })[2].castBy).toBe(
			'Dirk Jansen',
		)
	})

	it('shows nobody for a secret round', () => {
		expect(
			memberRows({ ...openRound, secret: true, members: [] }, { id: 'r' }),
		).toEqual([])
	})

	it('shows a ranked ballot as its order of preference', () => {
		const ranked = {
			...openRound,
			members: [
				{
					participant: 'p-anna',
					name: 'Anna de Boer',
					faction: 'GroenLinks',
					value: 'ranked',
					ranking: ['b', 'a'],
				},
			],
		}
		const round = {
			id: 'round-2',
			options: [
				{ key: 'a', label: 'Plan A' },
				{ key: 'b', label: 'Plan B' },
			],
		}
		expect(memberRows(ranked, round)[0].rankingText).toContain('Plan B')
	})
})

describe('the result per faction', () => {
	it('gives one line per faction with its counts', () => {
		expect(factionRows(openRound)).toEqual([
			{
				id: 'GroenLinks',
				faction: 'GroenLinks',
				for: 1,
				against: 0,
				abstain: 0,
			},
			{ id: 'VVD', faction: 'VVD', for: 1, against: 1, abstain: 0 },
		])
	})

	it('gives nothing for a secret round', () => {
		expect(factionRows({ secret: true, factions: [] })).toEqual([])
	})
})

describe('the widgets', () => {
	it('ask the server for the breakdown of each round', () => {
		expect(breakdownUrl('round-1')).toBe(
			'/apps/decidiq/api/voting-rounds/round-1/breakdown',
		)
		for (const file of ['MotionVotesTab.vue', 'MeetingVotesTab.vue']) {
			const source = read(`../../src/components/tabs/${file}`)
			expect(source).toContain('breakdownUrl(')
			expect(source).toContain('factionRows(')
		}
	})

	it('no longer read a caster field or filter ballots on votingRound', () => {
		const source = read('../../src/components/tabs/MotionVotesTab.vue')
		expect(source).not.toContain('row.caster')
		expect(source).not.toContain('votingRound: round.id')
		expect(source).toContain('memberRows(')
	})
})
