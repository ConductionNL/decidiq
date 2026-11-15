/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decisions leaf's reads and its decision-type vocabulary, in every
 * shape OpenRegister can answer in. Pinned before the helpers were split
 * into smaller steps.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md
 */

import axios from '@nextcloud/axios'
import { describe, expect, it, vi } from 'vitest'
import { listDecisionStages } from '../../src/integrations/decisionCard.js'
import {
	FALLBACK_DECISION_TYPES,
	listHostDecisions,
	withDecisionTypeVocabulary,
} from '../../src/integrations/decisionLink.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
}))

describe('listHostDecisions', () => {
	it('asks nothing without a subject', async () => {
		axios.get.mockReset()
		expect(await listHostDecisions('')).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads by subject with the default limit, bare or under results', async () => {
		axios.get.mockReset()
		axios.get.mockResolvedValueOnce({ data: [{ id: 'a' }] })
		expect(await listHostDecisions('s1')).toEqual([{ id: 'a' }])
		expect(axios.get.mock.calls[0][1]).toEqual({
			params: { subjectId: 's1', _limit: 100 },
		})
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'b' }] } })
		expect(await listHostDecisions('s1', 3)).toEqual([{ id: 'b' }])
		expect(axios.get.mock.calls[1][1].params._limit).toBe(3)
	})

	it('answers [] to any other shape', async () => {
		axios.get.mockReset()
		axios.get.mockResolvedValueOnce({ data: { results: {} } })
		expect(await listHostDecisions('s1')).toEqual([])
		axios.get.mockResolvedValueOnce(undefined)
		expect(await listHostDecisions('s1')).toEqual([])
	})
})

describe('listDecisionStages', () => {
	it('asks nothing without a decision', async () => {
		axios.get.mockReset()
		expect(await listDecisionStages(null)).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads a bare array, and [] for any other shape', async () => {
		axios.get.mockReset()
		axios.get.mockResolvedValueOnce({ data: [{ id: 's2' }] })
		expect(await listDecisionStages('d-1')).toEqual([{ id: 's2' }])
		expect(axios.get.mock.calls[0][1]).toEqual({
			params: { decision: 'd-1', _limit: 50 },
		})
		axios.get.mockResolvedValueOnce({ data: 'nope' })
		expect(await listDecisionStages('d-1')).toEqual([])
		axios.get.mockResolvedValueOnce(null)
		expect(await listDecisionStages('d-1')).toEqual([])
	})
})

describe('withDecisionTypeVocabulary edges', () => {
	it('hands back schemas it cannot enrich untouched', () => {
		const bare = {}
		const scalarProperties = { properties: 'x' }
		expect(withDecisionTypeVocabulary('text', ['motion'])).toBe('text')
		expect(withDecisionTypeVocabulary(bare, ['motion'])).toBe(bare)
		expect(withDecisionTypeVocabulary(scalarProperties, ['motion'])).toBe(
			scalarProperties,
		)
	})

	it('offers the fallback for a non-array vocabulary and keeps the property fields', () => {
		const schema = {
			title: 'Decision',
			properties: { name: { type: 'string' }, decisionType: { type: 'string' } },
		}
		const enriched = withDecisionTypeVocabulary(schema, 'motion')
		expect(enriched).not.toBe(schema)
		expect(enriched.title).toBe('Decision')
		expect(enriched.properties.name).toBe(schema.properties.name)
		expect(enriched.properties.decisionType.type).toBe('string')
		expect(enriched.properties.decisionType.enum).toEqual(FALLBACK_DECISION_TYPES)
		expect(Object.keys(enriched.properties.decisionType.enumLabels).length).toBeGreaterThan(0)
	})
})
