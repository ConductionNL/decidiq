/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: members are reminded before a meeting and before its
 * submission deadline (change meeting-reminders-before-deadlines, matrix rows
 * pla-06 and plt-09). The reminders come from an hourly background job, so
 * this spec expects the instance's cron to have run after the setup step
 * (`occ background-job:execute` on MeetingReminderJob in CI).
 *
 * @spec openspec/specs/decidesk-notifications/spec.md
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

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e decidesk-notifications::pieter-is-reminded
// @e2e decidesk-notifications::the-deadline-reminder
test('a member is reminded of a meeting tomorrow and of its submission deadline', async ({
	page,
}) => {
	await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		lifecycle: 'scheduled',
		scheduledDate: new Date(Date.now() + 23 * 3600 * 1000).toISOString(),
		submissionDeadline: new Date(Date.now() + 20 * 3600 * 1000).toISOString(),
	})

	await page.goto(`${BASE}/index.php/apps/notifications`)
	await expect(
		page.getByText(`The meeting ${tag}-raad is coming up`).first(),
	).toBeVisible()
	await expect(
		page
			.getByText(`The submission deadline of ${tag}-raad is coming up`)
			.first(),
	).toBeVisible()
})
