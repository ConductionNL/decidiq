/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Minutes index page (genuine behavioural).
 *
 * The Minutes nav route had no dedicated spec. This drives the page
 * through the app's LEFT navigation (the `cn-nav-entry-Minutes` testid,
 * NOT the global NC header — clicking a header "Dashboard"/app link can
 * silently navigate out of the SPA and false-green), then asserts the
 * real index surface: heading, object-list table, the "Add Minutes"
 * primary CTA, and that opening the create dialog renders a real form.
 *
 * @e2e openspec/specs/minutes-management/spec.md#view-the-minutes-list
 * @e2e openspec/specs/minutes-management/spec.md#create-minutes-for-a-meeting
 */
import { test, expect, type Page } from '@playwright/test'

const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

/** Dismiss the cn-support-dialog if it auto-opened and is intercepting clicks. */
async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]').first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

/** Navigate by the APP's left navigation (app-scoped), not the global header. */
async function appNavClick(page: Page, entryId: string): Promise<void> {
	await page.goto(`${BASE}/apps/decidesk/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
	const nav = page.locator('[data-testid="cn-nav"], #app-navigation-vue, .app-navigation').first()
	await nav.getByTestId(`cn-nav-entry-${entryId}`).click()
}

// @e2e openspec/specs/minutes-management/spec.md#view-the-minutes-list
test('Minutes: app-scoped nav lands on the Minutes index with its real content', async ({ page }) => {
	await appNavClick(page, 'Minutes')

	// URL stayed inside the decidesk SPA on the minutes route (no false-green out-nav)
	await expect(page).toHaveURL(/\/apps\/decidesk\/.*minutes/)

	// Real index surface: heading + object-list table + "Showing N of N" + primary CTA
	await expect(page.getByRole('heading', { name: 'Minutes', exact: true })).toBeVisible()
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	await expect(page.getByText('Showing', { exact: false }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: 'Add Minutes' })).toBeVisible()
})

// @e2e openspec/specs/minutes-management/spec.md#create-minutes-for-a-meeting
test('Minutes: Add Minutes opens a real create form dialog', async ({ page }) => {
	await appNavClick(page, 'Minutes')
	await page.getByRole('button', { name: 'Add Minutes' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(dialog.getByRole('heading', { name: /Create\s+Minutes/i })).toBeVisible()
	// A real form is rendered (at least one input) plus the Create action
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/minutes-management/spec.md#view-the-minutes-list
test('Minutes: no decidesk-origin console error or 500 on load', async ({ page }) => {
	const appErrors: string[] = []
	page.on('console', m => {
		const t = m.text()
		// Ignore NC-core user_status noise; only flag decidesk-origin failures.
		if (m.type() === 'error' && !/user_status|heartbeat|user status/i.test(t)) {
			if (/decidesk/i.test(t)) appErrors.push(t)
		}
	})
	page.on('response', r => {
		if (r.status() >= 500 && /decidesk/i.test(r.url())) appErrors.push(`HTTP ${r.status()} ${r.url()}`)
	})

	await appNavClick(page, 'Minutes')
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	expect(appErrors, `decidesk errors on Minutes:\n${appErrors.join('\n')}`).toHaveLength(0)
})
