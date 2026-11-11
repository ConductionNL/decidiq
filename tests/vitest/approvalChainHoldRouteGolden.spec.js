/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the approval chain's holdRoute(): the exact request body for
 * a named route with a substitute ask point, for the defaults, and for ask
 * points outside the window, and the stages it reads from each answer shape.
 * Pinned before holdRoute() was split into named steps.
 *
 * @spec exclude golden test of the held-route request, no behaviour of its own
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { holdRoute } from '../../src/integrations/approvalChainLink.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

beforeEach(() => {
	axios.post.mockReset()
})

describe('holdRoute request', () => {
	it('sends the route with its steps, deadline and ask point', async () => {
		axios.post.mockResolvedValue({ data: { stages: [{ id: 's1' }] } })
		const stages = await holdRoute({
			subject: 'doc-1',
			subjectSchema: 'document',
			actors: ['alice', 'bob'],
			deadline: '2026-12-01T00:00:00Z',
			name: 'Paraaf',
			askSubstituteAfter: 0.5,
		})
		expect(stages).toEqual([{ id: 's1' }])
		expect(axios.post.mock.calls).toMatchSnapshot()
		expect(JSON.stringify(axios.post.mock.calls[0][1])).toMatchSnapshot()
	})

	it('falls back to the defaults and ignores ask points outside the window', async () => {
		const bodies = []
		for (const askSubstituteAfter of [undefined, 0, 1.5, '0.5', 1]) {
			axios.post.mockResolvedValue({ data: {} })
			await holdRoute({
				subject: 's',
				subjectSchema: 'x',
				actors: null,
				askSubstituteAfter,
			})
			bodies.push(JSON.stringify(axios.post.mock.calls.at(-1)[1]))
		}
		expect(bodies).toMatchSnapshot()
	})

	it('reads no stages from an answer without a stage list', async () => {
		for (const answer of [null, {}, { data: null }, { data: { stages: 'x' } }]) {
			axios.post.mockResolvedValue(answer)
			expect(
				await holdRoute({ subject: 's', subjectSchema: 'x', actors: ['a'] }),
			).toEqual([])
		}
	})
})
