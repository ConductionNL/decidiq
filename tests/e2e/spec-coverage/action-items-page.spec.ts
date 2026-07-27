/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Action items index page (genuine behavioural).
 *
 * The ActionItems nav route had no dedicated spec. Drives the page via
 * the app's LEFT navigation (cn-nav-entry-ActionItems, not the global
 * NC header), then asserts the real index surface and the create form.
 *
 * @e2e openspec/specs/action-item-management/spec.md#view-the-action-items-list
 * @e2e openspec/specs/action-item-management/spec.md#create-an-action-item
 */
import { test, expect, type Page } from '@playwright/test'

const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]').first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

async function appNavClick(page: Page, entryId: string): Promise<void> {
	await page.goto(`${BASE}/apps/decidesk/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
	const nav = page.locator('[data-testid="cn-nav"], #app-navigation-vue, .app-navigation').first()
	await nav.getByTestId(`cn-nav-entry-${entryId}`).click()
}

// @e2e openspec/specs/action-item-management/spec.md#view-the-action-items-list
test('Action items: app-scoped nav lands on the index with its real content', async ({ page }) => {
	await appNavClick(page, 'ActionItems')

	await expect(page).toHaveURL(/\/apps\/decidesk\/.*action-items/)
	// NOTE: the migrated CnIndexPage (nc-vue v2) no longer renders a page-title
	// heading inside <main>; the real index surface is the object-list table +
	// "Showing N of N" + primary CTA, which is what we assert below.
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	await expect(page.getByText('Showing', { exact: false }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: 'Add ActionItem' })).toBeVisible()
})

// @e2e openspec/specs/action-item-management/spec.md#create-an-action-item
test('Action items: Add ActionItem opens a real create form dialog', async ({ page }) => {
	await appNavClick(page, 'ActionItems')
	await page.getByRole('button', { name: 'Add ActionItem' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(dialog.getByRole('heading', { name: /Create\s+ActionItem/i })).toBeVisible()
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/action-item-management/spec.md#view-the-action-items-list
test('Action items: no decidesk-origin console error or 500 on load', async ({ page }) => {
	const appErrors: string[] = []
	page.on('console', m => {
		const t = m.text()
		if (m.type() === 'error' && !/user_status|heartbeat|user status/i.test(t) && /decidesk/i.test(t)) {
			appErrors.push(t)
		}
	})
	page.on('response', r => {
		if (r.status() >= 500 && /decidesk/i.test(r.url())) appErrors.push(`HTTP ${r.status()} ${r.url()}`)
	})

	await appNavClick(page, 'ActionItems')
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	expect(appErrors, `decidesk errors on Action items:\n${appErrors.join('\n')}`).toHaveLength(0)
})
