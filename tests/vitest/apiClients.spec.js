/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the thin API clients in src/services: the action-item,
 * board-evaluation and participation endpoints each call (method, URL,
 * payload) and the data they return; plus the Deck availability probe and
 * the projection paths the projection spec leaves out (items without an id,
 * a card the leaf did not number, and the default write-back).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

import { createActionItem, deleteActionItem, updateActionItem } from '../../src/services/actionItemApi.js'
import {
	closeEvaluation,
	generateEvaluationReport,
	publishEvaluation,
	respondToEvaluation,
} from '../../src/services/boardEvaluationApi.js'
import { isDeckAvailable, projectActionItems } from '../../src/services/deckProjection.js'
import * as participation from '../../src/services/participationApi.js'

beforeEach(() => {
	for (const fn of Object.values(http)) {
		fn.mockReset()
		fn.mockResolvedValue({ data: { ok: true } })
	}
})

describe('actionItemApi', () => {
	it('creates, updates and deletes action items by encoded uid', async () => {
		expect(await createActionItem({ title: 'T' })).toEqual({ ok: true })
		await updateActionItem('a/b', { taskStatus: 'done' })
		await deleteActionItem('a b')

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/decidiq/api/action-items', { title: 'T' })
		expect(http.put).toHaveBeenCalledWith('/index.php/apps/decidiq/api/action-items/a%2Fb', { taskStatus: 'done' })
		expect(http.delete).toHaveBeenCalledWith('/index.php/apps/decidiq/api/action-items/a%20b')
	})
})

describe('boardEvaluationApi', () => {
	it('posts each evaluation step to its endpoint', async () => {
		const base = '/index.php/apps/decidiq/api/board-evaluations/7'

		expect(await respondToEvaluation(7, { q1: 4 })).toEqual({ ok: true })
		await closeEvaluation(7)
		await publishEvaluation(7)
		await generateEvaluationReport(7)

		expect(http.post.mock.calls).toEqual([
			[`${base}/respond`, { answers: { q1: 4 } }],
			[`${base}/close`],
			[`${base}/publish`],
			[`${base}/report`],
		])
	})
})

describe('participationApi', () => {
	it('posts each participation action with its body', async () => {
		const base = '/index.php/apps/decidiq/api/participation'

		await participation.transitionConsultation(1, 'open')
		await participation.submitReaction(1, 'Goed plan')
		await participation.approveReaction(2)
		await participation.approveReaction(2, 'ok')
		await participation.rejectReaction(3, 'spam')
		await participation.publishConsultationResults(1)
		await participation.publishConsultationResults(1, 'Dank')
		await participation.transitionBudgetRound(4, 'voting')
		await participation.submitProposal(4, { title: 'Bankje' })
		await participation.validateProposal(5, true)
		await participation.castAdvisoryVote(5, 1)
		expect(await participation.publishBudgetResults(4)).toEqual({ ok: true })

		expect(http.post.mock.calls).toEqual([
			[`${base}/consultations/1/transition`, { status: 'open' }],
			[`${base}/consultations/1/reactions`, { body: 'Goed plan' }],
			[`${base}/reactions/2/approve`, { reason: '' }],
			[`${base}/reactions/2/approve`, { reason: 'ok' }],
			[`${base}/reactions/3/reject`, { reason: 'spam' }],
			[`${base}/consultations/1/publish`, { staffResponse: '' }],
			[`${base}/consultations/1/publish`, { staffResponse: 'Dank' }],
			[`${base}/budgets/4/transition`, { status: 'voting' }],
			[`${base}/budgets/4/proposals`, { title: 'Bankje' }],
			[`${base}/proposals/5/validate`, { approve: true }],
			[`${base}/proposals/5/vote`, { value: 1 }],
			[`${base}/budgets/4/publish`, {}],
		])
	})
})

describe('deckProjection extras', () => {
	it('reports Deck available when the boards endpoint answers, unavailable on any error', async () => {
		expect(await isDeckAvailable()).toBe(true)
		expect(http.get).toHaveBeenCalledWith(expect.stringMatching(/\/integrations\/deck\/boards$/))

		http.get.mockRejectedValueOnce({ response: { status: 501 } })
		expect(await isDeckAvailable()).toBe(false)
	})

	it('skips items without an id, records a card without a number, and writes back through the action-item API', async () => {
		http.get.mockImplementation(async (url) => {
			if (url.includes('/integrations/deck/default/')) throw new Error('no default')
			if (url.includes('/integrations/deck/boards/')) return { data: { results: [{ id: 11, title: 'Open' }] } }
			if (url.includes('/integrations/deck/boards')) return { data: { results: [{ id: 5, title: 'Board' }] } }
			return { data: { results: [] } }
		})
		http.post.mockResolvedValueOnce({ data: {} }).mockResolvedValueOnce({ data: { cardId: 42 } })

		const result = await projectActionItems({
			register: 'decidiq',
			schema: 'decision',
			objectId: 'o1',
			items: [{ title: 'no id' }, { uuid: 'u1', title: 'A' }, { uuid: 'u2', title: 'B', dueDate: '2027-01-01' }],
		})

		expect(result.created).toBe(1)
		expect(result.errors).toEqual([{ uid: 'u1', error: 'No card id returned' }])
		expect(http.post).toHaveBeenCalledTimes(2)
		expect(http.post.mock.calls[1][1]).toMatchObject({ title: 'B', duedate: '2027-01-01', stackId: 11 })
		expect(http.put).toHaveBeenCalledWith('/index.php/apps/decidiq/api/action-items/u2', { deckCardId: 42 })
	})
})
