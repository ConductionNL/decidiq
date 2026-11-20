/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the commitments register (change toezeggingen-ingekomen-
 * stukken, the commitment half). The schema, lifecycle, reminders and public
 * read predicate are covered as configuration by
 * tests/Unit/Settings/CommitmentsRegisterTest.php, and the pages and the
 * dashboard tile by tests/vitest/commitmentsRegisterPages.spec.js. This file
 * walks them on a live instance.
 *
 * Needs the municipality example set, which seeds an open commitment past its
 * deadline (wachtlijsten jeugdzorg), one in execution (schouw Marktplein) and
 * a disposed one. Runs as the administrator; the anonymous read uses a fresh
 * request context without the stored session.
 *
 * Written, not yet run: it needs a live instance with the example set.
 *
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'

const OVERDUE = 'wachtlijsten jeugdzorg'
const IN_EXECUTION = 'schouw van het Marktplein'

// @e2e toezeggingen-register::griffier-browses-and-filters-the-toezeggingenlijst
test('Commitments: the Over the deadline lens lists the overdue open commitment and not the one in execution', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await expect(page.getByText(OVERDUE)).toBeVisible()

	await page.getByRole('button', { name: /Over the deadline/ }).click()
	await expect(page.getByText(OVERDUE)).toBeVisible()
	await expect(page.getByText(IN_EXECUTION)).toHaveCount(0)

	await page.getByRole('button', { name: /In execution/ }).click()
	await expect(page.getByText(IN_EXECUTION)).toBeVisible()
	await expect(page.getByText(OVERDUE)).toHaveCount(0)
})

// @e2e toezeggingen-register::export-toezeggingenlijst-to-csv
test('Commitments: the list exports to CSV', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await page
		.getByRole('button', { name: /Export/ })
		.first()
		.click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()
	const download = page.waitForEvent('download')
	await dialog
		.getByRole('button', { name: /Export/ })
		.last()
		.click()
	expect((await download).suggestedFilename()).toMatch(/\.csv$/)
})

// @e2e toezeggingen-register::kpi-counts-only-overdue-non-terminal-commitments
test('Dashboard: the Commitments over deadline tile counts what the lens lists, and opens it', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await page.getByRole('button', { name: /Over the deadline/ }).click()
	await expect(page.getByText(OVERDUE)).toBeVisible()
	const listed = await page.locator('tbody tr').count()

	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	const tile = page
		.locator('.cn-stats-block, .cn-kpi-card')
		.filter({ hasText: 'Commitments over deadline' })
	await expect(tile).toBeVisible()
	await expect(tile.locator('.cn-stats-block__count-value')).toHaveText(
		String(listed),
	)

	await tile.click()
	await expect(page).toHaveURL(/\/commitments/)
	await expect(page.getByText(OVERDUE)).toBeVisible()
	await expect(page.getByText(IN_EXECUTION)).toHaveCount(0)
})

// @e2e toezeggingen-register::commitment-is-settled
test('CommitmentDetail: recording a settlement disposes a commitment in execution', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await openRow(page, IN_EXECUTION)

	const tab = page.getByTestId('commitment-publication-tab')
	await tab.getByTestId('commitment-settle').click()
	const dialog = page.getByTestId('commitment-settlement-dialog')
	await expect(dialog).toBeVisible()
	await dialog
		.getByTestId('commitment-settlement-note')
		.locator('textarea, input')
		.first()
		.fill('Schouw gehouden op 12 mei; verslag naar de raad.')
	await dialog.getByTestId('commitment-settlement-confirm').click()

	await expect(dialog).toBeHidden()
	await expect(tab.getByTestId('commitment-settlement-note-shown')).toContainText(
		'Schouw gehouden op 12 mei',
	)
})

// @e2e toezeggingen-register::published-commitment-is-anonymously-readable
// @e2e toezeggingen-register::unpublished-commitment-is-not-public
test('Public read: a published commitment is readable without signing in, an unpublished one is not', async () => {
	const anonymous = await request.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
	})
	const response = await anonymous.get(
		'/index.php/apps/openregister/api/objects/decidiq/governance-commitment?_limit=100',
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	expect(response.ok()).toBe(true)
	const texts: string[] = ((await response.json()).results ?? []).map(
		(row: { text?: string }) => String(row.text ?? ''),
	)

	expect(texts.some((text) => text.includes(OVERDUE))).toBe(true)
	expect(
		texts.some((text) => text.includes('energieplan voor zwembad De Hoge Dijk')),
	).toBe(false)
	await anonymous.dispose()
})
