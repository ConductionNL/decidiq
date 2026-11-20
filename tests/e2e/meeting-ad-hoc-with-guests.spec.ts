// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: the Nieuw overleg page of board DcAdhocOverleg
 * (component AdhocMeetingPage, manifest page AdhocMeetingNew)
 * (meeting-ad-hoc-with-guests Task 3, pla-20).
 *
 * WHAT THIS ASSERTS THAT VITEST CANNOT
 * ------------------------------------
 * The unit suite (tests/vitest/adhocMeeting.spec.js) proves the payloads
 * validate against the merged register schemas and the steps run in order.
 * It cannot prove the route resolves above /meetings/:id, that the register
 * accepts the objects the page writes as a signed-in organiser, or that the
 * page lands on the new meeting with its agenda and guest attached.
 *
 * The invitation mail itself is not read here: that needs a mail catcher on
 * the instance (tasks.md Task 3, Playwright half).
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupAll,
	listObjects,
	newLedger,
} from './workflows/governance-fixture.ts'

const APP_BASE = '/apps/decidiq'
const ledger = newLedger()
const title = `Overleg e2e ${ledger.runId}`

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e meeting-management::the-organiser-sets-up-a-meeting-with-guests-on-one-page
test('AdhocMeetingPage: the organiser sets up a meeting without a body, its agenda and a guest on one page', async ({
	page,
}) => {
	await page.goto(`${APP_BASE}/meetings`, { waitUntil: 'domcontentloaded' })
	await dismissSetupWizard(page)
	await page.getByTestId('meeting-new-adhoc').click()
	await expect(page).toHaveURL(/\/meetings\/new$/)

	await page.getByTestId('adhoc-title').locator('input').fill(title)
	await page.locator('#adhoc-start').fill('2027-03-02T15:00')
	await page
		.getByTestId('adhoc-location')
		.locator('input')
		.fill('Stadskantoor, kamer 2.14')
	await page
		.getByTestId('adhoc-agenda-input')
		.locator('input')
		.fill('Opening en kennismaking')
	await page.getByTestId('adhoc-agenda-add').click()

	await page.getByTestId('adhoc-guest-add').click()
	await page.getByTestId('guest-invite-name').locator('input').fill('Karim Ouali')
	await page
		.getByTestId('guest-invite-email')
		.locator('input')
		.fill('k.ouali@example.org')
	await page.getByTestId('guest-invite-submit').click()
	await expect(page.getByTestId('adhoc-participants')).toContainText(
		'k.ouali@example.org',
	)

	await page.getByTestId('adhoc-create').click()
	await expect(page).toHaveURL(/\/meetings\/[0-9a-f-]{36}$/, { timeout: 30_000 })

	const meetings = await listObjects(page, 'meeting', 500)
	const meeting = meetings.find((m) => m.title === title)
	expect(meeting, 'the meeting the page created').toBeTruthy()
	ledger.created.meeting = [meeting.id ?? meeting['@self']?.id]
	expect(meeting.governanceBody ?? null).toBeNull()
	expect(meeting.lifecycle).toBe('draft')

	const items = (await listObjects(page, 'agenda-item', 500)).filter(
		(i) => (i.meeting?.id ?? i.meeting) === ledger.created.meeting[0],
	)
	expect(items.map((i) => i.title)).toEqual(['Opening en kennismaking'])
})
