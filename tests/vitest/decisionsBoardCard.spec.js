/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decisions leaf as the board card dossiq's DqZaakBesluiten draws: each
 * decision as a row (initials, title, a meta line, a status pill), its route
 * as a step bar (done, current, still to come, each with its line), and the
 * mandate notice that says who may take it.
 *
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
 */

import { describe, expect, it, vi } from 'vitest'
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url, generateOcsUrl: (url, params = {}) => `/ocs/v2.php/${url.replace(/\{(\w+)\}/g, (m, k) => params[k])}` }))

import axios from '@nextcloud/axios'
import {
	decisionAuthority,
	decisionMetaLine,
	drafterName,
	initialsOf,
	listDecisionStages,
	mandateNotice,
	personNames,
	stageSteps,
	userDisplayNames,
} from '../../src/integrations/decisionCard.js'

const day = (iso) => iso.slice(0, 10)

describe('a decision row', () => {
	it('takes the initials of the person who drafted it', () => {
		expect(initialsOf('Pieter Jansen')).toBe('PJ')
		expect(initialsOf('Anouk')).toBe('A')
		expect(initialsOf('')).toBe('')
	})

	it('reads the state, the outcome when there is one, and who drafted it when', () => {
		expect(decisionMetaLine({ state: 'Draft', outcome: 'Partly public', drafter: 'Pieter Jansen', date: '7 Oct' }))
			.toBe('Draft · Partly public · drafted by Pieter Jansen on 7 Oct')
		expect(decisionMetaLine({ state: 'Draft', outcome: '', drafter: '', date: '' })).toBe('Draft')
	})
})

describe('the route as a step bar', () => {
	const people = { p1: 'Pieter Jansen', p2: 'Anouk Bakker' }
	const stages = [
		{ id: 's3', sequence: 3, label: 'Signed', status: 'pending', note: 'mandate Woo decisions' },
		{ id: 's1', sequence: 1, label: 'Drafted', status: 'decided', decidedAt: '2026-10-07T10:00:00Z', assignedPerson: 'p1' },
		{ id: 's2', sequence: 2, label: 'Team lead initials', status: 'active', assignedPerson: 'p2' },
		{ id: 's4', sequence: 4, label: 'Announced', status: 'pending', note: 'objection period starts then' },
	]

	it('orders the stages and marks done, current and still to come', () => {
		const steps = stageSteps(stages, people, day, 'not yet')
		expect(steps.map((step) => [step.label, step.state])).toEqual([
			['Drafted', 'done'],
			['Team lead initials', 'current'],
			['Signed', 'todo'],
			['Announced', 'todo'],
		])
	})

	it('says when and by whom a stage was done, who is still due, and the note of one to come', () => {
		const lines = stageSteps(stages, people, day, 'not yet').map((step) => step.line)
		expect(lines).toEqual(['2026-10-07, Pieter Jansen', 'Anouk Bakker, not yet', 'mandate Woo decisions', 'objection period starts then'])
	})

	it('treats a skipped stage as done and draws nothing for no stages', () => {
		expect(stageSteps([{ id: 'x', sequence: 1, label: 'Advice', status: 'skipped' }], {}, day, 'not yet')[0].state).toBe('done')
		expect(stageSteps(null, {}, day, 'not yet')).toEqual([])
	})
})

describe('the mandate notice', () => {
	it('names the drafter and the mandate when the register holds one', () => {
		expect(mandateNotice({ authorised: true, subject: 'Woo decisions', restrictions: 'Not public: the team lead signs.' }, 'Pieter Jansen'))
			.toEqual({ tone: 'success', drafter: 'Pieter Jansen', allocation: 'Woo decisions', restrictions: 'Not public: the team lead signs.' })
	})

	it('draws nothing when the register does not say yes', () => {
		expect(mandateNotice({ authorised: false, reason: 'no-allocation' }, 'Pieter Jansen')).toBeNull()
		expect(mandateNotice(null, 'Pieter Jansen')).toBeNull()
	})
})

describe('the reads behind the card', () => {
	it('reads the route stages of a decision, and none when they cannot be read', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 's1' }] } })
		expect(await listDecisionStages('d-1')).toEqual([{ id: 's1' }])
		expect(axios.get.mock.calls[0][0]).toBe('/apps/openregister/api/objects/decidiq/decision-stage')
		expect(axios.get.mock.calls[0][1].params).toMatchObject({ decision: 'd-1' })
		axios.get.mockRejectedValueOnce(new Error('403'))
		expect(await listDecisionStages('d-1')).toEqual([])
	})

	it('names each person once and leaves out one it cannot read', async () => {
		axios.get.mockReset()
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/p1')) {
				return { data: { name: 'Pieter Jansen' } }
			}
			throw new Error('404')
		})
		expect(await personNames(['p1', 'p1', 'p2', null])).toEqual({ p1: 'Pieter Jansen' })
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('asks decidiq whether the drafter may take the decision, and answers nothing when it cannot', async () => {
		axios.get.mockReset()
		axios.get.mockResolvedValueOnce({ data: { authorised: true, subject: 'Woo decisions' } })
		expect(await decisionAuthority('d-1')).toEqual({ authorised: true, subject: 'Woo decisions' })
		expect(axios.get.mock.calls[0][0]).toBe('/apps/decidiq/api/decisions/d-1/authority')
		axios.get.mockRejectedValueOnce(new Error('404'))
		expect(await decisionAuthority('d-1')).toBeNull()
	})
})

describe('the drafter by name', () => {
	it('looks up each account once and leaves out one it cannot read', async () => {
		axios.get.mockReset()
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/cloud/users/pieter')) {
				return { data: { ocs: { data: { id: 'pieter', displayname: 'Pieter Jansen' } } } }
			}
			throw new Error('404')
		})
		expect(await userDisplayNames(['pieter', 'pieter', 'ghost', ''])).toEqual({ pieter: 'Pieter Jansen' })
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get.mock.calls[0][1].headers).toMatchObject({ 'OCS-APIRequest': 'true' })
	})

	it('names the drafter by the proposer, else the owner\'s display name, else the account', () => {
		const names = { pieter: 'Pieter Jansen' }
		expect(drafterName({ proposer: 'Raad', '@self': { owner: 'pieter' } }, names)).toBe('Raad')
		expect(drafterName({ '@self': { owner: 'pieter' } }, names)).toBe('Pieter Jansen')
		expect(drafterName({ '@self': { owner: 'anouk' } }, names)).toBe('anouk')
		expect(drafterName({}, names)).toBe('')
	})
})
