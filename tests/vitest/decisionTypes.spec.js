// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the decision-type picker wiring in
// src/integrations/decisionLink.js.
//
// The defect these pin: the create-proposal pickers hardcoded five decision
// types, so a type an administrator added to the `decision_types` app config
// validated fine at the write path and never appeared in any picker. The
// picker schema must be built from the REGISTRY's answer, an admin-added type
// must appear in it, and an unreachable registry must degrade to the shipped
// seed instead of blocking proposal creation.
//
// @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	decisionTypeLabels,
	FALLBACK_DECISION_TYPES,
	listDecisionTypes,
	proposalFormSchema,
} from '../../src/integrations/decisionLink.js'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => get(...a),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))

describe('listDecisionTypes', () => {
	beforeEach(() => {
		get.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('returns the registry vocabulary, admin-added types included', async () => {
		get.mockResolvedValueOnce({
			data: { types: ['motion', 'advice', 'subsidie-besluit'] },
		})

		const types = await listDecisionTypes()

		expect(get).toHaveBeenCalledTimes(1)
		expect(String(get.mock.calls[0][0])).toContain(
			'/apps/decidiq/api/v1/decision-types',
		)
		expect(types).toEqual(['motion', 'advice', 'subsidie-besluit'])
	})

	it('drops non-string and empty entries from a malformed answer', async () => {
		get.mockResolvedValueOnce({
			data: { types: ['motion', '', 42, null, 'advice'] },
		})

		expect(await listDecisionTypes()).toEqual(['motion', 'advice'])
	})

	it('falls back to the shipped seed when the endpoint is unreachable', async () => {
		get.mockRejectedValueOnce(new Error('offline'))

		expect(await listDecisionTypes()).toEqual(FALLBACK_DECISION_TYPES)
	})

	it('falls back to the shipped seed on an empty vocabulary', async () => {
		get.mockResolvedValueOnce({ data: { types: [] } })

		expect(await listDecisionTypes()).toEqual(FALLBACK_DECISION_TYPES)
	})
})

describe('proposalFormSchema', () => {
	it('offers exactly the given vocabulary in the type picker', () => {
		const schema = proposalFormSchema(['motion', 'advice', 'subsidie-besluit'])

		expect(schema.properties.decisionType.enum).toEqual([
			'motion',
			'advice',
			'subsidie-besluit',
		])
	})

	it('an admin-added type appears even though it has no shipped label', () => {
		const schema = proposalFormSchema([
			...FALLBACK_DECISION_TYPES,
			'subsidie-besluit',
		])

		expect(schema.properties.decisionType.enum).toContain('subsidie-besluit')
		// No label entry: the slug renders as its own name.
		expect(
			schema.properties.decisionType.enumLabels['subsidie-besluit'],
		).toBeUndefined()
	})

	it('defaults to motion when the vocabulary carries it', () => {
		expect(
			proposalFormSchema(['advice', 'motion']).properties.decisionType.default,
		).toBe('motion')
	})

	it('defaults to the first type when motion is absent', () => {
		expect(
			proposalFormSchema(['advice', 'contract']).properties.decisionType
				.default,
		).toBe('advice')
	})

	it('falls back to the shipped seed when handed nothing', () => {
		expect(proposalFormSchema(null).properties.decisionType.enum).toEqual(
			FALLBACK_DECISION_TYPES,
		)
		expect(proposalFormSchema([]).properties.decisionType.enum).toEqual(
			FALLBACK_DECISION_TYPES,
		)
	})

	it('labels every shipped type', () => {
		const labels = decisionTypeLabels()
		for (const type of FALLBACK_DECISION_TYPES) {
			expect(labels[type], `label for ${type}`).toBeTruthy()
		}
	})
})
