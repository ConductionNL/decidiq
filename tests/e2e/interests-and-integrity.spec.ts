/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: ancillary positions and declared gifts (change
 * interests-and-integrity). The schemas, the lifecycle, the read predicate and
 * the seeds are covered as configuration by
 * tests/Unit/Settings/IntegrityDisclosuresRegisterTest.php, the COI context
 * by tests/vitest/ancillaryContext.spec.js and the compliance join by
 * tests/vitest/integrityCompliance.spec.js. This file walks them on a live
 * instance.
 *
 * Needs the municipality example set (council policy, four positions, two
 * gifts) and, for the internal declaration, the corporate one. Runs as the
 * administrator; the anonymous reads use a request context without the
 * stored session.
 *
 * Written, not yet run: it needs a live instance with the example sets. The
 * self-service scenarios (MyDeclarations scoped to the signed-in member, add,
 * end, confirm review) wait on task 4.
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'

/**
 * The organisations of the ancillary positions an anonymous reader sees.
 *
 * @return {Promise<string[]>} The organisation names.
 */
async function anonymousPositions(): Promise<string[]> {
	const anonymous = await request.newContext({ baseURL: BASE, storageState: { cookies: [], origins: [] } })
	const response = await anonymous.get('/index.php/apps/openregister/api/objects/decidiq/ancillary-position?_limit=100', {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(response.ok()).toBe(true)
	const rows: Array<{ organisation?: string, lifecycle?: string }> = (await response.json()).results ?? []
	await anonymous.dispose()
	return rows.map((row) => `${row.organisation ?? ''}|${row.lifecycle ?? ''}`)
}

// @e2e interests-and-integrity::published-nevenfunctie-is-anonymously-readable
// @e2e interests-and-integrity::internal-declaration-is-not-public
// @e2e interests-and-integrity::ended-position-is-live-on-the-public-register
test('Public read: published positions, ended ones included, are readable without signing in; declared and internal ones are not', async () => {
	const seen = await anonymousPositions()

	expect(seen.some((row) => row.endsWith('|public'))).toBe(true)
	expect(seen.some((row) => row.endsWith('|ended'))).toBe(true)
	expect(seen.some((row) => row.endsWith('|internal'))).toBe(false)
	expect(seen.some((row) => row.endsWith('|reported'))).toBe(false)
	expect(seen.some((row) => row.startsWith('Stedin|'))).toBe(false)
})

// @e2e interests-and-integrity::griffie-filters-the-per-body-register-and-exports
test('AncillaryPositions: the lenses narrow the register and the list exports', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/ancillary-positions`)
	await expect(page.locator('tbody tr').first()).toBeVisible()
	const all = await page.locator('tbody tr').count()

	await page.getByRole('button', { name: /^Public/ }).click()
	const publicRows = await page.locator('tbody tr').count()
	expect(publicRows).toBeLessThan(all)

	await page.getByRole('button', { name: /^All/ }).click()
	await page.getByRole('button', { name: /Export/ }).first().click()
	const dialog = page.getByRole('dialog')
	const download = page.waitForEvent('download')
	await dialog.getByRole('button', { name: /Export/ }).last().click()
	expect((await download).suggestedFilename()).toMatch(/\.csv$/)
})

// @e2e interests-and-integrity::q.q.-functie-flagged-as-held-ex-officio
test('AncillaryPositionDetail: a position held ex officio says so', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/ancillary-positions`)
	await openRow(page, 'Afvalschap')
	await expect(page.getByText(/Ex officio/)).toBeVisible()
})

// @e2e interests-and-integrity::member-registers-a-received-gift
// @e2e interests-and-integrity::invitation-registered-and-handed-over
test('DeclaredGifts: the lenses split gifts from invitations and by decision', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/declared-gifts`)
	await page.getByRole('button', { name: /^Invitations/ }).click()
	await expect(page.locator('tbody tr', { hasText: /diner/i })).toBeVisible()
	await page.getByRole('button', { name: /^Accepted/ }).click()
	await expect(page.locator('tbody tr', { hasText: /boek/i })).toBeVisible()
})

// @e2e interests-and-integrity::dashboard-kpi-counts-overdue-reviews
test('Dashboard: the overdue-review tile is not zero on the example set', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	const tile = page.locator('.cn-stats-block, .cn-kpi-card').filter({ hasText: /review/i }).first()
	await expect(tile).toBeVisible()
	await expect(tile.locator('.cn-stats-block__count-value')).not.toHaveText('0')
})
