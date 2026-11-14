/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a member declares a conflict of interest on a motion page
 * and is kept out of the vote on it (change bodies-conflict-of-interest-recusal,
 * matrix row bod-10). The logged-in admin plays Anna: she has a participant
 * record in the meeting's body.
 *
 * @spec openspec/specs/conflict-of-interest/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e conflict-of-interest::a-member-declares-a-conflict
// @e2e conflict-of-interest::anna-cannot-vote
test('Anna declares a conflict on motion M-12 and cannot vote on it', async ({
	page,
}) => {
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-vergadering`,
		meetingType: 'regular',
		scheduledDate: new Date().toISOString(),
		governanceBody: idOf(body),
	})
	await createObject(page, ledger, 'participant', {
		displayName: 'Anna',
		nextcloudUserId: 'admin',
		role: 'member',
		governanceBody: idOf(body),
	})
	const motion = await createObject(page, ledger, 'decision', {
		title: `${tag}-M-12`,
		decisionType: 'motion',
		meeting: idOf(meeting),
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/motions/${idOf(motion)}`)
	await page.getByTestId('conflict-declare-open').click()
	await page
		.getByTestId('conflict-declare-reason')
		.locator('textarea')
		.fill('I own land in the plan area')
	await page.getByTestId('conflict-declare-submit').click()
	const row = page
		.getByTestId('conflict-row')
		.filter({ hasText: 'I own land in the plan area' })
	await expect(row).toBeVisible({ timeout: 20_000 })
	await expect(row.getByTestId('conflict-recused')).toBeVisible()

	const headers = await writeHeaders(page)
	const opened = await page.request.post(
		`${BASE}/index.php/apps/decidiq/api/voting-rounds`,
		{
			headers,
			data: {
				motionId: idOf(motion),
				subjectType: 'motion',
				meetingId: idOf(meeting),
			},
		},
	)
	const round = await opened.json()
	const roundId = round.id ?? round.votingRound?.id ?? round['@self']?.id
	const cast = await page.request.post(
		`${BASE}/index.php/apps/decidiq/api/voting-rounds/${roundId}/cast`,
		{ headers, data: { value: 'for' } },
	)
	expect(cast.ok()).toBe(false)
	expect((await cast.json()).message).toContain('I own land in the plan area')
})
