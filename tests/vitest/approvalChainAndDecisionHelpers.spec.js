// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The pure helpers the approval-chain and decisions leaves draw from: which
// actions belong to which step, which stage is live, whose turn it is, and
// which bucket a decision is shown in.

import { describe, expect, it, vi } from 'vitest'
import {
	actionsByStep,
	isCurrentActor,
	liveStage,
} from '../../src/integrations/approvalChainLink.js'
import { decisionBucket, KIND_GROUPS } from '../../src/integrations/decisionLink.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

describe('actionsByStep', () => {
	it('groups the actions per step number, keeping their order', () => {
		const a = { step: 1, verb: 'approve' }
		const b = { step: '2', verb: 'reject' }
		const c = { step: '1', verb: 'comment' }
		const d = { verb: 'note' }

		expect(actionsByStep([a, b, c, d])).toEqual({ 1: [a, c], 2: [b], 0: [d] })
	})

	it('has no steps for no actions', () => {
		expect(actionsByStep([])).toEqual({})
		expect(actionsByStep(null)).toEqual({})
	})
})

describe('liveStage', () => {
	it('is the active stage, before any pending one', () => {
		const stages = [
			{ step: 1, status: 'completed' },
			{ step: 2, status: 'pending' },
			{ step: 3, status: 'active' },
		]

		expect(liveStage(stages)).toBe(stages[2])
	})

	it('is the first pending stage when none is active', () => {
		const stages = [{ step: 1, status: 'completed' }, { step: 2, status: 'pending' }, { step: 3, status: 'pending' }]

		expect(liveStage(stages)).toBe(stages[1])
	})

	it('is null once every stage is finished, or without stages', () => {
		expect(liveStage([{ status: 'completed' }, {}])).toBeNull()
		expect(liveStage([])).toBeNull()
		expect(liveStage(undefined)).toBeNull()
	})
})

describe('isCurrentActor', () => {
	const stage = { assignedPerson: 'alice', substituteActor: 'bob' }

	it('is the assigned person or the substitute', () => {
		expect(isCurrentActor(stage, 'alice')).toBe(true)
		expect(isCurrentActor(stage, 'bob')).toBe(true)
	})

	it('is nobody else, and nobody without a stage or a user', () => {
		expect(isCurrentActor(stage, 'carol')).toBe(false)
		expect(isCurrentActor({}, 'carol')).toBe(false)
		expect(isCurrentActor(null, 'alice')).toBe(false)
		expect(isCurrentActor(stage, '')).toBe(false)
	})
})

describe('decisionBucket', () => {
	it('puts each proposal type under proposals', () => {
		for (const type of KIND_GROUPS.proposals) {
			expect(decisionBucket({ decisionType: type })).toBe('proposals')
		}
	})

	it('puts each advice type under advice, also when the type sits under data', () => {
		for (const type of KIND_GROUPS.advice) {
			expect(decisionBucket({ data: { decisionType: type } })).toBe('advice')
		}
	})

	it('puts every other or missing type under decisions', () => {
		expect(decisionBucket({ decisionType: 'decision' })).toBe('decisions')
		expect(decisionBucket({})).toBe('decisions')
		expect(decisionBucket(null)).toBe('decisions')
	})

	it('prefers the top-level type over the one under data', () => {
		expect(decisionBucket({ decisionType: 'motion', data: { decisionType: 'advice' } })).toBe('proposals')
	})
})
