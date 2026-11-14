// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => path.replace('{bodyId}', params.bodyId).replace('{action}', params.action),
}))

const { rosterRows, selectAll, confirmPayload, canConfirm, postTurnover } = await import('../../src/utils/rosterTurnover.js')

describe('rosterTurnover', () => {
	beforeEach(() => {
		globalThis.fetch = vi.fn()
	})

	it('keeps only rows with an email, trimmed', () => {
		expect(rosterRows([
			{ name: ' Eva ', email: ' eva@example.org ', role: 'member', line: 2 },
			{ name: 'Nobody', email: '', role: '', line: 3 },
		])).toEqual([{ name: 'Eva', email: 'eva@example.org', role: 'member' }])
	})

	it('starts with every suggestion selected', () => {
		expect(selectAll({ joining: [{ key: 'email:eva@example.org' }], leaving: [{ membershipId: 'm-1' }] }))
			.toEqual({ joining: ['email:eva@example.org'], leaving: ['m-1'] })
		expect(selectAll(null)).toEqual({ joining: [], leaving: [] })
	})

	it('sends no end date when nobody leaves', () => {
		const payload = confirmPayload([], { joining: ['k'], leaving: [] }, { batch: ' intake ', endDate: '2026-11-30' })
		expect(payload).toEqual({ rows: [], joining: ['k'], leaving: [], batch: 'intake', endDate: '', endReason: '' })
	})

	it('needs a selection, a batch label, and an end date for leavers', () => {
		expect(canConfirm({ joining: [], leaving: [] }, { batch: 'b', endDate: '' })).toBe(false)
		expect(canConfirm({ joining: ['k'], leaving: [] }, { batch: ' ', endDate: '' })).toBe(false)
		expect(canConfirm({ joining: [], leaving: ['m'] }, { batch: 'b', endDate: '' })).toBe(false)
		expect(canConfirm({ joining: [], leaving: ['m'] }, { batch: 'b', endDate: '2026-11-30' })).toBe(true)
		expect(canConfirm({ joining: ['k'], leaving: [] }, { batch: 'b', endDate: '' })).toBe(true)
	})

	it('posts to the body endpoint and surfaces the server message on failure', async () => {
		globalThis.fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ joining: [] }) })
		await expect(postTurnover('body-1', 'suggestions', { rows: [] })).resolves.toEqual({ joining: [] })
		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/apps/decidiq/api/governance-bodies/body-1/turnover/suggestions',
			expect.objectContaining({ method: 'POST', body: '{"rows":[]}' }),
		)

		globalThis.fetch.mockResolvedValueOnce({ ok: false, status: 403, json: async () => ({ message: 'Only the secretariat of this body may do this.' }) })
		await expect(postTurnover('body-1', 'confirm', {})).rejects.toThrow('Only the secretariat of this body may do this.')
	})
})
