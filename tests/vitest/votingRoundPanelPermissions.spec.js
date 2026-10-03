// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The voting round panel takes its controls from the server's answer
// (voting-chair-close-and-amendment-rounds, REQ-VCR-001 and REQ-VCR-004).
//
// @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-001-the-meetings-chair-and-secretary-see-the-voting-controls

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	NO_VOTING_PERMISSIONS,
	readVotingPermissions,
	votingPermissionsPath,
	votingRoundBody,
} from '../../src/utils/votingPermissions.js'

const here = dirname(fileURLToPath(import.meta.url))

describe('votingPermissionsPath', () => {
	it('asks per meeting, and the global fallback without one', () => {
		expect(votingPermissionsPath('m-1')).toBe(
			'/apps/decidiq/api/meetings/m-1/voting-permissions',
		)
		expect(votingPermissionsPath('')).toBe(
			'/apps/decidiq/api/voting-permissions',
		)
	})
})

describe('readVotingPermissions', () => {
	it('keeps only a literal true, so a missing or malformed answer hides the controls', () => {
		expect(
			readVotingPermissions({
				canOpen: true,
				canClose: true,
				canEnterTally: 'yes',
				canCastChairVote: 1,
			}),
		).toEqual({
			canOpen: true,
			canClose: true,
			canEnterTally: false,
			canCastChairVote: false,
		})
		expect(readVotingPermissions(null)).toEqual(NO_VOTING_PERMISSIONS)
	})
})

describe('votingRoundBody', () => {
	it('sends the subject type, so an amendment round is an amendment round', () => {
		expect(
			votingRoundBody({
				subjectId: 'a-1',
				subjectType: 'amendment',
				meetingId: 'm-1',
			}),
		).toMatchObject({
			motionId: 'a-1',
			subjectType: 'amendment',
			meetingId: 'm-1',
		})
		expect(votingRoundBody({ subjectId: 'x', meetingId: '' }).subjectType).toBe(
			'motion',
		)
	})
})

describe('VotingRoundPanel source', () => {
	const source = readFileSync(
		resolve(here, '../../src/components/VotingRoundPanel.vue'),
		'utf8',
	)

	it('no longer decides the controls from settingsStore.isAdmin', () => {
		expect(source).not.toMatch(/settingsStore\.isAdmin/)
	})

	it('gates the open button and the casting vote on the server answer', () => {
		expect(source).toMatch(/permissions\.canOpen/)
		expect(source).toMatch(/permissions\.canCastChairVote/)
		expect(source).toMatch(/permissions\.canClose/)
	})
})

describe('amendment voting context', () => {
	it('finds the parent motion through amends, and its meeting', async () => {
		const { meetingIdOf, parentMotionIdOf } =
			await import('../../src/utils/votingPermissions.js')
		expect(parentMotionIdOf({ amends: 'motion-1' })).toBe('motion-1')
		expect(parentMotionIdOf({ amends: { id: 'motion-2' } })).toBe('motion-2')
		expect(parentMotionIdOf({ parentMotion: 'motion-3' })).toBe('motion-3')
		expect(parentMotionIdOf({})).toBe('')
		expect(meetingIdOf({ meeting: 'm-1' })).toBe('m-1')
		expect(meetingIdOf({ meeting: { id: 'm-2' } })).toBe('m-2')
		expect(meetingIdOf(null)).toBe('')
	})
})
