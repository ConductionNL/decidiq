/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The approver sees a decision's risk band, its score and the properties
 * that produced it (REQ-DWP-008, the-decision-as-a-walked-process task 8.3).
 * Component mounting is absent in this repo's vitest, so the shaping lives in
 * src/utils/riskAssessment.js and the widget wiring is asserted on its source.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { riskBasisRows, riskSummary } from '../../src/utils/riskAssessment.js'

const read = (relative) => readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')

const STRINGS = [
	'Risk band {band}',
	'No risk band',
	'Score {score}, from:',
	'{property}: {value} (+{points})',
]

describe('risk assessment for the approver', () => {
	it('shows the band and the two properties that produced a high score', () => {
		const summary = riskSummary({
			riskAssessment: {
				score: 70,
				band: 'high',
				basis: [
					{ property: 'amount', value: '250000', points: 50 },
					{ property: 'category', value: 'subsidy', points: 20 },
				],
			},
		})

		expect(summary).toEqual({
			band: 'high',
			score: 70,
			basis: [
				{ property: 'amount', value: '250000', points: 50 },
				{ property: 'category', value: 'subsidy', points: 20 },
			],
		})
	})

	it('keeps a score below every band, with an empty band', () => {
		expect(riskSummary({ riskAssessment: { score: 5, band: '', basis: [] } }))
			.toEqual({ band: '', score: 5, basis: [] })
		expect(riskSummary({ riskAssessment: { score: '5' } }))
			.toEqual({ band: '', score: 5, basis: [] })
	})

	it('shows nothing for a decision without a score', () => {
		expect(riskSummary(null)).toBeNull()
		expect(riskSummary({})).toBeNull()
		expect(riskSummary({ riskAssessment: null })).toBeNull()
		expect(riskSummary({ riskAssessment: [] })).toBeNull()
		expect(riskSummary({ riskAssessment: { band: 'high' } })).toBeNull()
		expect(riskSummary({ riskAssessment: { score: 'many', band: 'high' } })).toBeNull()
		expect(riskSummary({ riskAssessment: { score: true } })).toBeNull()
	})

	it('drops basis rows without a property and reads missing values as text', () => {
		expect(riskBasisRows([
			{ property: 'amount', value: 10, points: '3' },
			{ property: 'owner.kind' },
			{ property: '', value: 'x', points: 1 },
			{ value: 'y', points: 1 },
			null,
			'amount',
		])).toEqual([
			{ property: 'amount', value: '10', points: 3 },
			{ property: 'owner.kind', value: '', points: 0 },
		])
		expect(riskBasisRows(undefined)).toEqual([])
		expect(riskBasisRows({ property: 'amount' })).toEqual([])
	})

	it('reads the assessment on the approval tab of the decision page', () => {
		const tab = read('../../src/components/tabs/DecisionApprovalTab.vue')

		expect(tab).toContain("import { riskSummary } from '../../utils/riskAssessment.js'")
		expect(tab).toContain("fetchObject('decision', this.objectId)")
		expect(tab).toContain('data-testid="decision-risk-assessment"')
		for (const text of STRINGS) {
			expect(tab, text).toContain(`'${text}'`)
		}
	})

	it('has its strings in every catalogue, in Dutch translated', () => {
		const en = JSON.parse(read('../../l10n/en.json')).translations
		const nl = JSON.parse(read('../../l10n/nl.json')).translations
		for (const text of STRINGS) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
			expect(nl[text], text).not.toBe(text)
		}
	})
})
