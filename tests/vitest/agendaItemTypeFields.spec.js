/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the fields an agenda item type declares (#1393).
 *
 * The defect: an administrator could declare extra fields on an agenda item
 * type (`AgendaItemType.fields`: key, label, fieldType, required, enumValues),
 * but nothing in src/ read them, so no form or detail page ever offered an
 * input and `AgendaItem.typeFields` could not be filled in or corrected.
 *
 * This repo's vitest runs without @vitejs/plugin-vue, so a `.vue` file cannot
 * be mounted (see decisionDetailFormDialog.spec.js). The field logic lives in
 * src/utils/agendaItemTypeFields.js and is tested here; the wiring into the
 * agenda form and the detail page is asserted against the sources.
 *
 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	findItemType,
	missingRequiredTypeFields,
	setTypeFieldValue,
	typeFieldInputs,
} from '../../src/utils/agendaItemTypeFields.js'

/**
 * Read a repo file relative to this spec.
 *
 * @param {string} relative Path relative to tests/vitest/.
 * @return {string} The file contents.
 */
function read(relative) {
	return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

const technicalQuestion = {
	id: '0b6f7a52-2c1a-4b8e-9d0e-3f1a2b3c4d5e',
	'@self': { slug: 'type-technische-vraag' },
	name: 'Technische vraag',
	fields: [
		{ key: 'question', label: 'Vraag', fieldType: 'text', required: true },
		{ key: 'answer', label: 'Antwoord', fieldType: 'text' },
		{ key: 'answeredOn', label: 'Beantwoord op', fieldType: 'date' },
		{ key: 'answeredBy', label: 'Beantwoord door', fieldType: 'reference' },
		{ key: 'number', label: 'Nummer', fieldType: 'string' },
		{ key: 'pages', label: 'Pagina’s', fieldType: 'number' },
		{ key: 'public', label: 'Openbaar', fieldType: 'boolean' },
		{
			key: 'lifecycle',
			label: 'Status',
			fieldType: 'enum',
			enumValues: ['submitted', 'answered'],
		},
		{ label: 'No key, never stored' },
	],
}

describe('typeFieldInputs', () => {
	it('offers one input per declared field, captioned by its label', () => {
		const inputs = typeFieldInputs(technicalQuestion)
		expect(inputs.map((i) => i.key)).toEqual([
			'question',
			'answer',
			'answeredOn',
			'answeredBy',
			'number',
			'pages',
			'public',
			'lifecycle',
		])
		expect(inputs[0].label).toBe('Vraag')
		expect(inputs[0].required).toBe(true)
		expect(inputs[1].required).toBe(false)
	})

	it('chooses the input by fieldType', () => {
		const byKey = Object.fromEntries(
			typeFieldInputs(technicalQuestion).map((i) => [i.key, i.input]),
		)
		expect(byKey).toEqual({
			question: 'textarea',
			answer: 'textarea',
			answeredOn: 'date',
			answeredBy: 'text',
			number: 'text',
			pages: 'number',
			public: 'checkbox',
			lifecycle: 'select',
		})
	})

	it('gives a pick-from-list field its choices', () => {
		const lifecycle = typeFieldInputs(technicalQuestion).find(
			(i) => i.key === 'lifecycle',
		)
		expect(lifecycle.options).toEqual(['submitted', 'answered'])
	})

	it('falls back to the key when a field has no label', () => {
		const [only] = typeFieldInputs({ fields: [{ key: 'answer' }] })
		expect(only.label).toBe('answer')
		expect(only.input).toBe('text')
	})

	it('offers nothing for a missing type or a type without fields', () => {
		expect(typeFieldInputs(null)).toEqual([])
		expect(typeFieldInputs({ name: 'Brief' })).toEqual([])
	})
})

describe('findItemType', () => {
	const types = [{ id: 'other', name: 'Other' }, technicalQuestion]

	it('finds the type by its uuid', () => {
		expect(findItemType(types, technicalQuestion.id)).toBe(technicalQuestion)
	})

	it('finds the type by the slug a seeded item stores', () => {
		expect(findItemType(types, 'type-technische-vraag')).toBe(technicalQuestion)
	})

	it('accepts the option object a reference picker may hand over', () => {
		expect(findItemType(types, { id: technicalQuestion.id, label: 'x' })).toBe(
			technicalQuestion,
		)
	})

	it('finds nothing for an empty or unknown reference', () => {
		expect(findItemType(types, '')).toBeNull()
		expect(findItemType(types, 'nope')).toBeNull()
		expect(findItemType(null, technicalQuestion.id)).toBeNull()
	})
})

describe('setTypeFieldValue', () => {
	const inputs = Object.fromEntries(
		typeFieldInputs(technicalQuestion).map((i) => [i.key, i]),
	)

	it('writes under the field key without touching the other values', () => {
		const before = { question: 'Hoeveel?', legacy: 'kept' }
		const after = setTypeFieldValue(before, inputs.answer, 'Veertig.')
		expect(after).toEqual({
			question: 'Hoeveel?',
			legacy: 'kept',
			answer: 'Veertig.',
		})
		expect(before).toEqual({ question: 'Hoeveel?', legacy: 'kept' })
	})

	it('stores a number field as a number', () => {
		expect(setTypeFieldValue({}, inputs.pages, '12')).toEqual({ pages: 12 })
	})

	it('stores a yes/no field as a boolean', () => {
		expect(setTypeFieldValue({}, inputs.public, true)).toEqual({ public: true })
		expect(setTypeFieldValue({ public: true }, inputs.public, false)).toEqual({
			public: false,
		})
	})

	it('removes the value when the input is emptied', () => {
		expect(
			setTypeFieldValue({ answer: 'x', pages: 3 }, inputs.answer, ''),
		).toEqual({ pages: 3 })
		expect(setTypeFieldValue({ pages: 3 }, inputs.pages, '')).toEqual({})
		expect(
			setTypeFieldValue({ lifecycle: 'answered' }, inputs.lifecycle, null),
		).toEqual({})
	})

	it('starts from an empty object when there are no values yet', () => {
		expect(setTypeFieldValue(null, inputs.answeredOn, '2026-09-27')).toEqual({
			answeredOn: '2026-09-27',
		})
	})
})

describe('missingRequiredTypeFields', () => {
	it('names the required fields that are still empty', () => {
		const inputs = typeFieldInputs(technicalQuestion)
		expect(missingRequiredTypeFields(inputs, {})).toEqual(['Vraag'])
		expect(missingRequiredTypeFields(inputs, { question: 'Hoeveel?' })).toEqual(
			[],
		)
	})
})

describe('the declared fields are wired into the screens', () => {
	it('the agenda item form renders them and saves them into typeFields', () => {
		const source = read('../../src/components/tabs/MeetingAgendaTab.vue')
		expect(source).toContain('<AgendaItemTypeFields')
		expect(source).toContain('typeFields')
	})

	it('the agenda item detail page has a widget for them', () => {
		const manifest = JSON.parse(read('../../src/manifest.json'))
		const page = manifest.pages.find((p) => p.id === 'AgendaItemDetail')
		const widget = page.config.widgets.find(
			(w) => w.component === 'AgendaItemTypeFieldsTab',
		)
		expect(widget).toBeDefined()
		expect(page.config.layout.some((l) => l.widgetId === widget.id)).toBe(true)
		expect(Object.values(page.slots)).toContain('AgendaItemTypeFieldsTab')
		expect(read('../../src/registry.js')).toMatch(
			/AgendaItemTypeFieldsTab: page\(AgendaItemTypeFieldsTab\)/,
		)
	})
})
