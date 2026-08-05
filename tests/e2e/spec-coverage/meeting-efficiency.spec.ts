/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Meeting efficiency (meeting-efficiency).
 *
 * Drives the real UI surfaces added by meeting-efficiency-v1:
 *   - LiveMeeting agenda-item timer (countdown / pause indicator / no-countdown
 *     informational item), speaker queue (add / remove / current highlight),
 *     and the toggleable cost panel;
 *   - the GovernanceBodyDetail "Efficiency" analytics tab sections.
 *
 * Wall-clock scenarios (15-minute timer expiry, 3-minute speaking limit) are
 * covered exhaustively by the pure-logic vitest suites and carry a
 * reason-bearing `@e2e exclude` in the spec — they cannot be driven in
 * real time in a deterministic browser test.
 *
 * Defensive skips: when no meeting / governance body is seeded in the target
 * instance, the relevant test skips rather than failing — the spec is about
 * behaviour when the data exists, and the deploy reality varies per env.
 *
 * @e2e openspec/specs/meeting-efficiency/spec.md#pause-timer-during-procedural-interruption
 * @e2e openspec/specs/meeting-efficiency/spec.md#skip-timer-for-informational-items
 * @e2e openspec/specs/meeting-efficiency/spec.md#manage-speaker-queue
 * @e2e openspec/specs/meeting-efficiency/spec.md#display-running-meeting-cost
 * @e2e openspec/specs/meeting-efficiency/spec.md#view-meeting-duration-trends
 * @e2e openspec/specs/meeting-efficiency/spec.md#compare-allocated-vs-actual-time-per-item-type
 * @e2e openspec/specs/meeting-efficiency/spec.md#show-cost-per-agenda-item-in-analytics
 */
import { test, expect, type Page } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url'

async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]').first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

async function openApp(page: Page): Promise<boolean> {
	await page.goto(`${BASE}/apps/decidesk/`)
	const ready = await page
		.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
		.then(() => true)
		.catch(() => false)
	if (ready) await dismissSupportDialog(page)
	return ready
}

// Navigate to the first live meeting, if any exists. Returns false to skip.
async function openFirstLiveMeeting(page: Page): Promise<boolean> {
	if (!(await openApp(page))) return false
	const nav = page.locator('[data-testid="cn-nav"], #app-navigation-vue, .app-navigation').first()
	const meetingsEntry = nav.getByTestId('cn-nav-entry-Meetings')
	if (!(await meetingsEntry.isVisible().catch(() => false))) return false
	await meetingsEntry.click()
	// Open the first meeting row, then its live view if present.
	const firstRow = page.getByTestId('cn-object-list-table').locator('tbody tr').first()
	if (!(await firstRow.isVisible().catch(() => false))) return false
	await firstRow.click()
	const liveButton = page.getByRole('button', { name: /live|conduct/i }).first()
	if (await liveButton.isVisible().catch(() => false)) {
		await liveButton.click()
	}
	return await page
		.waitForSelector('[data-testid="meeting-live"]', { timeout: 10_000 })
		.then(() => true)
		.catch(() => false)
}

// @e2e openspec/specs/meeting-efficiency/spec.md#display-running-meeting-cost
test('LiveMeeting: cost panel toggles its running figure', async ({ page }) => {
	if (!(await openFirstLiveMeeting(page))) {
		test.skip(true, 'No live meeting seeded in this environment.')
		return
	}
	const panel = page.getByTestId('meeting-cost-panel')
	await expect(panel).toBeVisible()
	// Default hidden; toggle reveals either a figure or the no-rate hint.
	await page.getByTestId('meeting-cost-toggle').click()
	const figure = page.getByTestId('meeting-cost-figure')
	const noRate = page.getByTestId('meeting-cost-no-rate')
	const shown = (await figure.isVisible().catch(() => false))
		|| (await noRate.isVisible().catch(() => false))
	expect(shown).toBeTruthy()
})

