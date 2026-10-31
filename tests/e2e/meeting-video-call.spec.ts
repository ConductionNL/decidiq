/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a digital or hybrid meeting shows its video call on the
 * meeting page (change meeting-video-call, matrix row pla-08). Needs Talk.
 *
 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md
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

// @e2e digital-meetings-and-recurrence::a-member-joins-the-call
test('a hybrid meeting gets a video call that members can join', async ({
	page,
}) => {
	const hybrid = await createObject(page, ledger, 'meeting', {
		title: `${tag}-commissie`,
		meetingMode: 'hybrid',
		scheduledDate: '2026-10-14T19:30:00Z',
	})
	const inPerson = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingMode: 'in-person',
		scheduledDate: '2026-10-15T19:30:00Z',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(hybrid)}`)
	await page.getByTestId('meeting-video-call-create').click()
	const join = page.getByTestId('meeting-video-call-join')
	await expect(join).toBeVisible()
	await expect(join).toHaveAttribute('href', /\/call\//)

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(inPerson)}`)
	await expect(
		page.getByTestId('meeting-agenda-tab').or(page.getByTestId('agenda-tab')),
	).toBeVisible()
	await expect(page.getByTestId('meeting-video-call')).toHaveCount(0)
})
