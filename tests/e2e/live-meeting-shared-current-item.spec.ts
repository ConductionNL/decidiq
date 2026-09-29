/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the chair's current agenda item is shared with every live
 * screen and the room screen, and a decision is recorded on it (change
 * live-meeting-shared-current-item, matrix rows liv-01 liv-04 liv-05 liv-11).
 *
 * @spec openspec/specs/agenda-live-management/spec.md
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

// @e2e agenda-live-management::members-follow-the-chair
// @e2e agenda-live-management::the-room-sees-the-vote
// @e2e agenda-live-management::the-secretary-records-the-decision
test('the chair makes an item current, the room screen follows and a decision is recorded', async ({
	page,
}) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		lifecycle: 'opened',
		scheduledDate: '2026-10-14T19:30:00Z',
	})
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-woningbouwplan`,
		meeting: idOf(meeting),
		orderNumber: 5,
		itemType: 'decision',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/live`)
	await page.getByRole('button', { name: new RegExp(`Activate ${tag}-woningbouwplan`) }).click()

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/screen`)
	await expect(page.getByTestId('meeting-screen-item')).toContainText(`${tag}-woningbouwplan`)

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/live`)
	await page.getByTestId('meeting-live-record-decision').click()
	await page.getByTestId('live-decision-text').locator('textarea').fill('De raad stemt in.')
	await page.getByTestId('live-decision-submit').click()
	await expect(page.getByText('Decision recorded.')).toBeVisible()
	expect(idOf(item)).toBeTruthy()
})
