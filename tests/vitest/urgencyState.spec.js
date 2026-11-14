/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the urgency banner shows, and the two urgent procedure calls.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-006-awaiting-ratification-indicators-and-dashboard-kpi
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { postUrgentAction, urgencyState } from '../../src/services/urgency.js'

const RATIFYING = { id: 'stage-2', stageType: 'ratifying', status: 'pending', assignedBody: 'body-raad' }

describe('urgencyState', () => {
	it('is not urgent for an ordinary decision', () => {
		expect(urgencyState({ isUrgent: false }, [RATIFYING])).toEqual({ urgent: false, awaiting: false, stage: RATIFYING, placementPending: false })
		expect(urgencyState(null).urgent).toBe(false)
	})

	it('follows the materialised awaitingRatification when present', () => {
		expect(urgencyState({ isUrgent: true, awaitingRatification: false }, [RATIFYING]).awaiting).toBe(false)
		expect(urgencyState({ isUrgent: true, awaitingRatification: true }, []).awaiting).toBe(true)
	})

	it('derives awaiting from the route when the field is absent', () => {
		expect(urgencyState({ isUrgent: true }, [{ stageType: 'decisive', status: 'decided' }, RATIFYING]).awaiting).toBe(true)
		expect(urgencyState({ isUrgent: true }, [{ ...RATIFYING, status: 'decided' }]).awaiting).toBe(false)
		expect(urgencyState({ isUrgent: true }, [{ ...RATIFYING, status: 'skipped' }]).awaiting).toBe(false)
		expect(urgencyState({ isUrgent: true }, []).awaiting).toBe(false)
	})

	it('reports a pending placement only while ratification is awaited', () => {
		expect(urgencyState({ isUrgent: true }, [{ ...RATIFYING, placementPending: true }]).placementPending).toBe(true)
		expect(urgencyState({ isUrgent: true }, [{ ...RATIFYING, status: 'decided', placementPending: true }]).placementPending).toBe(false)
	})
})

describe('postUrgentAction', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('posts the body as JSON with the request token and returns the answer', async () => {
		const fetch = vi.fn(async () => ({ ok: false, json: async () => ({ message: 'Only the chair may declare this decision urgent.' }) }))
		vi.stubGlobal('fetch', fetch)
		vi.stubGlobal('OC', { requestToken: 'tok' })

		const result = await postUrgentAction('dec 1', 'urgency', { reason: 'Spoed' })

		expect(result).toEqual({ ok: false, data: { message: 'Only the chair may declare this decision urgent.' } })
		const [url, init] = fetch.mock.calls[0]
		expect(url).toContain('/apps/decidiq/api/decisions/dec%201/urgency')
		expect(init.method).toBe('POST')
		expect(init.headers.requesttoken).toBe('tok')
		expect(JSON.parse(init.body)).toEqual({ reason: 'Spoed' })
	})

	it('answers an empty body when the response is not JSON', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => { throw new Error('no json') } })))

		expect(await postUrgentAction('dec-1', 'expedited-round', { responseDeadlineHours: 24 })).toEqual({ ok: true, data: {} })
	})
})
