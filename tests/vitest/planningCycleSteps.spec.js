// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * planning-cycle-generate-from-template (matrix row pla-12): the cycle page
 * lists the steps in order with an Overdue marker.
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { isOverdue, stepRows } from '../../src/utils/planningCycleSteps.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')
const TODAY = new Date(2027, 9, 1)

describe('cycle 2027 from the municipal template (REQ-PCG-001)', () => {
	it('lists the steps in sequence order', () => {
		const rows = stepRows(
			[
				{
					id: 's3',
					sequence: 3,
					label: 'Jaarrekening',
					deliveryDeadline: '2027-04-15',
				},
				{
					id: 's1',
					sequence: 1,
					label: 'Kadernota',
					deliveryDeadline: '2027-05-15',
				},
				{
					id: 's2',
					sequence: 2,
					stepType: 'begroting',
					deliveryDeadline: '2027-09-15',
					committeeDate: '2027-10-20',
				},
			],
			TODAY,
		)
		expect(rows.map((r) => r.label)).toEqual([
			'Kadernota',
			'begroting',
			'Jaarrekening',
		])
		expect(rows[1].committeeDate).toBe('2027-10-20')
	})
})

describe('an overdue step is marked (REQ-PCG-001)', () => {
	it('a passed deadline on a step that is not done is overdue', () => {
		expect(
			isOverdue(
				{ deliveryDeadline: '2027-09-15', status: 'in-progress' },
				TODAY,
			),
		).toBe(true)
	})

	it('an adopted or completed step, a future deadline or no deadline is not', () => {
		expect(
			isOverdue({ deliveryDeadline: '2027-09-15', status: 'adopted' }, TODAY),
		).toBe(false)
		expect(
			isOverdue(
				{ deliveryDeadline: '2027-09-15', status: 'completed' },
				TODAY,
			),
		).toBe(false)
		expect(
			isOverdue({ deliveryDeadline: '2027-10-01', status: 'planned' }, TODAY),
		).toBe(false)
		expect(isOverdue({ status: 'planned' }, TODAY)).toBe(false)
	})

	it('the cycle page renders the Steps widget', () => {
		const pages = JSON.parse(read('src/manifest.d/pc-cyclus.json'))
		const list = Array.isArray(pages) ? pages : pages.pages
		const detail = list.find((p) => p.id === 'PlanningCycleDetail')
		expect(
			detail.config.widgets.find((w) => w.id === 'cyclus-steps'),
		).toMatchObject({
			type: 'custom',
			component: 'PlanningCycleStepsTab',
		})
		expect(read('src/registry.js')).toMatch(
			/PlanningCycleStepsTab: page\(PlanningCycleStepsTab\)/,
		)
	})
})
