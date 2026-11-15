/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sending a proposal along an approval route from its page (REQ-RSPR-001):
 * the dialog lists the active decision routes with the default first, posts
 * the chosen route to decidiq's own controller for the decision, and shows
 * the engine's refusal as it comes back; the decision page carries the
 * widget. Component mounting is absent in this repo's vitest, so the logic
 * lives in src/services/approvalRouteStart.js and the wiring is asserted on
 * the manifest and the registry source.
 *
 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
 */

import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	activeRoutesFor,
	failureMessage,
	sendAlongRoute,
	sortRoutes,
} from '../../src/services/approvalRouteStart.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (match, key) => params[key]),
}))

const read = (relative) => readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')

describe('send for approval', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('lists only the active decision routes, the default first and then by name', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ id: 'r3', name: 'Zonder secretaris', subjectType: 'decision', active: true },
					{ id: 'r1', name: 'Wethouder dan Secretaris', subjectType: 'decision', active: true, isDefault: true },
					{ id: 'r2', name: 'Uit gebruik', subjectType: 'decision', active: false },
					{ id: 'r4', name: 'Voor documenten', subjectType: 'document', active: true },
					{ id: 'r5', name: 'Alfabetisch eerst', subjectType: 'decision', active: true },
				],
			},
		})

		const routes = await activeRoutesFor('decision')

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/decidiq/approval-route',
			{ params: { subjectType: 'decision', _limit: 100 } },
		)
		expect(routes.map((route) => route.id)).toEqual(['r1', 'r5', 'r3'])
	})

	it('reads a plain list answer too, and an empty one as none', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 'r1', name: 'A', subjectType: 'decision', active: true }] })
		expect((await activeRoutesFor('decision')).map((route) => route.id)).toEqual(['r1'])

		axios.get.mockResolvedValueOnce({ data: null })
		expect(await activeRoutesFor('decision')).toEqual([])
	})

	it('sorts without changing the list it was given', () => {
		const routes = [{ name: 'B' }, { name: 'A', isDefault: true }]
		expect(sortRoutes(routes).map((route) => route.name)).toEqual(['A', 'B'])
		expect(routes.map((route) => route.name)).toEqual(['B', 'A'])
	})

	it("starts the chosen route on the decision through decidiq's controller", async () => {
		const route = { id: 'r1', name: 'Wethouder dan Secretaris', steps: [{ order: 1 }, { order: 2 }] }
		axios.post.mockResolvedValue({ data: { stages: [{ id: 's1', status: 'active' }] } })

		const stages = await sendAlongRoute(route, 'dec-1')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/decidiq/api/approval-routes/instantiate',
			{ route, subject: 'dec-1', subjectSchema: 'decision' },
		)
		expect(stages).toEqual([{ id: 's1', status: 'active' }])
	})

	it('answers no stages when the engine returned none', async () => {
		axios.post.mockResolvedValue({ data: {} })
		expect(await sendAlongRoute({ id: 'r1' }, 7)).toEqual([])
		expect(axios.post.mock.calls[0][1].subject).toBe('7')
	})

	it("shows the engine's refusal, or the fallback sentence without one", () => {
		const refused = { response: { data: { message: 'You may not start a route on this decision' } } }
		expect(failureMessage(refused, 'Fallback')).toBe('You may not start a route on this decision')
		expect(failureMessage(new Error('network'), 'Fallback')).toBe('Fallback')
		expect(failureMessage({ response: { data: { message: '' } } }, 'Fallback')).toBe('Fallback')
	})

	it('puts the approval widget on the decision page, wired to a registered component', () => {
		const manifest = JSON.parse(read('../../src/manifest.json'))
		const page = manifest.pages.find((candidate) => candidate.id === 'DecisionDetail')
		const widget = page.config.widgets.find((candidate) => candidate.id === 'decision-approval')

		expect(widget).toMatchObject({ type: 'custom', component: 'DecisionApprovalTab', title: 'Approval route' })
		expect(page.slots['widget-decision-approval']).toBe('DecisionApprovalTab')
		expect(page.config.layout.some((row) => row.widgetId === 'decision-approval')).toBe(true)
		const ids = page.config.layout.map((row) => row.id)
		expect(new Set(ids).size).toBe(ids.length)
		expect(read('../../src/registry.js')).toContain('DecisionApprovalTab: page(DecisionApprovalTab)')
	})

	it('has its strings in English and Dutch', () => {
		const en = JSON.parse(read('../../l10n/en.json')).translations
		const nl = JSON.parse(read('../../l10n/nl.json')).translations
		for (const text of [
			'Send for approval',
			'Loading routes…',
			'There is no active approval route for decisions.',
			'The approval routes could not be loaded.',
			'The proposal could not be sent for approval.',
			'Approval route',
		]) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
			expect(nl[text], text).not.toBe(text)
		}
	})
})
