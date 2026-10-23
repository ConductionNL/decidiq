/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Incoming documents reach the agenda (age-13): the meeting's widget reads
 * agenda items of an incoming document type instead of the retired
 * raadsinformatiebrief and ingekomen-stuk schemas, the Incoming documents
 * dashboard widget lists those without a meeting, and Put on agenda saves a payload the
 * real agenda-item schema accepts. This repo's vitest cannot mount a `.vue`
 * file, so the wiring is asserted against the sources.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	incomingItems,
	incomingRows,
	incomingTypes,
	isIncomingType,
	putOnAgendaPayload,
	waitingItems,
} from '../../src/utils/incomingDocuments.js'
import { validatorFor } from './helpers/registerSchema.js'

function source(path) {
	return readFileSync(
		fileURLToPath(new URL(`../../${path}`, import.meta.url)),
		'utf8',
	)
}

const LETTER_TYPE = '7a1f0000-0000-4000-a000-0000000000a1'
const RIB_TYPE = '7a1f0000-0000-4000-a000-0000000000a2'
const MOTION_TYPE = '7a1f0000-0000-4000-a000-0000000000a3'
const COUNCIL_14_OCT = '7a1f0000-0000-4000-a000-0000000000c1'

const types = [
	{ id: LETTER_TYPE, name: 'Ingekomen stuk', incomingDocument: true },
	{
		id: RIB_TYPE,
		name: 'Raadsinformatiebrief',
		'@self': { slug: 'type-raadsinformatiebrief' },
	},
	{ id: MOTION_TYPE, name: 'Motie', incomingDocument: false },
]

const letter = {
	id: 'item-letter',
	'@self': { id: 'item-letter', schema: 'agenda-item' },
	title: 'Brief van een bewoner over de schoolzone',
	itemType: 'informational',
	orderNumber: 300,
	type: LETTER_TYPE,
	meeting: null,
}
const routed = {
	id: 'item-rib',
	title: 'Wachtlijsten jeugdzorg',
	type: { id: RIB_TYPE },
	meeting: COUNCIL_14_OCT,
	lifecycle: 'submitted',
}
const motion = {
	id: 'item-motion',
	title: 'Motie fietspaden',
	type: MOTION_TYPE,
	meeting: null,
}

describe('incoming document types', () => {
	it('counts a flagged type and a seeded kind stored before the flag, never a type flagged false', () => {
		expect(isIncomingType(types[0])).toBe(true)
		expect(isIncomingType(types[1])).toBe(true)
		expect(isIncomingType(types[2])).toBe(false)
		expect(
			isIncomingType({ slug: 'type-ingekomen-stuk', incomingDocument: false }),
		).toBe(false)
		expect(isIncomingType(null)).toBe(false)
		expect([...incomingTypes(types).keys()]).toEqual([LETTER_TYPE, RIB_TYPE])
	})

	it('the merged agenda-item-type schema declares the flag and the seeded kinds carry it', () => {
		const validate = validatorFor('agenda-item-type')
		const seeds = []
		const walk = (node) => {
			if (Array.isArray(node)) return node.forEach(walk)
			if (!node || typeof node !== 'object') return
			if (node['@self']?.schema === 'agenda-item-type') seeds.push(node)
			Object.values(node).forEach(walk)
		}
		walk(JSON.parse(source('lib/Settings/profiles/municipality.json')))
		for (const slug of ['type-ingekomen-stuk', 'type-raadsinformatiebrief']) {
			const seed = seeds.find((s) => s.slug === slug)
			expect(seed?.incomingDocument, slug).toBe(true)
			// The seed names its body by slug; the importer resolves it to the uuid the schema holds.
			const { '@self': self, slug: s, ...fields } = seed
			expect(
				validate({
					...fields,
					owningBody: '7a1f0000-0000-4000-a000-0000000000b1',
				}),
				JSON.stringify(validate.errors),
			).toBe(true)
		}
	})
})

describe('the meeting widget and the waiting list', () => {
	const incoming = incomingTypes(types)
	const items = [letter, routed, motion]

	it('the meeting shows its incoming documents with the type name', () => {
		const onMeeting = incomingItems(items, incoming).filter(
			(item) => item.meeting === COUNCIL_14_OCT,
		)
		expect(incomingRows(onMeeting, incoming)).toEqual([
			{
				id: 'item-rib',
				typeLabel: 'Raadsinformatiebrief',
				title: 'Wachtlijsten jeugdzorg',
				lifecycle: 'submitted',
			},
		])
	})

	it('the waiting list holds incoming documents without a meeting only', () => {
		expect(waitingItems(items, incoming).map((item) => item.id)).toEqual([
			'item-letter',
		])
	})

	it('the widget no longer reads the retired schemas', () => {
		const tab = source('src/components/tabs/MeetingRoutedDocumentsTab.vue')
		expect(tab).not.toContain("'raadsinformatiebrief'")
		expect(tab).not.toContain("'ingekomen-stuk'")
		expect(tab).toContain('incomingDocuments.js')
		expect(tab).toContain("'agenda-item-type'")
	})
})

describe('Put on agenda', () => {
	it('puts the letter on the council meeting of 14 October after its last item, valid against agenda-item', () => {
		const payload = putOnAgendaPayload(letter, COUNCIL_14_OCT, [
			{ orderNumber: 1 },
			{ orderNumber: 7 },
			{ title: 'no order' },
		])
		expect(payload.meeting).toBe(COUNCIL_14_OCT)
		expect(payload.orderNumber).toBe(8)
		expect(payload).not.toHaveProperty('id')
		expect(payload).not.toHaveProperty('@self')
		const validate = validatorFor('agenda-item')
		expect(validate(payload), JSON.stringify(validate.errors)).toBe(true)

		const afterIt = waitingItems(
			[{ ...letter, ...payload }],
			incomingTypes(types),
		)
		expect(afterIt).toEqual([])
	})

	it('an empty meeting gets the letter as item 1', () => {
		expect(putOnAgendaPayload(letter, COUNCIL_14_OCT, []).orderNumber).toBe(1)
	})

	it('the dashboard widget and the dialog are wired', () => {
		const manifest = JSON.parse(source('src/manifest.json'))
		const dashboard = manifest.pages.find((p) => p.id === 'Dashboard')
		expect(dashboard.slots['widget-incoming-documents']).toBe(
			'IncomingDocumentsWidget',
		)
		expect(
			dashboard.config.widgets.find((w) => w.id === 'incoming-documents')
				?.type,
		).toBe('custom')
		expect(
			dashboard.config.layout.some((l) => l.widgetId === 'incoming-documents'),
		).toBe(true)
		expect(source('src/registry.js')).toContain(
			'IncomingDocumentsWidget: widget(IncomingDocumentsWidget',
		)
		const view = source(
			'src/views/dashboard/widgets/IncomingDocumentsWidget.vue',
		)
		expect(view).toContain("from '../../../dialogs/PutOnAgendaDialog.vue'")
		expect(view).toContain('putOnAgendaPayload')
		expect(view).toContain('waitingItems')
		expect(source('src/dialogs/PutOnAgendaDialog.vue')).toContain('inputLabel')
	})
})
