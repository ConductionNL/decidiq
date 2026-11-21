/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The approval chain leaf's read helpers: how a row's id is found, how the
 * stages, actions and mandates are read whatever shape OpenRegister answers
 * in, and when a step renders overdue. Pinned before the helpers were split
 * into smaller steps.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	isOverdue,
	listActions,
	listMandates,
	listStages,
	objId,
} from '../../src/integrations/approvalChainLink.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

describe('objId', () => {
	it('reads id, then @self.id, then uuid, else empty', () => {
		expect(objId(null)).toBe('')
		expect(objId({ id: 7, uuid: 'u' })).toBe('7')
		expect(objId({ '@self': { id: 's' }, uuid: 'u' })).toBe('s')
		expect(objId({ '@self': {}, uuid: 'u' })).toBe('u')
		expect(objId({})).toBe('')
	})
})

describe('listStages', () => {
	beforeEach(() => axios.get.mockReset())

	it('asks nothing without a subject', async () => {
		expect(await listStages('')).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('filters on the decision back-reference and sorts by sequence', async () => {
		axios.get.mockResolvedValue({
			data: { results: [{ id: 'b', sequence: 2 }, { id: 'x' }, { id: 'a', sequence: '1' }] },
		})
		const rows = await listStages('s1', 5)
		expect(axios.get.mock.calls[0][1]).toEqual({
			params: { decision: 's1', _limit: 5 },
		})
		expect(rows.map((r) => r.id)).toEqual(['x', 'a', 'b'])
	})

	it('accepts a bare array and answers [] to any other shape', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 'a' }] })
		expect((await listStages('s1')).map((r) => r.id)).toEqual(['a'])
		expect(axios.get.mock.calls[0][1].params._limit).toBe(100)
		axios.get.mockResolvedValueOnce({ data: { results: 'nope' } })
		expect(await listStages('s1')).toEqual([])
		axios.get.mockResolvedValueOnce(null)
		expect(await listStages('s1')).toEqual([])
	})
})

describe('listActions', () => {
	beforeEach(() => axios.get.mockReset())

	it('asks nothing without a subject', async () => {
		expect(await listActions(undefined)).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('filters on the subject and sorts oldest first, undated first', async () => {
		axios.get.mockResolvedValue({
			data: [
				{ id: 'late', recordedAt: '2026-02-01' },
				{ id: 'none' },
				{ id: 'early', recordedAt: '2026-01-01' },
			],
		})
		const rows = await listActions('s1')
		expect(axios.get.mock.calls[0][1]).toEqual({
			params: { subject: 's1', _limit: 200 },
		})
		expect(rows.map((r) => r.id)).toEqual(['none', 'early', 'late'])
	})

	it('answers [] to an unknown shape', async () => {
		axios.get.mockResolvedValue({ data: {} })
		expect(await listActions('s1')).toEqual([])
	})
})

describe('listMandates', () => {
	beforeEach(() => axios.get.mockReset())

	it('asks nothing without a user', async () => {
		expect(await listMandates('')).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('answers [] when the register answers nothing usable', async () => {
		axios.get.mockResolvedValue({ data: null })
		expect(await listMandates('bob', new Date('2026-09-27T12:00:00Z'))).toEqual([])
		expect(axios.get.mock.calls[0][1]).toEqual({
			params: { delegatePerson: 'bob', status: 'effective', _limit: 50 },
		})
	})
})

describe('isOverdue', () => {
	const now = new Date('2026-05-10T12:00:00Z')

	it('is false for no stage, a non-active stage, no due date or a bad date', () => {
		expect(isOverdue(null, now)).toBe(false)
		expect(isOverdue({ status: 'pending', dueAt: '2026-01-01' }, now)).toBe(false)
		expect(isOverdue({ status: 'active' }, now)).toBe(false)
		expect(isOverdue({ status: 'active', dueAt: 'soon' }, now)).toBe(false)
	})

	it('compares the due date with the clock', () => {
		expect(isOverdue({ status: 'active', dueAt: '2026-05-09' }, now)).toBe(true)
		expect(isOverdue({ status: 'active', dueAt: '2026-05-11' }, now)).toBe(false)
		expect(isOverdue({ status: 'active', dueAt: '2000-01-01' })).toBe(true)
	})
})
