/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Signing on someone's behalf and asking a substitute, from the approval
 * chain leaf (#1397).
 *
 * ApprovalStageGuard already accepts an action `onBehalfOf` the assignee
 * under a mandate, and the lapse sweep already asks a step's substitute part
 * way through its term when the step carries `askSubstituteAfter`. No screen
 * sent either. These pin what the leaf's helpers now send, and which
 * mandates the leaf offers. The widget itself cannot be mounted here (no SFC
 * transform), so the logic lives in approvalChainLink.js.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	holdRoute,
	listMandates,
	mayActOnBehalf,
	parseActors,
	recordAction,
	usableMandates,
} from '../../src/integrations/approvalChainLink.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

const TODAY = new Date('2026-09-27T12:00:00Z')

describe('recordAction on behalf of the assignee', () => {
	beforeEach(() => {
		axios.post.mockReset()
		axios.post.mockResolvedValue({ data: { id: 'a1' } })
	})

	it('sends onBehalfOf and the mandate when given', async () => {
		await recordAction({
			subject: 's1',
			subjectSchema: 'decision',
			step: 2,
			action: 'approved',
			onBehalfOf: 'alice',
			mandate: 'm-1',
		})
		const body = axios.post.mock.calls[0][1]
		expect(body.onBehalfOf).toBe('alice')
		expect(body.mandate).toBe('m-1')
	})

	it('sends neither for an ordinary action', async () => {
		await recordAction({
			subject: 's1',
			subjectSchema: 'decision',
			step: 1,
			action: 'approved',
		})
		const body = axios.post.mock.calls[0][1]
		expect(body).not.toHaveProperty('onBehalfOf')
		expect(body).not.toHaveProperty('mandate')
	})
})

describe('the mandates the leaf offers', () => {
	const rows = [
		{
			id: 'm-ok',
			status: 'effective',
			delegatePerson: 'bob',
			validFrom: '2026-01-01',
			validTo: '2026-12-31',
		},
		{ id: 'm-open', status: 'effective', delegatePerson: 'bob' },
		{ id: 'm-draft', status: 'draft', delegatePerson: 'bob' },
		{
			id: 'm-late',
			status: 'effective',
			delegatePerson: 'bob',
			validTo: '2026-09-01',
		},
		{
			id: 'm-early',
			status: 'effective',
			delegatePerson: 'bob',
			validFrom: '2026-10-01',
		},
		{ id: 'm-other', status: 'effective', delegatePerson: 'carol' },
	]

	it('keeps only effective mandates in force for this delegate', () => {
		expect(usableMandates(rows, 'bob', TODAY).map((m) => m.id)).toEqual([
			'm-ok',
			'm-open',
		])
	})

	it('reads them from the register the server checks them against', async () => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: { results: rows } })
		const found = await listMandates('bob', TODAY)
		expect(axios.get.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/decidiq/bevoegdheidstoedeling',
		)
		expect(axios.get.mock.calls[0][1].params).toMatchObject({
			delegatePerson: 'bob',
			status: 'effective',
		})
		expect(found.map((m) => m.id)).toEqual(['m-ok', 'm-open'])
	})

	it('offers signing on behalf only to someone else, on an active step, with a mandate', () => {
		const stage = { status: 'active', assignedPerson: 'alice' }
		expect(mayActOnBehalf(stage, 'bob', [{ id: 'm-ok' }])).toBe(true)
		expect(mayActOnBehalf(stage, 'alice', [{ id: 'm-ok' }])).toBe(false)
		expect(mayActOnBehalf(stage, 'bob', [])).toBe(false)
		expect(
			mayActOnBehalf({ status: 'pending', assignedPerson: 'alice' }, 'bob', [
				{ id: 'm-ok' },
			]),
		).toBe(false)
		expect(
			mayActOnBehalf({ status: 'active', assignedPerson: '' }, 'bob', [
				{ id: 'm-ok' },
			]),
		).toBe(false)
	})
})

describe('holdRoute asking a substitute', () => {
	beforeEach(() => {
		axios.post.mockReset()
		axios.post.mockResolvedValue({ data: { stages: [] } })
	})

	it('sends askSubstituteAfter with the route when chosen', async () => {
		await holdRoute({
			subject: 's1',
			subjectSchema: 'decision',
			actors: ['alice', 'bob'],
			deadline: '2026-10-10T00:00:00Z',
			askSubstituteAfter: 0.5,
		})
		const body = axios.post.mock.calls[0][1]
		expect(body.askSubstituteAfter).toBe(0.5)
		expect(
			body.route.steps.every((step) => step.askSubstituteAfter === 0.5),
		).toBe(true)
	})

	it('sends nothing about a substitute when not chosen', async () => {
		await holdRoute({
			subject: 's1',
			subjectSchema: 'decision',
			actors: ['alice'],
		})
		const body = axios.post.mock.calls[0][1]
		expect(body).not.toHaveProperty('askSubstituteAfter')
		expect(body.route.steps[0]).not.toHaveProperty('askSubstituteAfter')
	})
})

describe('the people a review asks', () => {
	it('reads names in order, drops blanks and repeats', () => {
		expect(parseActors(' alice, bob;\ncarol,, alice ')).toEqual([
			'alice',
			'bob',
			'carol',
		])
		expect(parseActors('')).toEqual([])
	})
})
