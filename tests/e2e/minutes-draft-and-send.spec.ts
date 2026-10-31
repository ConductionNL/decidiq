/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the secretary drafts the minutes from the meeting and sends
 * approved minutes to the members (change minutes-draft-and-send, matrix rows
 * min-01, min-02 and min-07). The AI draft needs a TaskProcessing provider, so
 * "Use as minutes" is covered by tests/vitest/minutesDraftAndSend.spec.js.
 *
 * @spec openspec/specs/p2-minutes-and-decisions/spec.md
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

// @e2e p2-minutes-and-decisions::the-secretary-starts-from-a-draft
test('Draft from the meeting writes the attendance into the minutes', async ({
	page,
}) => {
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
	})
	const anna = await createObject(page, ledger, 'participant', {
		displayName: `${tag}-Anna`,
		governanceBody: idOf(body),
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-14-oktober`,
		governanceBody: idOf(body),
		scheduledDate: '2026-10-14T19:30:00Z',
	})
	await createObject(page, ledger, 'meeting-attendance', {
		meeting: idOf(meeting),
		participant: idOf(anna),
		status: 'present',
	})
	const minutes = await createObject(page, ledger, 'minutes', {
		title: `${tag}-notulen`,
		lifecycle: 'draft',
		meeting: idOf(meeting),
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/minutes/${idOf(minutes)}`)
	await page.getByTestId('minutes-draft-from-meeting').click()
	await expect(page.getByTestId('minutes-actions-tab')).toContainText(
		'The draft is in the minutes',
	)
	await page.reload()
	await expect(page.getByText(`Aanwezig (1): ${tag}-Anna`)).toBeVisible()
})

// @e2e p2-minutes-and-decisions::members-receive-the-minutes
test('approved minutes offer Send to members', async ({ page }) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-21-oktober`,
		scheduledDate: '2026-10-21T19:30:00Z',
	})
	const minutes = await createObject(page, ledger, 'minutes', {
		title: `${tag}-notulen-vastgesteld`,
		lifecycle: 'approved',
		meeting: idOf(meeting),
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/minutes/${idOf(minutes)}`)
	await page.getByTestId('minutes-send-to-members').click()
	await expect(page.getByTestId('minutes-actions-tab')).toContainText(
		'Members told',
	)
})
