// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation of the optional-field branches of the participation
 * payload builder and the export filter reader, pinned before they were
 * split into named helpers.
 *
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */
import { describe, expect, it, vi } from 'vitest'
// The store pulls in @nextcloud/vue, which plain vitest cannot resolve; only
// the pure helpers are under test.
vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))

import { buildParticipationPayload } from '../../src/utils/bodyParticipations.js'
import { filterFromQuery } from '../../src/utils/exportBundle.js'

describe('buildParticipationPayload optional fields', () => {
	it('keeps every filled field, truncating seats and trimming the label', () => {
		const payload = buildParticipationPayload({
			sharedBody: 'b',
			participant: 'p',
			seats: '2.9',
			votingWeight: 0,
			accessionDate: new Date(2026, 0, 5, 13, 30),
			exitDate: '2026-02-01',
			label: '  Oss  ',
			id: 'x1',
		})
		expect(payload).toEqual({
			sharedBody: 'b',
			participant: 'p',
			seats: 2,
			votingWeight: 0,
			accessionDate: new Date(2026, 0, 5).toISOString(),
			exitDate: new Date(2026, 1, 1).toISOString(),
			label: 'Oss',
			id: 'x1',
		})
		expect(Object.keys(payload)).toEqual([
			'sharedBody',
			'participant',
			'seats',
			'votingWeight',
			'accessionDate',
			'exitDate',
			'label',
			'id',
		])
	})

	it('drops non-numeric numbers, blank labels and unparseable dates', () => {
		expect(
			buildParticipationPayload({
				sharedBody: 'b',
				participant: 'p',
				seats: 'abc',
				votingWeight: null,
				accessionDate: 'not a date',
				exitDate: undefined,
				label: '   ',
				id: '',
			}),
		).toEqual({ sharedBody: 'b', participant: 'p' })
	})

	it('accepts the fields missing altogether', () => {
		expect(buildParticipationPayload({ sharedBody: 'b', participant: 'p' })).toEqual({
			sharedBody: 'b',
			participant: 'p',
		})
	})
})

describe('filterFromQuery entry rules', () => {
	it('skips null and undefined values, reserved and underscore keys', () => {
		const { filter, search } = filterFromQuery(
			{ a: null, b: undefined, c: 0, d: false, page: '2', limit: '20', _x: '1', e: 'y' },
			{ a: 'base', e: 'base' },
		)
		expect(filter).toEqual({ a: 'base', c: 0, d: false, e: 'y' })
		expect(Object.keys(filter)).toEqual(['a', 'e', 'c', 'd'])
		expect(search).toBe('')
	})

	it('reads a non-string search as empty and tolerates a missing query', () => {
		expect(filterFromQuery({ _search: 5 }).search).toBe('')
		expect(filterFromQuery(null, { k: 1 })).toEqual({ filter: { k: 1 }, search: '' })
		expect(filterFromQuery()).toEqual({ filter: {}, search: '' })
	})
})
