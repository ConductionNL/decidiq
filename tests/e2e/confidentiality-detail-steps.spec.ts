/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the confidentiality restriction's detail page shows the
 * board's steps (change confidentiality-term-and-lapse, task 4, REQ-EMB-023).
 *
 * WHAT THIS ASSERTS THAT VITEST CANNOT
 * ------------------------------------
 * tests/vitest/registerDetailWidgets.spec.js pins buildConfidentialityStepper,
 * buildConfidentialityStages and ratificationHeaderLine per stage. Only a real
 * instance shows that the detail page mounts the header widget and the
 * timeline on the stored record, resolves the ratifying body's name for the
 * header line, and keeps the steps that have not happened on screen as
 * planned instead of leaving them out.
 *
 * It reads two restrictions of the municipality example set and writes
 * nothing: the one imposed on 10 September 2026, sent on 24 September, with
 * ratification planned for 15 October and lifting "Na gunning van de
 * grondexploitatie" by 1 July 2027; and the attachment whose restriction
 * lapsed. Without the example set they are not there and the suite skips,
 * saying so.
 *
 * Written, not run: it needs a live instance with the municipality example set.
 *
 * @spec openspec/changes/archive/2026-11-24-confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board
 * @e2e embargo-geheimhouding::an-imposed-restriction-before-the-council-meets
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE, listObjects, objId } from './workflows/governance-fixture.ts'

const APP = `${BASE}/index.php/apps/decidiq`
const SCHEMA = 'confidentiality-restriction'

/**
 * The seeded restriction that matches, or null when the example set is not loaded.
 *
 * @param page  The page whose session reads the register.
 * @param match The condition on the stored record.
 * @return The restriction, or null.
 */
async function restriction(page: Page, match: (r: Record<string, any>) => boolean): Promise<Record<string, any> | null> {
	const rows = await listObjects(page, SCHEMA, 500)
	return rows.find(match) ?? null
}

test.describe('the restriction page shows the board\'s steps (REQ-EMB-023)', () => {
	// @e2e embargo-geheimhouding::an-imposed-restriction-before-the-council-meets
	test('an imposed restriction before the council meets: header line, stepper and four steps', async ({ page }) => {
		const record = await restriction(page, (r) => r.lifecycle === 'imposed'
			&& r.ratificationDeadline === '2026-10-15'
			&& String(r.liftingConditions ?? '').startsWith('Na gunning'))
		test.skip(record === null, 'the municipality example set is not loaded')

		// The scenario is "before the council meets": the page reads the clock
		// for the overdue flags, so it is set to a day after sending and before
		// the 15 October deadline. Run on a later day, the ratification step
		// would rightly read Overdue instead of Pending.
		await page.clock.install({ time: new Date('2026-10-01T12:00:00+02:00') })
		await page.goto(`${APP}/confidentiality-restrictions/${objId(record)}`)

		// The header line, while ratification is open, names the body and the date.
		const line = page.getByTestId('confidentiality-header-line')
		await expect(line).toBeVisible()
		await expect(line).toContainText('Ratification by')
		await expect(line).toContainText('2026')
		await expect(line).toContainText('15')

		// The stepper: imposed now, ratified and lifted still planned.
		await expect(page.getByTestId('confidentiality-step-imposed')).toContainText('now')
		await expect(page.getByTestId('confidentiality-step-ratified')).toContainText('planned')
		await expect(page.getByTestId('confidentiality-step-dissolved')).toContainText('planned')

		// Four steps, none left out: imposed and sent have happened, ratification
		// and lifting are pending.
		const list = page.getByTestId('confidentiality-timeline-list')
		await expect(list.locator(':scope > li')).toHaveCount(4)
		await expect(page.getByTestId('confidentiality-stage-imposed')).not.toContainText('Pending')
		await expect(page.getByTestId('confidentiality-stage-sent')).toContainText('Sent to the body with the proposal')
		await expect(page.getByTestId('confidentiality-stage-sent')).not.toContainText('Pending')
		await expect(page.getByTestId('confidentiality-stage-ratification')).toContainText('Pending')
		const lifting = page.getByTestId('confidentiality-stage-dissolution')
		await expect(lifting).toContainText('Pending')
		await expect(lifting.getByTestId('confidentiality-lifting-conditions')).toContainText('Na gunning van de grondexploitatie')
		await expect(lifting).toContainText('2027')
	})

	test('a lapsed restriction shows Lapsed in the stepper and the timeline, and no header line', async ({ page }) => {
		const record = await restriction(page, (r) => r.lifecycle === 'lapsed')
		test.skip(record === null, 'the municipality example set has no lapsed restriction')

		await page.goto(`${APP}/confidentiality-restrictions/${objId(record)}`)

		await expect(page.getByTestId('confidentiality-step-lapsed')).toBeVisible()
		await expect(page.getByTestId('confidentiality-step-ratified')).toHaveCount(0)
		await expect(page.getByTestId('confidentiality-stage-ratification')).toContainText('Lapsed')
		await expect(page.getByTestId('confidentiality-header-line')).toHaveCount(0)
	})
})
