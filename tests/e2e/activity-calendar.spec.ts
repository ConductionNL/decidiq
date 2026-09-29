/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the meeting calendar filters by audience and body and keeps
 * the filter in the address; a public meeting is published to the residents'
 * calendar from its Publication widget (change
 * planning-activity-calendar-by-audience, matrix row pla-16). The payload's
 * allow-list and the refusal of a non-public meeting are covered by
 * ActivityPublicationTest; the month query by meetingCalendarLoad.spec.js.
 *
 * @spec openspec/specs/activity-calendar/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

// @e2e activity-calendar::a-clerk-looks-at-what-is-on-for-the-executive
test('the calendar filters on an audience and keeps it in the address', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/calendar`)
	await expect(page.getByTestId('meeting-calendar')).toBeVisible()
	await page.getByTestId('meeting-calendar-audience').click()
	await page.getByRole('option', { name: 'Executive' }).click()
	await expect(page).toHaveURL(/audience=executive/)
})

// @e2e activity-calendar::a-meeting-without-a-type-stays-findable
test('No audience set is offered as a filter', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/calendar?audience=none`)
	await expect(page.getByTestId('meeting-calendar')).toBeVisible()
	await expect(page.getByTestId('meeting-calendar-audience')).toContainText('No audience set')
})
