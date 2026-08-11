/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — app configuration (genuine behavioural).
 *
 * RETARGETED under ADR-079 D1. This file used to deep-link the in-app
 * `/apps/decidesk/settings` route — the manifest `type:"settings"` page — which
 * was a SECOND home for configuration that already lived in the Nextcloud
 * settings framework. That page is deleted; app-level configuration now has
 * exactly one address, `/settings/admin/decidesk`, rendered by
 * lib/Settings/AdminSettings.php and authorized by Nextcloud SERVER-SIDE before
 * the section renders.
 *
 * The scenarios below are the same two the old file owned; only the surface
 * moved. Two things got stronger in the move, and neither is a relaxation:
 *
 *  - The old file carried a `test.fixme` for the register-mapping actions,
 *    because the nc-vue `version-info` / `register-mapping` settings WIDGETS
 *    crashed while rendering the manifest page (`TypeError: e is not a
 *    function` at `Proxy.render`, twice). The admin page does not use those
 *    widgets — CnAdminSettingsShell renders the register mapping itself — so
 *    the scenario is asserted here for real instead of being deferred.
 *  - The old file could not assert on decidesk-origin console errors, for the
 *    same reason. This one can.
 *
 * @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
 * @e2e openspec/specs/openregister-integration/spec.md#configure-register-mapping
 */
import { test, expect, type Page } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url'

async function openAdminSettings(page: Page): Promise<void> {
	await page.goto(`${BASE}/settings/admin/decidesk`)
	await page.waitForSelector('[data-testid="admin-root"]', { timeout: 15_000 })
}

// @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
test('Admin settings: the Nextcloud admin section mounts with the app configuration sections', async ({ page }) => {
	await openAdminSettings(page)

	await expect(page).toHaveURL(/\/settings\/admin\/decidesk/)

	// The sections that carry app-level configuration.
	await expect(page.getByTestId('organisation-settings')).toBeVisible()
	await expect(page.getByTestId('organisation-mode-settings')).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting
//
// organisatie_modus was the ONE key the deleted in-app page owned that the
// admin page did not — the other two Advanced fields (ori_endpoint,
// email_voting_enabled) already had sections here. This test is what makes the
// rehoming real rather than asserted: it selects a mode, saves, reloads, and
// requires the choice to have survived the round trip.
test('Admin settings: organisation mode selects, saves and survives a reload', async ({ page }) => {
	await openAdminSettings(page)

	const section = page.getByTestId('organisation-mode-settings')
	await expect(section).toBeVisible()

	await section.getByTestId('organisation-mode').click()
	await page.getByRole('option', { name: 'Association (assoc)' }).click()
	await section.getByTestId('organisation-mode-save').click()

	// Round-trip through the server: the value is only proven persisted if a
	// fresh load of the page renders it.
	await openAdminSettings(page)
	await expect(page.getByTestId('organisation-mode-settings')).toContainText('Association (assoc)')

	// Restore the instance default so the assertion is not order-dependent for
	// any later spec that reads the mode.
	const restore = page.getByTestId('organisation-mode-settings')
	await restore.getByTestId('organisation-mode').click()
	await page.getByRole('option', { name: 'Government (gov)' }).click()
	await restore.getByTestId('organisation-mode-save').click()
})

// @e2e openspec/specs/openregister-integration/spec.md#configure-register-mapping
test('Admin settings: register mapping exposes its configuration actions', async ({ page }) => {
	await openAdminSettings(page)

	// CnAdminSettingsShell renders the register/schema mapping for the app.
	// These are the actions the old in-app page could never paint.
	await expect(page.getByRole('button', { name: /Re-?import configuration/i }).first()).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
test('Admin settings: no decidesk-origin 5xx and no decidesk console error on load', async ({ page }) => {
	const serverErrors: string[] = []
	const consoleErrors: string[] = []
	page.on('response', r => {
		if (r.status() >= 500 && /decidesk/i.test(r.url())) serverErrors.push(`HTTP ${r.status()} ${r.url()}`)
	})
	page.on('console', m => {
		if (m.type() === 'error' && /decidesk/i.test(m.location().url ?? '')) consoleErrors.push(m.text())
	})

	await openAdminSettings(page)
	await expect(page.getByTestId('organisation-settings')).toBeVisible()

	expect(serverErrors, `decidesk 5xx on admin settings:\n${serverErrors.join('\n')}`).toHaveLength(0)
	expect(consoleErrors, `decidesk console errors on admin settings:\n${consoleErrors.join('\n')}`).toHaveLength(0)
})
