// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// Screens that list votes show a ranked ballot as its ordered options
// (issue #1419, REQ-RPB-002), and any other vote as before.
// @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-rpb-002-screens-that-list-votes-show-a-ranked-ballot-as-its-ranking

import { describe, expect, it } from 'vitest'
import { rankingText, withRankingText } from '../../src/utils/rankedBallot.js'

const round = {
	votingMethod: 'ranked-choice',
	options: [
		{ key: 'renoveren', label: 'Renovate the clubhouse' },
		{ key: 'nieuwbouw', label: 'Build a new clubhouse' },
		{ key: 'huren', label: 'Rent a hall' },
	],
}

describe('rankingText', () => {
	it('reads a ranked ballot as its ordered option labels', () => {
		const vote = {
			value: 'ranked',
			ranking: ['nieuwbouw', 'renoveren', 'huren'],
		}
		expect(rankingText(vote, round)).toBe(
			'1. Build a new clubhouse, 2. Renovate the clubhouse, 3. Rent a hall',
		)
	})

	it('leaves any other vote alone', () => {
		expect(rankingText({ value: 'for' }, round)).toBe('')
		expect(withRankingText([{ value: 'for' }], round)).toEqual([
			{ value: 'for' },
		])
	})

	it('falls back to the key when the round has no label for it', () => {
		expect(rankingText({ value: 'ranked', ranking: ['x'] }, {})).toBe('1. x')
	})
})
