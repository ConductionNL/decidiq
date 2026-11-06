/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the administrator presses Export all data on the admin
 * settings page, the job is queued, and a download name that is not an
 * export answers 404; a non-administrator is refused (change
 * platform-full-data-export, matrix row pub-15). The archive itself is built
 * by a background job, so its content is covered by FullExportTest.
 *
 * @spec openspec/specs/openregister-integration/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { adminActor, anonymousActor } from './support/api-actors.ts'

const EXPORT = `${BASE}/index.php/apps/decidiq/api/export/full`

// @e2e openregister-integration::the-organisation-leaves
// @e2e openregister-integration::only-administrators-export-and-only-exports-download
test('the administrator starts the export from the settings page', async ({
	page,
	playwright,
}) => {
	await page.goto(`${BASE}/index.php/settings/admin/decidiq`)
	const panel = page.getByTestId('full-export-settings')
	await expect(panel).toBeVisible()
	await panel.getByTestId('full-export-start').click()
	await expect(panel).toContainText('The export is being prepared')

	const { ctx: admin } = await adminActor(playwright)
	expect((await admin.get(`${EXPORT}/..%2F..%2Fconfig.php`)).status()).toBe(404)

	const { ctx: anonymous } = await anonymousActor(playwright)
	expect((await anonymous.post(EXPORT)).status()).toBeGreaterThanOrEqual(401)
})
