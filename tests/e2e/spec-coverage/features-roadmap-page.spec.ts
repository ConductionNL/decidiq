/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Features & roadmap page (genuine behavioural).
 *
 * The FeaturesRoadmap nav route had no dedicated spec. Drives the page
 * via the app's LEFT navigation (cn-nav-entry-FeaturesRoadmapMenu, not
 * the global NC header), then asserts the real roadmap surface: the
 * "Features" heading and the "Show roadmap" / "Suggest feature" CTAs.
 *
 * @e2e openspec/specs/dashboard/spec.md#view-the-features-and-roadmap-page
 */
import { test, expect, type Page } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url'

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

// @e2e openspec/specs/dashboard/spec.md#view-the-features-and-roadmap-page
test('Features & roadmap: app-scoped nav lands on the roadmap surface', async ({ page }) => {
	await appNavClick(page, 'FeaturesRoadmapMenu')

	await expect(page).toHaveURL(/\/apps\/decidesk\/.*features-roadmap/)
	await expect(page.getByRole('heading', { name: 'Features', exact: true })).toBeVisible()
	await expect(page.getByRole('button', { name: /Show roadmap/i }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: /Suggest feature/i }).first()).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#view-the-features-and-roadmap-page
test('Features & roadmap: no decidesk-origin console error or 500 on load', async ({ page }) => {
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

	await appNavClick(page, 'FeaturesRoadmapMenu')
	await expect(page.getByRole('heading', { name: 'Features', exact: true })).toBeVisible()
	expect(appErrors, `decidesk errors on Features & roadmap:\n${appErrors.join('\n')}`).toHaveLength(0)
})
