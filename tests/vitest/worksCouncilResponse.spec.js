// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * works-council-consultation task 6: recording the director's decision, the
 * suspension term, and what the traject page offers.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	decisionIsComplete,
	decisionOutcomes,
	decisionPayload,
	nextSteps,
	responseDocumentPath,
	suspensionUntil,
} from '../../src/utils/worksCouncilResponse.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')
const schema = JSON.parse(read('lib/Settings/register.d/47-works-council-consultation.json')).components.schemas.ConsultationRequest

const SENT = {
	type: 'adviceRequest',
	worArticle: 'art. 25 lid 1 sub n WOR',
	subject: 'Uitbesteding ICT-beheer',
	director: '2b3c4d5e-6f7a-4b2c-9d3e-4f5a6b7c8d12',
	governanceBody: '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85',
	receivedDate: '2026-01-06',
	lifecycle: 'sent',
	responseOutcome: 'advice-with-conditions',
	responseText: 'Positief onder voorwaarden.',
	responseDate: '2026-02-24',
}

describe('the director decision (REQ-WCC-006)', () => {
	it('offers the outcomes the schema has for each request type', () => {
		const all = [...decisionOutcomes('adviceRequest'), ...decisionOutcomes('consent-request')]
		for (const outcome of all) expect(schema.properties.decisionOutcome.enum).toContain(outcome)
		expect(decisionOutcomes('consent-request')).not.toContain('contrary-to-advice')
		expect(decisionOutcomes('unknown')).toEqual([])
	})

	it('shows the same suspension end the register derives: one month, contrary advice only', () => {
		expect(suspensionUntil('adviceRequest', 'contrary-to-advice', '2026-03-10')).toBe('2026-04-10')
		expect(suspensionUntil('adviceRequest', 'contrary-to-advice', '2026-01-31')).toBe('2026-02-28')
		expect(suspensionUntil('adviceRequest', 'contrary-to-advice', '2026-12-15')).toBe('2027-01-15')
		expect(suspensionUntil('adviceRequest', 'in-line-with-advice', '2026-03-10')).toBeNull()
		expect(suspensionUntil('consent-request', 'contrary-to-advice', '2026-03-10')).toBeNull()
		const rule = schema['x-openregister-calculations'].suspensionTo.expression.if
		expect(rule[0].and).toEqual([{ eq: [{ prop: 'type' }, 'adviceRequest'] }, { eq: [{ prop: 'decisionOutcome' }, 'contrary-to-advice'] }])
		expect(rule[1].dateAdd).toEqual({ date: { prop: 'decisionDate' }, amount: 1, unit: 'months' })
	})

	it('saves the decision with every field kept and the move the lifecycle allows', () => {
		const payload = decisionPayload({ ...SENT, '@self': { id: 'x' }, suspensionTo: '2020-01-01' }, { outcome: 'contrary-to-advice', text: '  Uitbesteding zonder evaluatie. ', date: '2026-03-10' })

		expect(payload).toEqual({ ...SENT, decisionOutcome: 'contrary-to-advice', decisionText: 'Uitbesteding zonder evaluatie.', decisionDate: '2026-03-10', lifecycle: 'decision-received' })
		expect(schema['x-openregister-lifecycle'].transitions).toContainEqual({ from: 'sent', to: 'decision-received' })
		const valid = validatorFor('consultation-request')
		expect(valid(payload), JSON.stringify(valid.errors)).toBe(true)
	})

	it('needs an outcome the type allows and a date', () => {
		expect(decisionIsComplete('adviceRequest', { outcome: 'contrary-to-advice', date: '2026-03-10' })).toBe(true)
		expect(decisionIsComplete('consent-request', { outcome: 'contrary-to-advice', date: '2026-03-10' })).toBe(false)
		expect(decisionIsComplete('adviceRequest', { outcome: 'not-pursued', date: '' })).toBe(false)
	})
})

describe('what the traject page offers (REQ-WCC-003, REQ-WCC-005)', () => {
	it('records the decision once the response is sent, and writes the document once it is adopted', () => {
		expect(nextSteps(SENT)).toEqual({ recordDecision: true, writeDocument: true, constituencyConsultation: null, relatedDecision: null })
		expect(nextSteps({ ...SENT, lifecycle: 'consultationMeeting' })).toMatchObject({ recordDecision: false, writeDocument: false })
		expect(nextSteps({ ...SENT, lifecycle: 'adopted', responseOutcome: null })).toMatchObject({ writeDocument: false })
		expect(nextSteps(null)).toEqual({ recordDecision: false, writeDocument: false, constituencyConsultation: null, relatedDecision: null })
	})

	it('links the constituency consultation and the related decision when they are set', () => {
		expect(nextSteps({ ...SENT, constituencyConsultation: 'c-1', relatedDecision: 'd-1' })).toMatchObject({ constituencyConsultation: 'c-1', relatedDecision: 'd-1' })
	})

	it('writes the document through its route', () => {
		expect(responseDocumentPath('t 1')).toBe('/apps/decidiq/api/works-council-consultations/t%201/response-document')
		expect(read('appinfo/routes.php')).toContain("'url' => '/api/works-council-consultations/{id}/response-document', 'verb' => 'POST'")
	})
	it('sits on the traject page as its next-step widget', () => {
		const fragment = JSON.parse(read('src/manifest.d/works-council-consultation.json'))
		const page = fragment.pages.find((p) => p.id === 'WorksCouncilConsultationDetail')
		expect(page.config.widgets.find((w) => w.id === 'wor-traject-next-step')).toMatchObject({ type: 'custom', component: 'WorksCouncilResponseTab' })
		expect(page.slots['widget-wor-traject-next-step']).toBe('WorksCouncilResponseTab')
		expect(page.config.layout.some((l) => l.widgetId === 'wor-traject-next-step')).toBe(true)
		expect(read('src/registry.js')).toMatch(/WorksCouncilResponseTab: page\(WorksCouncilResponseTab\)/)
	})
})
