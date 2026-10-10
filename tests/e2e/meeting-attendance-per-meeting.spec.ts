/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the clerk records attendance per meeting on the meeting's
 * Participants widget (change meeting-attendance-per-meeting, matrix row pla-09).
 *
 * @spec openspec/specs/meeting-attendees/spec.md
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

// @e2e meeting-attendees::the-clerk-records-apologies
test('marking Anna as sent apologies on one meeting leaves the other meeting alone', async ({
	page,
}) => {
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
	})
	const anna = await createObject(page, ledger, 'participant', {
		displayName: `${tag}-Anna`,
		governanceBody: idOf(body),
	})
	const earlier = await createObject(page, ledger, 'meeting', {
		title: `${tag}-7-oktober`,
		governanceBody: idOf(body),
		scheduledDate: '2026-10-07T19:30:00Z',
	})
	const later = await createObject(page, ledger, 'meeting', {
		title: `${tag}-14-oktober`,
		governanceBody: idOf(body),
		scheduledDate: '2026-10-14T19:30:00Z',
	})
	await createObject(page, ledger, 'meeting-attendance', {
		meeting: idOf(earlier),
		participant: idOf(anna),
		status: 'present',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(later)}`)
	const widget = page.getByTestId('meeting-participants-tab')
	const row = widget.getByRole('row', { name: new RegExp(`${tag}-Anna`) })
	await row.getByRole('button', { name: /actions/i }).click()
	await page.getByRole('menuitem', { name: 'Mark as sent apologies' }).click()
	await expect(row).toContainText('Sent apologies')

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(earlier)}`)
	await expect(
		page
			.getByTestId('meeting-participants-tab')
			.getByRole('row', { name: new RegExp(`${tag}-Anna`) }),
	).toContainText('Present')
})
