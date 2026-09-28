/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for technical questions assigned to officials (mot-17).
 *
 * A technical question is an agenda item whose type declares an `assignedTo`
 * person field. The griffier picks the official by name, the meeting page
 * lists the meeting's questions as open, answered or overdue.
 *
 * This repo's vitest runs without @vitejs/plugin-vue, so a `.vue` file cannot
 * be mounted; the logic lives in src/utils and the wiring is asserted against
 * the sources, as agendaItemTypeFields.spec.js does.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	setTypeFieldValue,
	typeFieldInputs,
} from '../../src/utils/agendaItemTypeFields.js'
import {
	technicalQuestionRows,
	technicalQuestionStatus,
} from '../../src/utils/technicalQuestions.js'

function read(path) {
	return readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')
}

const questionType = {
	id: 'type-tv',
	slug: 'type-technische-vraag',
	name: 'Technische vraag',
	fields: [
		{ key: 'question', label: 'Vraag', fieldType: 'text', required: true },
		{ key: 'answer', label: 'Antwoord', fieldType: 'text' },
		{ key: 'assignedTo', label: 'Ambtenaar', fieldType: 'user' },
		{ key: 'answerDeadline', label: 'Antwoord uiterlijk', fieldType: 'date' },
	],
}
const letterType = { id: 'type-rib', name: 'Raadsinformatiebrief', fields: [] }

describe('a person field on an agenda item type', () => {
	it('renders as a person picker', () => {
		const assignee = typeFieldInputs(questionType).find(
			(input) => input.key === 'assignedTo',
		)
		expect(assignee.input).toBe('user')
	})

	it('stores the user id of the picked person', () => {
		const assignee = typeFieldInputs(questionType).find(
			(input) => input.key === 'assignedTo',
		)
		expect(
			setTypeFieldValue({ question: 'Klopt het?' }, assignee, {
				id: 'j.official',
				label: 'Jan Ambtenaar',
			}),
		).toEqual({ question: 'Klopt het?', assignedTo: 'j.official' })
		expect(
			setTypeFieldValue({ assignedTo: 'j.official' }, assignee, null),
		).toEqual({})
	})

	it('is offered by the field renderer with the user search', () => {
		const source = read('../../src/components/AgendaItemTypeFields.vue')
		expect(source).toContain("input.input === 'user'")
		expect(source).toContain('searchDelegateUsers')
	})
})

describe('the status of a technical question', () => {
	const today = '2026-10-08'

	it('is answered once an answer is filled in', () => {
		expect(
			technicalQuestionStatus(
				{ typeFields: { answer: 'Ja.', answerDeadline: '2026-10-01' } },
				today,
			),
		).toBe('answered')
	})

	it('is overdue when the deadline passed without an answer', () => {
		expect(
			technicalQuestionStatus(
				{ typeFields: { answerDeadline: '2026-10-07' } },
				today,
			),
		).toBe('overdue')
	})

	it('is open on or before the deadline, and without one', () => {
		expect(
			technicalQuestionStatus(
				{ typeFields: { answerDeadline: '2026-10-08' } },
				today,
			),
		).toBe('open')
		expect(technicalQuestionStatus({ typeFields: {} }, today)).toBe('open')
	})
})

describe('the meeting list of technical questions', () => {
	it('lists only items whose type declares an assignee, with their status', () => {
		const items = [
			{ id: 'i1', title: 'RIB 2026-14', type: 'type-rib' },
			{
				id: 'i2',
				title: 'Vraag 1',
				type: 'type-tv',
				typeFields: {
					question: 'Klopt de planning?',
					assignedTo: 'j.official',
					answerDeadline: '2026-10-07',
				},
			},
			{
				id: 'i3',
				title: 'Vraag 2',
				type: 'type-technische-vraag',
				typeFields: { question: 'Wat kost het?', answer: 'Vijf ton.' },
			},
		]
		const rows = technicalQuestionRows(
			items,
			[questionType, letterType],
			'2026-10-08',
		)
		expect(rows.map((row) => [row.id, row.status])).toEqual([
			['i2', 'overdue'],
			['i3', 'answered'],
		])
		expect(rows[0]).toMatchObject({
			question: 'Klopt de planning?',
			assignee: 'j.official',
			deadline: '2026-10-07',
		})
	})

	it('is a widget on the meeting page', () => {
		const manifest = JSON.parse(read('../../src/manifest.json'))
		const page = manifest.pages.find((p) => p.id === 'MeetingDetail')
		const widget = page.config.widgets.find(
			(w) => w.component === 'MeetingTechnicalQuestionsTab',
		)
		expect(widget).toBeTruthy()
		expect(page.config.layout.some((cell) => cell.widgetId === widget.id)).toBe(
			true,
		)
		expect(read('../../src/registry.js')).toContain(
			'MeetingTechnicalQuestionsTab: page(MeetingTechnicalQuestionsTab)',
		)
	})
})
