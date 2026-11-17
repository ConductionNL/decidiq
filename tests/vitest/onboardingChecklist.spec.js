// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * bodies-onboarding-checklist-walkthrough (matrix row bod-08): the secretary
 * marks the steps of an onboarding or offboarding on its page.
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	checklistRows,
	isMandatory,
	kindOf,
	stepPath,
} from '../../src/utils/onboardingChecklist.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('the onboarding checklist (REQ-MOBW-001)', () => {
	it('offers done on an open step and skip only on an optional one', () => {
		const record = {
			lifecycle: 'in-execution',
			steps: [
				{ stepType: 'installation', title: 'Record the installation', status: 'open' },
				{ stepType: 'induction-pack', title: 'Send the induction pack', status: 'open', mandatory: false },
				{ stepType: 'account-linking', title: 'Link the account', status: 'completed' },
				{ stepType: 'custom', title: 'Coffee with the clerk', status: 'skipped', mandatory: false },
			],
		}

		expect(checklistRows('onboarding', record)).toEqual([
			{ index: 0, title: 'Record the installation', status: 'open', mandatory: true, canComplete: true, canSkip: false },
			{ index: 1, title: 'Send the induction pack', status: 'open', mandatory: false, canComplete: true, canSkip: true },
			{ index: 2, title: 'Link the account', status: 'completed', mandatory: true, canComplete: false, canSkip: false },
			{ index: 3, title: 'Coffee with the clerk', status: 'skipped', mandatory: false, canComplete: false, canSkip: false },
		])
	})

	it('a finished record offers nothing, and broken steps keep the indexes of the rest', () => {
		const rows = checklistRows('onboarding', {
			lifecycle: 'completed',
			steps: [null, { stepType: 'assign-groups', status: 'open' }],
		})

		expect(rows).toEqual([
			{ index: 1, title: 'assign-groups', status: 'open', mandatory: true, canComplete: false, canSkip: false },
		])
		expect(checklistRows('onboarding', null)).toEqual([])
	})

	it('the revoke-groups step of an offboarding is always mandatory, as the server reads it', () => {
		expect(isMandatory('offboarding', { stepType: 'revoke-groups', mandatory: false })).toBe(true)
		expect(isMandatory('onboarding', { stepType: 'custom', mandatory: false })).toBe(false)
		expect(isMandatory('offboarding', { stepType: 'exit-confirmation' })).toBe(true)
		const [row] = checklistRows('offboarding', {
			lifecycle: 'started',
			steps: [{ stepType: 'revoke-groups', status: 'open', mandatory: false }],
		})
		expect(row.canSkip).toBe(false)
	})

	it('puts to the step route of the record kind', () => {
		expect(kindOf('member-onboarding')).toBe('onboarding')
		expect(kindOf('member-offboarding')).toBe('offboarding')
		expect(kindOf('membership')).toBeNull()
		expect(stepPath('offboarding', 'r 1', 2)).toBe('/apps/decidiq/api/onboarding/offboarding/r%201/steps/2')
		expect(read('appinfo/routes.php')).toContain("'url' => '/api/onboarding/{kind}/{id}/steps/{index}', 'verb' => 'PUT'")
	})

	it('both detail pages show the checklist widget', () => {
		const fragment = JSON.parse(read('src/manifest.d/member-onboarding.json'))
		for (const [pageId, widgetId] of [['MemberOnboardingDetail', 'onboarding-checklist'], ['MemberOffboardingDetail', 'offboarding-checklist']]) {
			const page = fragment.pages.find((p) => p.id === pageId)
			expect(page.config.widgets.find((w) => w.id === widgetId)).toMatchObject({ type: 'custom', component: 'OnboardingChecklistTab' })
			expect(page.config.layout.some((l) => l.widgetId === widgetId)).toBe(true)
			expect(page.slots[`widget-${widgetId}`]).toBe('OnboardingChecklistTab')
		}
		expect(read('src/registry.js')).toMatch(/OnboardingChecklistTab: page\(OnboardingChecklistTab\)/)
	})
})
