
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the griffier reports progress on an adopted motion from its
 * page, the motion's implementation status follows the newest update, a
 * rejected motion takes no update, and the Motions list filters on the status
 * (change followup-implementation-progress, tasks 3 and 4).
 *
 * Written, not run: it needs a live instance and an account in the
 * secretariat (the implementation-update schema grants create to the
 * secretariat and administrators only; the default e2e account is an
 * administrator). Every object it reads is created here and removed
 * afterwards. The 90-day reminder (REQ-FUP-004) fires from OpenRegister's
 * scheduled notification run and is proven by tests/integration/decidiq-implementation-progress.postman_collection.json.
 *
 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	getObject,
	newLedger,
	OR,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id
const APP = `${BASE}/index.php/apps/decidiq`

/**
 * An adopted motion, as the council left it after the vote.
 *
 * @param page The page whose session seeds it.
 * @param title The motion's title.
 * @return The stored motion.
 */
async function adoptedMotion(page: Page, title: string): Promise<Record<string, any>> {
	return createObject(page, ledger, 'decision', {
		title,
		decisionType: 'motion',
		proposer: 'Anna',
		lifecycle: 'decided',
		outcome: 'adopted',
		decisionDate: '2026-01-29',
	})
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e decision-management::the-griffier-reports-progress-on-a-motion
// @e2e decision-management::the-status-follows-the-newest-update
test('the griffier adds an update on the motion page and the status follows it', async ({ page }) => {
	const motion = await adoptedMotion(page, `${tag}-Motie meer groen in de wijk`)
	await createObject(page, ledger, 'implementation-update', {
		decision: idOf(motion),
		reportedOn: '2026-03-01',
		status: 'on-track',
		text: `${tag}-Plan van aanpak vastgesteld`,
	})

	await page.goto(`${APP}/motions/${idOf(motion)}`)
	const widget = page.locator('section, div').filter({ has: page.getByRole('heading', { name: 'Implementation' }) }).last()
	await expect(widget.getByText(`${tag}-Plan van aanpak vastgesteld`)).toBeVisible()

	await widget.getByRole('button', { name: /add/i }).first().click()
	const dialog = page.getByRole('dialog')
	await dialog.getByLabel(/date/i).first().fill('2026-06-15')
	await dialog.getByLabel(/status/i).first().click()
	await page.getByRole('option', { name: /delayed/i }).click()
	await dialog.getByLabel(/percent/i).first().fill('45')
	await dialog.getByLabel(/progress|text/i).first().fill(`${tag}-Aanbesteding uitgesteld tot na de zomer`)
	await dialog.getByRole('button', { name: /save|create|add/i }).last().click()
	await expect(dialog).toHaveCount(0)

	// Newest first: the June update sits above the March one.
	const rows = widget.getByRole('row').filter({ hasText: tag })
	await expect(rows.nth(0)).toContainText(`${tag}-Aanbesteding uitgesteld tot na de zomer`)
	await expect(rows.nth(1)).toContainText(`${tag}-Plan van aanpak vastgesteld`)

	await expect.poll(async () => (await getObject(page, 'decision', idOf(motion)))?.implementationStatus).toBe('delayed')
	const stored = await getObject(page, 'decision', idOf(motion))
	expect(String(stored?.implementationStatusDate ?? '')).toContain('2026-06-15')
})

// @e2e decision-management::a-rejected-motion-takes-no-updates
test('an update on a rejected motion is refused', async ({ page }) => {
	const motion = await createObject(page, ledger, 'decision', {
		title: `${tag}-Verworpen motie`,
		decisionType: 'motion',
		proposer: 'Anna',
		lifecycle: 'decided',
		outcome: 'rejected',
		decisionDate: '2026-01-29',
	})

	const response = await page.request.post(`${OR}/implementation-update`, {
		headers: await writeHeaders(page),
		data: {
			decision: idOf(motion),
			reportedOn: '2026-06-15',
			status: 'on-track',
			text: `${tag}-Mag niet`,
		},
	})
	expect(response.status()).toBe(422)
	expect(await response.text()).toMatch(/adopted/i)
})

// @e2e decision-management::the-council-asks-which-motions-are-delayed
test('the Motions list filters on implementation status', async ({ page }) => {
	const statuses = ['on-track', 'delayed', 'completed']
	for (const status of statuses) {
		const motion = await adoptedMotion(page, `${tag}-motie-${status}`)
		await createObject(page, ledger, 'implementation-update', {
			decision: idOf(motion),
			reportedOn: '2026-06-15',
			status,
			text: `${tag}-${status}`,
		})
	}

	await page.goto(`${APP}/motions?implementationStatus=delayed`)
	await expect(page.getByText(`${tag}-motie-delayed`)).toBeVisible()
	await expect(page.getByText(`${tag}-motie-on-track`)).toHaveCount(0)
	await expect(page.getByText(`${tag}-motie-completed`)).toHaveCount(0)
})
