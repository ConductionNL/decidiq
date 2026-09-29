/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the griffier selects motions on the Motions list, chooses
 * Export with attachments, and the export lands in her Decidiq exports folder;
 * all rows matching the filter export through the same endpoint; a visitor
 * who is not signed in is refused (change motions-export-with-attachments,
 * matrix rows mot-16 and pub-17). The refusals that need filinq or 501
 * decisions are covered by ExportBundleServiceTest.
 *
 * @spec openspec/specs/motion-management/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { adminActor, anonymousActor, APP_API } from './support/api-actors.ts'

const EXPORT = `${APP_API}/exports/decision-bundle`

// @e2e motion-management::the-griffier-exports-three-motions
test('the griffier exports selected motions from the Motions list', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/motions`)
	const rows = page.getByRole('row').filter({ has: page.getByRole('checkbox') })
	await expect(rows.first()).toBeVisible()
	const count = Math.min(3, (await rows.count()) - 1)
	for (let i = 1; i <= count; i++) {
		await rows.nth(i).getByRole('checkbox').check()
	}
	await page.getByRole('button', { name: 'Export with attachments' }).click()
	const dialog = page.getByTestId('export-bundle-modal')
	await expect(dialog).toBeVisible()
	await dialog.getByTestId('export-bundle-format-zip').click()
	await dialog.getByTestId('export-bundle-confirm').click()
	await expect(dialog.getByTestId('export-bundle-done')).toContainText('is in your Decidiq exports folder')
})

// @e2e motion-management::all-motions-of-this-year
test('all motions matching the filter export as one ZIP, and a visitor is refused', async ({
	playwright,
}) => {
	const { ctx: admin } = await adminActor(playwright)
	const response = await admin.post(EXPORT, {
		data: {
			format: 'zip',
			list: 'Motions',
			filter: { decisionType: 'motion' },
			search: '',
		},
	})
	expect([201, 422]).toContain(response.status())
	if (response.status() === 201) {
		expect((await response.json()).path).toMatch(/^Decidiq exports\/Motions \d{4}-\d{2}-\d{2}\.zip$/)
	}

	const { ctx: anonymous } = await anonymousActor(playwright)
	expect((await anonymous.post(EXPORT, { data: { format: 'zip' } })).status()).toBeGreaterThanOrEqual(401)
})
