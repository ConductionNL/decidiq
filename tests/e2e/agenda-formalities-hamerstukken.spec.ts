/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: formalities (hamerstukken) are marked on the meeting's
 * agenda and adopted together on the live screen (change
 * agenda-formalities-hamerstukken, matrix row age-07).
 *
 * @spec openspec/specs/agenda-live-management/spec.md
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

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e agenda-live-management::the-chair-adopts-the-formalities
test('three formalities are adopted together without debate', async ({ page }) => {
	const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		scheduledDate: new Date().toISOString(),
	})
	const meetingId = idOf(meeting)
	const items = []
	for (const n of [3, 4, 5, 7]) {
		items.push(
			await createObject(page, ledger, 'agenda-item', {
				title: `${tag}-item-${n}`,
				meeting: meetingId,
				orderNumber: n,
			}),
		)
	}

	const headers = await writeHeaders(page)
	for (const item of [items[0], items[1], items[3]]) {
		const marked = await page.request.put(
			`${BASE}/index.php/apps/decidiq/api/agendas/${meetingId}/items/${idOf(item)}/formality`,
			{ headers, data: { isFormality: true } },
		)
		expect(marked.ok()).toBe(true)
	}

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${meetingId}/live`)
	await page.getByTestId('meeting-live-adopt-consent').click()
	await page.getByRole('dialog').getByRole('button', { name: /adopt/i }).click()

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${meetingId}`)
	const agenda = page.getByTestId('agenda-tab')
	for (const n of [3, 4, 7]) {
		await expect(
			agenda.getByRole('row').filter({ hasText: `${tag}-item-${n}` }),
		).toContainText('Adopted without debate', { timeout: 20_000 })
	}
	await expect(
		agenda.getByRole('row').filter({ hasText: `${tag}-item-5` }),
	).not.toContainText('Adopted without debate')
})
