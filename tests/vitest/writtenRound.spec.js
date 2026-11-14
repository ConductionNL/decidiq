/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))

const { default: axios } = await import('@nextcloud/axios')
const {
	answerWrittenRound,
	canPutInWriting,
	latestWrittenRound,
	openWrittenRound,
	presidingBodyIds,
	progressOf,
	recordRows,
	refusalOf,
	waitsToClose,
} = await import('../../src/utils/writtenRound.js')

const round = {
	id: 'r-1',
	procedure: 'written',
	openedAt: '2026-03-09T09:00:00Z',
	votingDeadline: '2026-03-13T16:00:00Z',
	closedAt: null,
	electorate: [
		{ membership: 'm-janneke', person: 'p-janneke', userId: 'janneke' },
		{ membership: 'm-jan', person: 'p-jan', userId: 'jan' },
		{ membership: 'm-mark', person: 'p-mark', userId: 'mark' },
	],
}

describe('writtenRound', () => {
	beforeEach(() => axios.post.mockReset())

	it('finds the bodies a person presides over today', () => {
		const memberships = [
			{ role: 'secretary', governanceBody: 'board', startDate: '2025-01-01' },
			{ role: 'chair', governanceBody: { id: 'board' } },
			{ role: 'member', governanceBody: 'council' },
			{ role: 'chair', governanceBody: 'old', endDate: '2026-03-01' },
			{ role: 'vice-chair', governanceBody: 'later', startDate: '2026-04-01' },
		]
		expect(presidingBodyIds(memberships, '2026-03-09')).toEqual(['board'])
	})

	it('puts only a proposed or deliberating decision without a meeting in writing', () => {
		expect(canPutInWriting({ lifecycle: 'proposed' })).toBe(true)
		expect(canPutInWriting({ lifecycle: 'deliberating', meeting: '' })).toBe(true)
		expect(canPutInWriting({ lifecycle: 'proposed', meeting: { id: 'm-1' } })).toBe(false)
		expect(canPutInWriting({ lifecycle: 'decided' })).toBe(false)
		expect(canPutInWriting(null)).toBe(false)
	})

	it('picks the latest written round', () => {
		const rounds = [{ id: 'meeting', openedAt: '2026-04-01' }, round, { id: 'r-0', procedure: 'written', openedAt: '2026-01-01' }]
		expect(latestWrittenRound(rounds).id).toBe('r-1')
		expect(latestWrittenRound([])).toBeNull()
	})

	it('lists every member with their answer or none, and the progress', () => {
		const votes = [
			{ membership: 'm-janneke', value: 'abstain', castAt: '2026-03-09T10:00:00Z' },
			{ membership: 'm-janneke', value: 'for', castAt: '2026-03-09T11:30:00Z' },
			{ membership: { id: 'm-mark' }, value: 'against', castAt: '2026-03-10T08:00:00Z' },
			{ value: 'for' },
		]
		const rows = recordRows(round, votes, { 'p-janneke': 'Janneke de Bruin' })
		expect(rows).toEqual([
			{ membership: 'm-janneke', userId: 'janneke', name: 'Janneke de Bruin', value: 'for', castAt: '2026-03-09T11:30:00Z' },
			{ membership: 'm-jan', userId: 'jan', name: 'jan', value: null, castAt: null },
			{ membership: 'm-mark', userId: 'mark', name: 'mark', value: 'against', castAt: '2026-03-10T08:00:00Z' },
		])
		expect(progressOf(rows)).toEqual({ answered: 2, total: 3 })
	})

	it('says an open round past its deadline waits to close', () => {
		expect(waitsToClose(round, new Date('2026-03-13T15:59:00Z'))).toBe(false)
		expect(waitsToClose(round, new Date('2026-03-13T16:00:00Z'))).toBe(true)
		expect(waitsToClose({ ...round, closedAt: '2026-03-13T16:05:00Z' }, new Date('2026-03-14T00:00:00Z'))).toBe(false)
	})

	it('opens a written round and answers it through the voting routes', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'r-1' } })
		await expect(openWrittenRound({ decisionId: 'd-1', governanceBodyId: 'board', votingDeadline: '2026-03-13T16:00:00Z', writtenRule: 'unanimous' })).resolves.toEqual({ id: 'r-1' })
		expect(axios.post).toHaveBeenLastCalledWith('/apps/decidiq/api/voting-rounds', {
			decisionId: 'd-1',
			governanceBodyId: 'board',
			votingDeadline: '2026-03-13T16:00:00Z',
			writtenRule: 'unanimous',
			procedure: 'written',
		})

		axios.post.mockResolvedValueOnce({ data: { vote: { value: 'objection' }, round } })
		await answerWrittenRound('r-1', 'objection')
		expect(axios.post).toHaveBeenLastCalledWith('/apps/decidiq/api/voting-rounds/r-1/cast', { value: 'objection' })
	})

	it('shows the server message of a refusal', () => {
		expect(refusalOf({ response: { data: { message: 'You are not one of the members this decision was put to.' } } }, 'x')).toBe('You are not one of the members this decision was put to.')
		expect(refusalOf({}, 'fallback')).toBe('fallback')
	})
})
