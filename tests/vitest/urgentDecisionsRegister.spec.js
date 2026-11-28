/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client side of the urgent decisions register: the declaration names
 * the body on whose behalf it is made (and the office, when the power is one
 * person's), and the report to the ratifying body can be recorded while the
 * decision waits for ratification and has no report date yet.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
 */
import { describe, expect, it } from 'vitest'
import { canDeclare, canReport, declarationBody } from '../../src/services/urgency.js'

describe('declarationBody', () => {
	it('posts the reason, the declaring body and the office, trimmed', () => {
		expect(declarationBody({ reason: '  Dreigende wanordelijkheden ', body: { id: 'body-college', label: 'College' }, office: ' Burgemeester ' }))
			.toEqual({ reason: 'Dreigende wanordelijkheden', urgencyDeclaredByBody: 'body-college', urgencyDeclaredInOffice: 'Burgemeester' })
	})

	it('accepts the body as an id and leaves an empty office out', () => {
		expect(declarationBody({ reason: 'Brand', body: 'body-college', office: '  ' }))
			.toEqual({ reason: 'Brand', urgencyDeclaredByBody: 'body-college', urgencyDeclaredInOffice: null })
	})

	it('sends an empty body when none is picked, so the server names the field', () => {
		expect(declarationBody({ reason: 'Brand' }).urgencyDeclaredByBody).toBe('')
		expect(declarationBody().reason).toBe('')
	})
})

describe('canDeclare', () => {
	it('needs a reason and a declaring body', () => {
		expect(canDeclare({ reason: 'Brand', body: { id: 'body-college' } })).toBe(true)
		expect(canDeclare({ reason: 'Brand', body: null })).toBe(false)
		expect(canDeclare({ reason: '   ', body: 'body-college' })).toBe(false)
	})
})

describe('canReport', () => {
	it('offers the report while the decision waits and was not reported', () => {
		expect(canReport({ isUrgent: true }, { awaiting: true })).toBe(true)
	})

	it('hides it once a report date is set, or when nothing waits', () => {
		expect(canReport({ isUrgent: true, reportedToRatifyingBodyAt: '2026-10-06' }, { awaiting: true })).toBe(false)
		expect(canReport({ isUrgent: true }, { awaiting: false })).toBe(false)
		expect(canReport(null, null)).toBe(false)
	})
})
