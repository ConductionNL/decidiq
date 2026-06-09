/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Workspaces index page (genuine behavioural).
 *
 * The Workspaces nav route had no dedicated spec. Drives the page via
 * the app's LEFT navigation (cn-nav-entry-Workspaces, not the global
 * NC header), then asserts the real cn-index-page surface (heading,
 * Cards/Table view toggle, primary CTA) and the create modal.
 *
 * @e2e openspec/specs/workspace-management/spec.md#view-the-workspaces-list
 * @e2e openspec/specs/workspace-management/spec.md#create-a-workspace
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

// @e2e openspec/specs/workspace-management/spec.md#view-the-workspaces-list
test('Workspaces: app-scoped nav lands on the index with its real content', async ({ page }) => {
	await appNavClick(page, 'Workspaces')

	await expect(page).toHaveURL(/\/apps\/decidesk\/.*workspaces/)
	await expect(page.getByRole('heading', { name: 'Workspaces', exact: true })).toBeVisible()
	const indexPage = page.getByTestId('cn-index-page').first()
	await expect(indexPage).toBeVisible()
	// Index actions bar exposes the Cards/Table view toggle and the primary CTA.
	const actionsBar = page.getByTestId('cn-actions-bar').first()
	await expect(actionsBar).toBeVisible()
	await expect(page.getByTestId('cn-cta-primary').first()).toBeVisible()
})

// @e2e openspec/specs/workspace-management/spec.md#create-a-workspace
test('Workspaces: primary CTA opens a real create form dialog', async ({ page }) => {
	await appNavClick(page, 'Workspaces')
	await page.getByTestId('cn-cta-primary').first().click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(dialog.getByRole('heading', { name: /Create\s+Item/i })).toBeVisible()
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/workspace-management/spec.md#view-the-workspaces-list
test('Workspaces: no decidesk-origin console error or 500 on load', async ({ page }) => {
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

	await appNavClick(page, 'Workspaces')
	await expect(page.getByTestId('cn-index-page').first()).toBeVisible()
	expect(appErrors, `decidesk errors on Workspaces:\n${appErrors.join('\n')}`).toHaveLength(0)
})
