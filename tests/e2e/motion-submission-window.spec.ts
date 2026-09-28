/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the submission window for motions and amendments (change
 * motions-submission-window). Writes go through the OpenRegister object API,
 * which is where SubmissionDeadlineListener refuses them with a 422.
 *
 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`

/**
 * An ISO timestamp a number of days from now.
 *
 * @param days Days from now, negative for the past.
 */
function daysFromNow(days: number): string {
	return new Date(Date.now() + days * 86_400_000).toISOString()
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e motion-amendment::the-griffier-sets-the-window
test('the meeting page shows when submission opens and closes', async ({ page }) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-window-shown`,
		meetingType: 'regular',
		scheduledDate: daysFromNow(14),
		submissionOpensAt: daysFromNow(4),
		submissionDeadline: daysFromNow(13),
	})
	const id = meeting.id ?? meeting['@self']?.id
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${id}`)
	await expect(page.getByText('Submission opens', { exact: true })).toBeVisible({
		timeout: 20_000,
	})
	await expect(
		page.getByText('Submission deadline', { exact: true }),
	).toBeVisible()
})

// @e2e motion-amendment::a-member-is-too-early
// @e2e motion-amendment::inside-the-window
test('a motion before the window opens is refused, inside it is created', async ({
	page,
}) => {
	const early = await createObject(page, ledger, 'meeting', {
		title: `${tag}-not-yet-open`,
		meetingType: 'regular',
		scheduledDate: daysFromNow(14),
		submissionOpensAt: daysFromNow(4),
		submissionDeadline: daysFromNow(13),
	})
	const headers = await writeHeaders(page)
	const refused = await page.request.post(`${OR}/decision`, {
		headers,
		data: {
			decisionType: 'motion',
			title: `${tag}-too-early`,
			meeting: early.id ?? early['@self']?.id,
		},
	})
	expect(refused.status()).toBe(422)
	expect(await refused.text()).toContain('opens on')

	const open = await createObject(page, ledger, 'meeting', {
		title: `${tag}-open`,
		meetingType: 'regular',
		scheduledDate: daysFromNow(14),
		submissionOpensAt: daysFromNow(-1),
		submissionDeadline: daysFromNow(13),
	})
	await createObject(page, ledger, 'decision', {
		decisionType: 'motion',
		title: `${tag}-in-time`,
		meeting: open.id ?? open['@self']?.id,
	})
})

// @e2e motion-amendment::a-typo-in-the-dates
test('a window that opens after it closes cannot be saved', async ({ page }) => {
	const headers = await writeHeaders(page)
	const resp = await page.request.post(`${OR}/meeting`, {
		headers,
		data: {
			title: `${tag}-inverted`,
			meetingType: 'regular',
			scheduledDate: daysFromNow(14),
			submissionOpensAt: daysFromNow(10),
			submissionDeadline: daysFromNow(9),
		},
	})
	expect(resp.status()).toBe(422)
	expect(await resp.text()).toContain(
		'The submission window opens after it closes.',
	)
})
