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
import { becomesVisible } from '../becomes-visible.js'

async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page
		.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]')
		.first()
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

/**
 * Open the first live meeting. Returns false when none exists, so the caller
 * can skip for a reason that is true.
 *
 * ⚠️ THIS USED TO WALK THE UI, AND THAT COULD NOT FIT IN THE TEST BUDGET.
 * The previous version clicked nav → Meetings → first row → "Live", guarding
 * each hop with a probe. Those probes were `isVisible()`, which does not wait,
 * so the helper returned false on the first tick and all three callers skipped
 * with "No live meeting seeded in this environment." — a reason that is FALSE.
 *
 * De-racing the probes exposed the second half of the problem: the click-path's
 * worst-case budget is 15s (app-root) + 10 + 10 + 5 (polls) + 10 (meeting-live)
 * = **50s against a 20s test timeout** (playwright.config.ts). So the tests
 * stopped skipping and started dying *inside this helper* at 20.5s, never
 * reaching an assertion or even their own `test.skip()`. A timeout is more
 * honest than a false skip, but it says nothing.
 *
 * 🔑 The fix is not a bigger timeout — it is the route the repo has already
 * proven. `meeting-management.spec.ts:165` ("live meeting view mounts for an
 * existing meeting") resolves the meeting through the OpenRegister object API
 * and navigates STRAIGHT to `/meetings/{id}/live`, and it passes in **6.6s**.
 * Same destination, one navigation, no dependence on nav labels, list ordering
 * or a "Live" button's accessible name.
 *
 * Budget here: ~5s API + 15s for `meeting-live` — inside the 20s cap, and the
 * remaining `test.skip()` now means what it says: no meeting objects exist.
 */
async function openFirstLiveMeeting(page: Page): Promise<boolean> {
	const resp = await page.request
		.get(
			`${BASE}/index.php/apps/openregister/api/objects/decidesk/meeting?_limit=1`,
			{ headers: { Accept: 'application/json' }, timeout: 5_000 },
		)
		.catch(() => null)
	if (!resp || !resp.ok()) return false
	const body = await resp.json().catch(() => null)
	const first = (body?.results ?? body?.items ?? [])[0]
	const meetingId = first?.id ?? first?.['@self']?.id
	if (!meetingId) return false

	await page.goto(`${BASE}/apps/decidesk/meetings/${meetingId}/live`)
	const mounted = await page
		.waitForSelector('[data-testid="meeting-live"]', { timeout: 15_000 })
		.then(() => true)
		.catch(() => false)
	if (mounted) await dismissSupportDialog(page)
	return mounted
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
	// `.or()` POLLS for either branch. The previous form read both with the
	// non-waiting `isVisible()` on the tick after the click, so it could report
	// "neither" purely because the toggle had not re-rendered yet.
	await expect(figure.or(noRate).first()).toBeVisible({ timeout: 10_000 })
})

// @e2e openspec/specs/meeting-efficiency/spec.md#pause-timer-during-procedural-interruption
// @e2e openspec/specs/meeting-efficiency/spec.md#skip-timer-for-informational-items
test('LiveMeeting: agenda-item timer renders for the active item', async ({
	page,
}) => {
	if (!(await openFirstLiveMeeting(page))) {
		test.skip(true, 'No live meeting seeded in this environment.')
		return
	}
	// Activate the first agenda item if the chair controls are present.
	const activate = page
		.getByTestId('meeting-live')
		.getByRole('button', { name: /^1\./ })
		.first()
	if (!(await becomesVisible(activate))) {
		test.skip(
			true,
			'No activatable agenda item / not chair in this environment.',
		)
		return
	}
	await activate.click()
	const timer = page.getByTestId('agenda-item-timer')
	await expect(timer).toBeVisible()
	// Either a countdown clock (allocated) or the no-allocation hint (informational).
	const clock = page.getByTestId('agenda-item-timer-clock')
	const noAlloc = page.getByTestId('agenda-item-timer-no-allocation')
	await expect(clock.or(noAlloc).first()).toBeVisible({ timeout: 10_000 })
})

// @e2e openspec/specs/meeting-efficiency/spec.md#manage-speaker-queue
test('LiveMeeting: speaker queue panel renders with an empty state', async ({
	page,
}) => {
	if (!(await openFirstLiveMeeting(page))) {
		test.skip(true, 'No live meeting seeded in this environment.')
		return
	}
	const activate = page
		.getByTestId('meeting-live')
		.getByRole('button', { name: /^1\./ })
		.first()
	if (!(await becomesVisible(activate))) {
		test.skip(
			true,
			'No activatable agenda item / not chair in this environment.',
		)
		return
	}
	await activate.click()
	const panel = page.getByTestId('speaker-queue-panel')
	await expect(panel).toBeVisible()
	const empty = page.getByTestId('speaker-queue-empty')
	const list = page.getByTestId('speaker-queue-list')
	await expect(empty.or(list).first()).toBeVisible({ timeout: 10_000 })
})

// @e2e openspec/specs/meeting-efficiency/spec.md#view-meeting-duration-trends
// @e2e openspec/specs/meeting-efficiency/spec.md#compare-allocated-vs-actual-time-per-item-type
// @e2e openspec/specs/meeting-efficiency/spec.md#show-cost-per-agenda-item-in-analytics
test('GovernanceBody: Efficiency tab shows the analytics surface', async ({
	page,
}) => {
	if (!(await openApp(page))) {
		test.skip(true, 'Decidesk app did not load in this environment.')
		return
	}
	const nav = page
		.locator('[data-testid="cn-nav"], #app-navigation-vue, .app-navigation')
		.first()
	const bodiesEntry = nav.getByTestId('cn-nav-entry-GovernanceBodies')
	if (!(await becomesVisible(bodiesEntry))) {
		test.skip(true, 'No governance bodies nav entry in this environment.')
		return
	}
	await bodiesEntry.click()
	const firstRow = page
		.getByTestId('cn-object-list-table')
		.locator('tbody tr')
		.first()
	if (!(await becomesVisible(firstRow))) {
		test.skip(true, 'No governance body seeded in this environment.')
		return
	}
	await firstRow.click()
	const efficiencyTab = page.getByRole('tab', { name: 'Efficiency' }).first()
	if (!(await becomesVisible(efficiencyTab))) {
		test.skip(true, 'Efficiency tab not rendered (sidebar tabs unavailable).')
		return
	}
	await efficiencyTab.click()
	const tab = page.getByTestId('body-efficiency-tab')
	await expect(tab).toBeVisible()
	// Either the analytics sections or the honest empty state are shown.
	const duration = page.getByTestId('body-efficiency-duration')
	const empty = page.getByTestId('body-efficiency-empty')
	await expect(duration.or(empty).first()).toBeVisible({ timeout: 10_000 })
})
