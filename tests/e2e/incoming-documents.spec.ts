/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: incoming documents reach the agenda (change
 * agenda-incoming-documents-list, matrix row age-13). Which items count as
 * incoming and the Put on agenda payload are covered by
 * tests/vitest/incomingDocuments.spec.js, which validates the payload
 * against the merged agenda-item schema.
 *
 * Needs the municipality example set: its Ingekomen stuk items have no
 * meeting, so they wait on the Incoming documents list. Runs as the
 * administrator. The test puts the first waiting letter on the first
 * upcoming meeting and finds it on that meeting's Incoming documents widget.
 *
 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

// @e2e agenda-management::putting-a-letter-on-the-agenda
test('IncomingDocumentsView: a waiting letter put on a meeting leaves the list and shows on the meeting', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/incoming-documents`)
	const list = page.getByTestId('incoming-documents')
	await expect(list).toBeVisible()
	await expect(
		list.getByText('Brief inzake verkeersveiligheid schoolzone Lindelaan'),
	).toBeVisible()

	const row = list.locator('tr', {
		hasText: 'Brief inzake verkeersveiligheid schoolzone Lindelaan',
	})
	await row.getByTestId('put-on-agenda').click()
	const dialog = page.getByTestId('put-on-agenda-dialog')
	await expect(dialog).toBeVisible()
	await dialog.getByRole('combobox', { name: 'Meeting' }).click()
	const option = page.getByRole('option').first()
	const meetingLabel = (await option.innerText())
		.replace(/\s*\(.*\)\s*$/, '')
		.trim()
	test.skip(
		meetingLabel === '',
		'the example set has no upcoming meeting on this instance',
	)
	await option.click()
	await dialog.getByTestId('put-on-agenda-confirm').click()

	await expect(dialog).toBeHidden()
	await expect(
		list.getByText('Brief inzake verkeersveiligheid schoolzone Lindelaan'),
	).toHaveCount(0)

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings`)
	await page.getByText(meetingLabel).first().click()
	const widget = page.getByTestId('meeting-routed-documents-tab')
	await expect(
		widget.getByText('Brief inzake verkeersveiligheid schoolzone Lindelaan'),
	).toBeVisible()
})
