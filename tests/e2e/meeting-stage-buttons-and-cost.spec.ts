/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the chair moves a meeting through its stages from the
 * meeting page, and closing it records its cost (change
 * meeting-stage-buttons-and-cost, matrix rows pla-15 and pla-10).
 *
 * @spec openspec/specs/meeting-workflow/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e meeting-workflow::the-chair-opens-the-meeting
test('the chair opens a convened meeting from the meeting page', async ({
	page,
}) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: new Date().toISOString(),
		lifecycle: 'scheduled',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}`)
	await page.getByTestId('meeting-stage-open').click()
	await expect(page.getByTestId('meeting-stage')).toHaveText(/in session/i)
})

// @e2e meeting-workflow::the-cost-appears-after-closing
test('closing the meeting shows its cost', async ({ page }) => {
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
		hourlyRate: 50,
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-sluiten`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: new Date().toISOString(),
		lifecycle: 'opened',
		governanceBody: idOf(body),
		openedAt: new Date(Date.now() - 2 * 3600 * 1000).toISOString(),
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}`)
	await page.getByTestId('meeting-stage-close').click()
	await expect(page.getByTestId('meeting-stage')).toHaveText(/closed/i)
	await expect(page.getByTestId('meeting-stage-cost')).toBeVisible()
})
