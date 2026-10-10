/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The consultation Reactions tab lists only that consultation's pending
 * reactions.
 *
 * The tab scoped with `_relations.public-consultation`, which PHP receives as
 * `_relations_public-consultation`. OpenRegister ignored it and answered every
 * pending reaction, so e2e hub.spec.ts found another consultation's reaction
 * on the page. The rows below are the shape that CI run returned.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	pendingReactionsFrom,
	pendingReactionsQuery,
} from '../../src/components/tabs/reactionQueue.js'

const HERE = 'da2e00db-bb51-4241-8481-1db61253aa30'
const ELSEWHERE = 'a4037d86-5e8a-44a3-92b4-5d0f4f0a1c11'

/**
 * A reaction as a collection read returns it.
 *
 * @param {string} id The reaction id.
 * @param {string} consultation The consultation it relates to.
 * @param {string} status Its moderation status.
 *
 * @return {object} The reaction.
 */
function reaction(id, consultation, status = 'pending') {
	return {
		id,
		body: `reaction ${id}`,
		moderationStatus: status,
		'@self': {
			id,
			relations: {
				submitterId: 'citizen',
				'relations.0.id': consultation,
				'relations.0.schema': 'public-consultation',
			},
		},
	}
}

describe('pendingReactionsQuery', () => {
	it('scopes with a key that survives PHP parameter-name mangling', () => {
		const query = pendingReactionsQuery(HERE)
		expect(query).toEqual({
			moderationStatus: 'pending',
			_limit: 200,
			_relations_contains: HERE,
		})
		expect(Object.keys(query).some((key) => key.includes('.'))).toBe(false)
	})

	it('asks for every pending reaction when no consultation is given', () => {
		expect(pendingReactionsQuery('')).toEqual({
			moderationStatus: 'pending',
			_limit: 200,
		})
	})
})

describe('pendingReactionsFrom', () => {
	const unscoped = {
		results: [
			reaction('r-here', HERE),
			reaction('r-elsewhere', ELSEWHERE),
			reaction('r-here-approved', HERE, 'approved'),
		],
	}

	it('keeps only the pending reactions of the consultation, even from an unscoped answer', () => {
		expect(pendingReactionsFrom(unscoped, HERE).map((r) => r.id)).toEqual([
			'r-here',
		])
	})

	it('keeps every pending reaction for the hub-wide queue', () => {
		expect(pendingReactionsFrom(unscoped, '').map((r) => r.id)).toEqual([
			'r-here',
			'r-elsewhere',
		])
	})

	it('reads a bare array and an empty answer', () => {
		expect(
			pendingReactionsFrom([reaction('r-here', HERE)], HERE).map((r) => r.id),
		).toEqual(['r-here'])
		expect(pendingReactionsFrom(null, HERE)).toEqual([])
	})
})
