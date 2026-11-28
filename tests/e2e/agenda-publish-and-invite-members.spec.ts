/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the secretary publishes the agenda from the meeting page,
 * the members are invited, and a public meeting's agenda can then go public
 * (change agenda-publish-and-invite-members, matrix rows age-05 and pla-05).
 *
 * @spec openspec/specs/agenda-publication/spec.md
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

async function councilWithAgenda(page, isPublic: boolean) {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: '2026-10-08T17:30:00Z',
		location: 'Raadzaal',
		isPublic,
	})
	for (const [n, title] of [
		'Opening',
		'Vaststelling agenda',
		'Begroting',
		'Rondvraag',
		'Sluiting',
	].entries()) {
		await createObject(page, ledger, 'agenda-item', {
			title: `${tag}-${title}`,
			meeting: idOf(meeting),
			orderNumber: n + 1,
		})
	}
	return meeting
}

// @e2e agenda-publication::members-are-invited
test('the secretary publishes the agenda from the meeting page', async ({
	page,
}) => {
	const meeting = await councilWithAgenda(page, false)

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}`)
	await page.getByTestId('agenda-publish').click()
	await expect(page.getByTestId('agenda-published-on')).toBeVisible()

	await page.goto(`${BASE}/index.php/apps/notifications`)
	await expect(
		page.getByText(`The agenda of ${tag}-raad was published`).first(),
	).toBeVisible()
})

// @e2e agenda-publication::the-public-sees-the-agenda
test('a public meeting with a published agenda can go public', async ({ page }) => {
	const meeting = await councilWithAgenda(page, true)

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}`)
	await page.getByTestId('agenda-publish').click()
	await expect(page.getByTestId('agenda-published-on')).toBeVisible()
	await page.reload()
	await expect(
		page.getByRole('button', { name: /^publish$/i }).first(),
	).toBeEnabled()
})
