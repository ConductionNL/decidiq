// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A voting round follows its body's rules unless the chair picks one
// (meeting-rules-from-body-and-type, REQ-MRB-002).
//
// @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { chosenRules } from '../../src/utils/votingPermissions.js'

const here = dirname(fileURLToPath(import.meta.url))

describe('chosenRules', () => {
	it('sends null for a rule left on the body rule', () => {
		expect(
			chosenRules({
				voteThreshold: '',
				abstentionHandling: '',
				tieBreakRule: '',
			}),
		).toEqual({
			voteThreshold: null,
			abstentionHandling: null,
			tieBreakRule: null,
		})
	})

	it('sends what the chair picked', () => {
		expect(
			chosenRules({
				voteThreshold: 'unanimous',
				abstentionHandling: '',
				tieBreakRule: 'revote',
			}),
		).toEqual({
			voteThreshold: 'unanimous',
			abstentionHandling: null,
			tieBreakRule: 'revote',
		})
	})
})

describe('VotingRoundPanel source', () => {
	const source = readFileSync(
		resolve(here, '../../src/components/VotingRoundPanel.vue'),
		'utf8',
	)

	it('no longer opens every round with fixed rules', () => {
		expect(source).not.toMatch(/voteThreshold: 'simple-majority'/)
		expect(source).not.toMatch(/tieBreakRule: 'rejected',\n/)
		expect(source).toMatch(/\.\.\.chosenRules\(this\.newRound\)/)
	})

	it('offers the body rule as the first choice of each rule', () => {
		expect(source.match(/The body's rule/g)).toHaveLength(3)
	})
})
