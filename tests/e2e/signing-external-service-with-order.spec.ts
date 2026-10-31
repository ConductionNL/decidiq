/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: send a decision list for signature in a chosen order
 * (change signing-external-service-with-order, matrix row min-17). The
 * meeting page carries the Signers widget for its decision list: the chair
 * is moved above the griffier, and Send for signature posts the meeting to
 * the signing endpoint. With no signing service configured on the instance
 * the widget says so instead of pretending it was sent.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md
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

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e p2-minutes-and-decisions-core-t3::the-decision-list-is-signed
test('the chair signs the decision list before the griffier', async ({ page }) => {
	const chair = await createObject(page, ledger, 'participant', {
		displayName: `${tag}-voorzitter`,
	})
	const griffier = await createObject(page, ledger, 'participant', {
		displayName: `${tag}-griffier`,
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad-14-oktober`,
		meetingType: 'regular',
		scheduledDate: new Date(Date.now() - 86_400_000).toISOString(),
		signers: [
			{ participant: griffier.id ?? griffier['@self']?.id, order: 1 },
			{ participant: chair.id ?? chair['@self']?.id, order: 2 },
		],
	})
	const meetingId = meeting.id ?? meeting['@self']?.id

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${meetingId}`)
	const widget = page.getByTestId('minutes-signers-tab')
	await expect(widget.getByText(`${tag}-griffier`)).toBeVisible({
		timeout: 20_000,
	})

	const chairRow = widget.getByRole('row').filter({ hasText: `${tag}-voorzitter` })
	await chairRow.getByRole('button', { name: /actions/i }).click()
	await page.getByRole('menuitem', { name: 'Move up' }).click()
	await expect(widget.getByRole('row').nth(1)).toContainText(`${tag}-voorzitter`)

	const sent = page.waitForRequest(
		(request) =>
			request.url().includes(`/api/signing/decision-list/${meetingId}/send`)
			&& request.method() === 'POST',
	)
	await widget.getByTestId('signers-send').click()
	await sent
	await expect(
		widget.getByTestId('signers-status').or(widget.getByRole('alert')),
	).toBeVisible({ timeout: 20_000 })
})
