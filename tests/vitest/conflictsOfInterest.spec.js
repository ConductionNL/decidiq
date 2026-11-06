// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A member declares a conflict of interest from a motion or agenda item page,
// and a recused member is not counted among those who may vote
// (bodies-conflict-of-interest-recusal, bod-10).
//
// @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
// @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	declarationPayload,
	declarationsFor,
	DECLARE_PATH,
	eligibleCount,
	isRecusal,
	recusedMemberCount,
} from '../../src/utils/conflicts.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const anna = {
	id: 'd-1',
	boardMember: 'M-anna',
	agendaItem: 'M-12',
	declarationType: 'financial-interest',
	description: 'I own land in the plan area',
	actionTaken: 'recused-from-vote',
	declarationTimestamp: '2026-09-29T10:00:00Z',
}

describe('declarationPayload', () => {
	it('posts the reason and the recusal for the logged-in member, without a membership id', () => {
		expect(
			declarationPayload({
				subjectId: 'M-12',
				declarationType: 'financial-interest',
				description: '  I own land in the plan area ',
				recuseFromVote: true,
			}),
		).toEqual({
			agendaItemId: 'M-12',
			declarationType: 'financial-interest',
			description: 'I own land in the plan area',
			severity: 'material',
			recuseFromVote: true,
		})
		expect(DECLARE_PATH).toBe('/apps/decidiq/api/conflicts')
	})
})

describe('declarationsFor', () => {
	it('lists the declarations on the motion and its agenda item, newest first, once each', () => {
		const onItem = {
			...anna,
			id: 'd-2',
			agendaItem: 'item-7',
			declarationTimestamp: '2026-09-29T11:00:00Z',
		}
		const elsewhere = { ...anna, id: 'd-3', agendaItem: 'M-99' }
		const rows = declarationsFor(
			[anna, onItem, elsewhere, anna],
			['M-12', 'item-7'],
		)
		expect(rows.map((r) => r.id)).toEqual(['d-2', 'd-1'])
	})

	it('reads an expanded agenda item reference', () => {
		expect(
			declarationsFor([{ ...anna, agendaItem: { id: 'M-12' } }], ['M-12']),
		).toHaveLength(1)
	})
})

describe('recusals and the eligible count', () => {
	it('counts a recused member once and leaves her out of the eligible voters', () => {
		const again = { ...anna, id: 'd-2', agendaItem: 'item-7' }
		const disclosed = {
			...anna,
			id: 'd-4',
			boardMember: 'M-bert',
			actionTaken: 'disclosed-and-participated',
		}
		expect(isRecusal(anna)).toBe(true)
		expect(isRecusal(disclosed)).toBe(false)
		expect(
			recusedMemberCount([anna, again, disclosed], ['M-12', 'item-7']),
		).toBe(1)
		expect(eligibleCount(9, [anna, again, disclosed], ['M-12', 'item-7'])).toBe(
			8,
		)
		expect(eligibleCount(0, [anna], ['M-12'])).toBe(0)
	})
})

describe('wiring', () => {
	it('shows the conflicts widget on the motion and agenda item pages', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const page = (id) => manifest.pages.find((p) => p.id === id)
		expect(page('MotionDetail').slots['widget-motion-conflicts']).toBe(
			'MotionConflictsTab',
		)
		expect(
			page('MotionDetail').config.layout.some(
				(l) => l.widgetId === 'motion-conflicts',
			),
		).toBe(true)
		expect(page('AgendaItemDetail').slots['widget-agenda-conflicts']).toBe(
			'AgendaItemConflictsTab',
		)
		expect(
			page('AgendaItemDetail').config.layout.some(
				(l) => l.widgetId === 'agenda-conflicts',
			),
		).toBe(true)
		const registry = read('src/registry.js')
		expect(registry).toContain('MotionConflictsTab: page(MotionConflictsTab)')
		expect(registry).toContain(
			'AgendaItemConflictsTab: page(AgendaItemConflictsTab)',
		)
	})

	it('the widget opens the dialog and posts the payload', () => {
		const tab = read('src/components/tabs/ConflictsTab.vue')
		expect(tab).toContain('<ConflictDeclareDialog')
		expect(tab).toContain(
			'declarationPayload({ subjectId: this.subjectId, ...values })',
		)
		const dialog = read('src/dialogs/ConflictDeclareDialog.vue')
		expect(dialog).toContain(':inputLabel=')
		expect(dialog).toContain('recuseFromVote: this.recuse')
	})

	it('the voting round counts eligible voters without the recused', () => {
		const panel = read('src/components/VotingRoundPanel.vue')
		expect(panel).toContain('total: eligibleVoters')
		expect(read('src/components/tabs/MotionVotingRoundTab.vue')).toContain(
			':agendaItemId="agendaItemId"',
		)
	})
})