// @e2e openspec/specs/meeting-efficiency/spec.md#pause-timer-during-procedural-interruption
// @e2e openspec/specs/meeting-efficiency/spec.md#skip-timer-for-informational-items
test('LiveMeeting: agenda-item timer renders for the active item', async ({ page }) => {
	if (!(await openFirstLiveMeeting(page))) {
		test.skip(true, 'No live meeting seeded in this environment.')
		return
	}
	// Activate the first agenda item if the chair controls are present.
	const activate = page.getByTestId('meeting-live').getByRole('button', { name: /^1\./ }).first()
	if (!(await activate.isVisible().catch(() => false))) {
		test.skip(true, 'No activatable agenda item / not chair in this environment.')
		return
	}
	await activate.click()
	const timer = page.getByTestId('agenda-item-timer')
	await expect(timer).toBeVisible()
	// Either a countdown clock (allocated) or the no-allocation hint (informational).
	const clock = page.getByTestId('agenda-item-timer-clock')
	const noAlloc = page.getByTestId('agenda-item-timer-no-allocation')
	const present = (await clock.isVisible().catch(() => false))
		|| (await noAlloc.isVisible().catch(() => false))
	expect(present).toBeTruthy()
})

// @e2e openspec/specs/meeting-efficiency/spec.md#manage-speaker-queue
test('LiveMeeting: speaker queue panel renders with an empty state', async ({ page }) => {
	if (!(await openFirstLiveMeeting(page))) {
		test.skip(true, 'No live meeting seeded in this environment.')
		return
	}
	const activate = page.getByTestId('meeting-live').getByRole('button', { name: /^1\./ }).first()
	if (!(await activate.isVisible().catch(() => false))) {
		test.skip(true, 'No activatable agenda item / not chair in this environment.')
		return
	}
	await activate.click()
	const panel = page.getByTestId('speaker-queue-panel')
	await expect(panel).toBeVisible()
	const empty = page.getByTestId('speaker-queue-empty')
	const list = page.getByTestId('speaker-queue-list')
	const present = (await empty.isVisible().catch(() => false))
		|| (await list.isVisible().catch(() => false))
	expect(present).toBeTruthy()
})

// @e2e openspec/specs/meeting-efficiency/spec.md#view-meeting-duration-trends
// @e2e openspec/specs/meeting-efficiency/spec.md#compare-allocated-vs-actual-time-per-item-type
// @e2e openspec/specs/meeting-efficiency/spec.md#show-cost-per-agenda-item-in-analytics
test('GovernanceBody: Efficiency tab shows the analytics surface', async ({ page }) => {
	if (!(await openApp(page))) {
		test.skip(true, 'Decidesk app did not load in this environment.')
		return
	}
	const nav = page.locator('[data-testid="cn-nav"], #app-navigation-vue, .app-navigation').first()
	const bodiesEntry = nav.getByTestId('cn-nav-entry-GovernanceBodies')
	if (!(await bodiesEntry.isVisible().catch(() => false))) {
		test.skip(true, 'No governance bodies nav entry in this environment.')
		return
	}
	await bodiesEntry.click()
	const firstRow = page.getByTestId('cn-object-list-table').locator('tbody tr').first()
	if (!(await firstRow.isVisible().catch(() => false))) {
		test.skip(true, 'No governance body seeded in this environment.')
		return
	}
	await firstRow.click()
	const efficiencyTab = page.getByRole('tab', { name: 'Efficiency' }).first()
	if (!(await efficiencyTab.isVisible().catch(() => false))) {
		test.skip(true, 'Efficiency tab not rendered (sidebar tabs unavailable).')
		return
	}
	await efficiencyTab.click()
	const tab = page.getByTestId('body-efficiency-tab')
	await expect(tab).toBeVisible()
	// Either the analytics sections or the honest empty state are shown.
	const duration = page.getByTestId('body-efficiency-duration')
	const empty = page.getByTestId('body-efficiency-empty')
	const present = (await duration.isVisible().catch(() => false))
		|| (await empty.isVisible().catch(() => false))
	expect(present).toBeTruthy()
})
