// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Start an agenda from a template or copy items from an earlier meeting
// (agenda-templates-and-copy, age-04 and pla-14).
//
// @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
// @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
// @e2e tests/e2e/agenda-templates-and-copy.spec.ts

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	itemsFromMeeting,
	itemsFromTemplate,
	nextOrderNumber,
	templateFromItems,
} from '../../src/utils/agendaCopy.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const MEETING = '6f1c1c38-4d3c-4d0e-9a55-2b8b2b1f0e14'
const EARLIER = '6f1c1c38-4d3c-4d0e-9a55-2b8b2b1f0e07'
const validItem = validatorFor('agenda-item')
const validTemplate = validatorFor('agenda-template')

const raadsvergadering = {
	name: 'Raadsvergadering',
	items: [
		{
			title: 'Opening en mededelingen',
			itemType: 'informational',
			isFormality: true,
		},
		{
			title: 'Vaststellen van de agenda',
			itemType: 'decision',
			isFormality: true,
		},
		{ title: 'Vragenhalfuur', itemType: 'discussion', estimatedDuration: 30 },
		{ title: 'Hamerstukken', itemType: 'decision', isFormality: true },
		{ title: 'Bespreekstukken', itemType: 'decision', estimatedDuration: 90 },
		{ title: 'Sluiting' },
	],
}

const earlier = [
	{
		id: 'i-3',
		meeting: EARLIER,
		title: 'Rondvraag',
		itemType: 'informational',
		orderNumber: 3,
	},
	{
		id: 'i-1',
		meeting: EARLIER,
		title: 'Opening',
		itemType: 'informational',
		orderNumber: 1,
		formalityOutcome: 'adopted-without-debate',
	},
	{
		id: 'i-2',
		meeting: EARLIER,
		title: 'Begroting 2027',
		itemType: 'decision',
		orderNumber: 2,
		description: 'Vaststellen',
	},
]

describe('Start from a template', () => {
	it('gives the meeting the six items of Raadsvergadering in order', () => {
		const items = itemsFromTemplate(raadsvergadering, MEETING, 1)
		expect(items.map((i) => i.title)).toEqual(
			raadsvergadering.items.map((i) => i.title),
		)
		expect(items.map((i) => i.orderNumber)).toEqual([1, 2, 3, 4, 5, 6])
		for (const item of items) {
			expect(validItem(item), JSON.stringify(validItem.errors)).toBe(true)
		}
	})

	it('saves an agenda as a template the register accepts', () => {
		const template = templateFromItems('Commissie', [
			...earlier,
			{
				id: 'i-9',
				meeting: EARLIER,
				title: 'Subpunt',
				parentItem: 'i-2',
				orderNumber: 2,
			},
		])
		expect(template.items.map((i) => i.title)).toEqual([
			'Opening',
			'Begroting 2027',
			'Rondvraag',
		])
		expect(template.items[0].formalityOutcome).toBeUndefined()
		expect(validTemplate(template), JSON.stringify(validTemplate.errors)).toBe(
			true,
		)
		expect(validTemplate({ name: 'x', items: [{ itemType: 'decision' }] })).toBe(
			false,
		)
	})
})

describe('Copy from a meeting', () => {
	it('adds the two picked items after the last item, in the earlier order', () => {
		const current = [{ orderNumber: 1 }, { orderNumber: 4 }]
		const items = itemsFromMeeting(
			earlier,
			EARLIER,
			MEETING,
			nextOrderNumber(current),
			['i-3', 'i-1'],
		)
		expect(items.map((i) => i.title)).toEqual(['Opening', 'Rondvraag'])
		expect(items.map((i) => i.orderNumber)).toEqual([5, 6])
		expect(items[0].formalityOutcome).toBeUndefined()
		expect(items[0].id).toBeUndefined()
		for (const item of items) {
			expect(validItem(item), JSON.stringify(validItem.errors)).toBe(true)
		}
		expect(validItem({ ...items[0], orderNumber: 'vijf' })).toBe(false)
	})

	it('copies the whole agenda when nothing is picked', () => {
		expect(itemsFromMeeting(earlier, EARLIER, MEETING, 1, [])).toHaveLength(3)
		expect(nextOrderNumber([])).toBe(1)
	})
})

describe('the meeting Agenda widget', () => {
	it('offers copying and saving as a template', () => {
		const tab = read('src/components/tabs/MeetingAgendaTab.vue')
		expect(tab).toContain('AgendaCopyDialog')
		expect(tab).toContain('agenda-copy-items')
		expect(tab).toContain('agenda-save-template')
		const dialog = read('src/dialogs/AgendaCopyDialog.vue')
		expect(dialog).toContain('agenda-copy-dialog')
	})

	it('has a settings page for the templates', () => {
		const pages = JSON.parse(read('src/manifest.d/agenda-templates.json')).pages
		expect(pages.find((p) => p.id === 'AgendaTemplates').config.schema).toBe(
			'agenda-template',
		)
		expect(read('src/menu-layout.json')).toContain('"AgendaTemplates"')
	})
})
