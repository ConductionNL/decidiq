/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: an administrator declares document types with extra fields,
 * and a clerk fills them in per file on the agenda item page (change
 * platform-document-metadata-fields, matrix row plt-20). The save guard and the
 * one-record-per-file rule are covered by DocumentTypeFieldsGuardListenerTest;
 * the record payload against the register schema by documentMetadata.spec.js.
 *
 * Needs an agenda item "Vaststelling omgevingsvisie" with at least one file,
 * and the seeded document types "Raadsvoorstel" (agenda items) and
 * "Presentatie" (meetings only) from the municipality profile.
 *
 * @spec openspec/specs/document-metadata-fields/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

// @e2e document-metadata-fields::the-administrator-adds-a-field-without-the-supplier
test('an administrator opens the document types page', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/document-types`)
	await expect(page.getByText('Raadsvoorstel')).toBeVisible()
})

// @e2e document-metadata-fields::the-clerk-records-the-zaaknummer-of-a-raadsvoorstel
test('the clerk records the zaaknummer of a raadsvoorstel', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await page.getByText('Vaststelling omgevingsvisie').first().click()
	const row = page.getByTestId('document-details-row').first()
	await row.getByTestId('document-details-open').click()
	const dialog = page.getByTestId('document-details-dialog')
	await dialog.getByTestId('document-details-type').click()
	await page.getByRole('option', { name: 'Raadsvoorstel' }).click()
	await dialog.getByLabel('Zaaknummer').fill('Z-2026-00412')
	await dialog.getByTestId('document-details-save').click()
	await expect(row).toContainText('Raadsvoorstel')
	await expect(row).toContainText('Z-2026-00412')
})

// @e2e document-metadata-fields::a-type-for-meetings-is-not-offered-on-an-agenda-item
test('a meeting-only type is not offered on an agenda item file', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await page.getByText('Vaststelling omgevingsvisie').first().click()
	await page.getByTestId('document-details-open').first().click()
	await page.getByTestId('document-details-type').click()
	await expect(page.getByRole('option', { name: 'Presentatie' })).toHaveCount(0)
})
