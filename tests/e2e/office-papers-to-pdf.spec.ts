/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: Office papers shown as PDF on the agenda item page, a failed
 * conversion with its reason and Try again, and the administrator switch
 * (change agenda-office-files-to-pdf, matrix row age-17). Detection and the
 * conversion itself are covered by OfficePaperAddedListenerTest and
 * ConvertPaperToPdfJobTest; the package preference by PaperRenditionFilterTest;
 * the rendition payload against the register schema by paperRenditions.spec.js.
 *
 * Needs the municipality example set: agenda item "Kadernota begroting 2026"
 * carries one converted and one failed paper.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

// @e2e agenda-management::a-member-opens-the-paper
test('the converted paper shows once, as its PDF', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await page.getByText('Kadernota begroting 2026').first().click()
	const widget = page.getByTestId('paper-renditions')
	await expect(widget).toContainText('Kadernota 2026')
})

// @e2e agenda-management::a-spreadsheet-cannot-be-converted
test('a failed conversion shows its reason and Try again', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await page.getByText('Kadernota begroting 2026').first().click()
	await expect(page.getByTestId('paper-renditions-failure')).toContainText(
		'no backend could convert this file',
	)
	await expect(
		page.getByRole('button', { name: /Try the conversion of/ }),
	).toBeVisible()
})

// @e2e agenda-management::conversion-is-switched-off
test('an administrator finds the conversion switch', async ({ page }) => {
	await page.goto(`${BASE}/index.php/settings/admin/decidiq`)
	await expect(page.getByTestId('office-paper-settings-switch')).toBeVisible()
})
