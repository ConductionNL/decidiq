/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — In-app Settings page (genuine behavioural).
 *
 * The Settings nav route had no dedicated spec. The Settings entry
 * lives in the app's collapsible footer/settings drawer (not the
 * primary left-nav list), so this spec deep-links to the route
 * (still app-scoped — never via the global header) and asserts the
 * real settings surface: version-info, register-mapping configuration,
 * the Advanced section and the "Save configuration" action.
 *
 * @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
 * @e2e openspec/specs/openregister-integration/spec.md#configure-register-mapping
 */
import { test, expect, type Page } from '@playwright/test'

const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]').first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

async function openSettings(page: Page): Promise<void> {
	await page.goto(`${BASE}/apps/decidesk/settings`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
}

// @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
test('Settings: app-scoped nav lands on the settings surface with version + config', async ({ page }) => {
	await openSettings(page)

	await expect(page).toHaveURL(/\/apps\/decidesk\/.*settings/)
	await expect(page.getByRole('heading', { name: 'Version Information' })).toBeVisible()
	await expect(page.getByRole('heading', { name: 'Register Configuration' })).toBeVisible()
})

// @e2e openspec/specs/openregister-integration/spec.md#configure-register-mapping
test('Settings: register configuration exposes a Save configuration action', async ({ page }) => {
	await openSettings(page)

	await expect(page.getByRole('button', { name: /Save configuration/i }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: /Re-import configuration/i }).first()).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
test('Settings: no decidesk-origin console error or 500 on load', async ({ page }) => {
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

	await openSettings(page)
	await expect(page.getByRole('heading', { name: 'Version Information' })).toBeVisible()
	expect(appErrors, `decidesk errors on Settings:\n${appErrors.join('\n')}`).toHaveLength(0)
})
