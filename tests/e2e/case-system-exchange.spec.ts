/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the case system on the agenda item, the minutes page and
 * the meeting page (change platform-case-system-document-exchange, matrix rows
 * plt-23 and plt-24). The adapter paths (an unknown case, the 422 before
 * approval, the confidential flag in the request) are covered by
 * CaseSystemExchangeTest and CaseSystemControllerTest; the widget rules by
 * caseSystem.spec.js.
 *
 * Needs the municipality profile (agenda item "Vaststelling omgevingsvisie"
 * linked to case Z-2026-00412, and the seeded send record with one failed
 * document) and an integriq source in mock mode linked to decidiq's
 * case-system connection.
 *
 * @spec openspec/specs/case-system-exchange/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

/**
 * Open the agenda item "Vaststelling omgevingsvisie".
 *
 * @param page The page.
 */
async function openItem(page) {
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await page.getByText('Vaststelling omgevingsvisie').first().click()
}

// @e2e case-system-exchange::the-griffier-links-a-raadsvoorstel-to-its-case
test('the griffier links a raadsvoorstel to its case', async ({ page }) => {
	await openItem(page)
	const tab = page.getByTestId('agenda-item-case')
	await tab.getByTestId('agenda-item-case-reference').locator('input').fill('Z-2026-00412')
	await tab.getByTestId('agenda-item-case-link').click()
	await expect(tab.getByTestId('agenda-item-case-label')).toContainText('Z-2026-00412')
})

// @e2e case-system-exchange::the-griffier-fetches-the-raadsvoorstel
test('the griffier fetches the raadsvoorstel', async ({ page }) => {
	await openItem(page)
	await page.getByTestId('agenda-item-case-fetch').click()
	const dialog = page.getByTestId('case-documents-dialog')
	const rows = dialog.getByTestId('case-documents-row')
	await expect(rows.first()).toBeVisible()
	for (const row of await rows.all()) {
		const box = row.getByRole('checkbox')
		if (await box.isEnabled()) await box.check()
	}
	await dialog.getByTestId('case-documents-fetch').click()
	await expect(dialog.getByText('Fetched').first()).toBeVisible()
})

// @e2e case-system-exchange::the-griffier-sends-the-file-after-the-council-meeting
test('the griffier sends the meeting file from the minutes page', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/minutes`)
	await page.getByText('Notulen').first().click()
	const send = page.getByTestId('minutes-case-system-send')
	await expect(send).toBeVisible()
	await send.click()
	await expect(page.getByTestId('minutes-case-system')).toContainText('being sent')
})

// @e2e case-system-exchange::one-document-failed
test('Send again sends the failed document', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings`)
	await page.getByText('Raadsvergadering 15 januari 2025').first().click()
	const widget = page.getByTestId('meeting-case-system')
	await expect(widget).toContainText('informatieobjecttype not configured')
	await widget.getByTestId('meeting-case-system-resend').first().click()
	await expect(widget).toContainText('sent again')
})
