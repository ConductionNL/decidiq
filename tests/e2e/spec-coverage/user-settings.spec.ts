/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — User settings (user-settings-v1).
 *
 * Drives the Nextcloud personal settings panel (/settings/user/decidesk,
 * the spec's required ISettings surface) and the in-app /user-settings SPA
 * page through the four preference sections: notification preferences
 * (per-event toggles + delivery channels + reminder timing), display
 * preferences (default view + date format), absence delegation (delegate +
 * period + the no-voting-rights notice) and communication preferences
 * (governance email). API/contract assertions live in Newman
 * (tests/integration/decidesk-user-settings.postman_collection.json), not
 * here.
 *
 * Defensive skips: when the deployed instance does not serve this branch's
 * personal settings panel yet (deploy mismatch), the specs skip instead of
 * failing — same convention as the other spec-coverage suites.
 *
 * @e2e openspec/specs/user-settings/spec.md#configure-vote-notification-preferences
 * @e2e openspec/specs/user-settings/spec.md#disable-meeting-reminder-notifications
 * @e2e openspec/specs/user-settings/spec.md#configure-notification-timing-for-meeting-reminders
 * @e2e openspec/specs/user-settings/spec.md#set-default-landing-page
 * @e2e openspec/specs/user-settings/spec.md#configure-date-format-preference
 * @e2e openspec/specs/user-settings/spec.md#configure-absence-delegation
 * @e2e openspec/specs/user-settings/spec.md#set-preferred-contact-for-governance-communications
 */
import { test, expect, type Page } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url'

/**
 * Open the Decidesk personal settings panel; skip the test when this branch
 * is not deployed (panel absent).
 */
async function openPersonalSettings(page: Page): Promise<boolean> {
	await page.goto(`${BASE}/settings/user/decidesk`)
	const panel = page.locator('[data-testid="decidesk-personal-settings"]')
	try {
		await panel.waitFor({ state: 'visible', timeout: 15_000 })
		return true
	} catch {
		return false
	}
}

/**
 * Toggle helper for NcCheckboxRadioSwitch.
 *
 * ⚠️ The `data-testid` IS the <input>, not a wrapper around one. @nextcloud/vue
 * 9's NcCheckboxRadioSwitch declares `inheritAttrs: false` and merges `$attrs`
 * onto the <input> element itself, so `[data-testid="x"] input` — what this
 * helper used to ask for — matches nothing and can only ever time out. Verified
 * against the DOM captured in the failing run's trace:
 *
 *   <input class="checkbox-radio-switch__input" type="checkbox"
 *          data-testid="notification-toggle-votingOpened">
 *
 * The input is deliberately unreachable by a real mouse click (`opacity: 0`,
 * `z-index: -1`, with the switch's `inert` icon painted over its box), so the
 * toggle is actuated with a click event dispatched at the control. A checkbox's
 * activation behaviour runs for dispatched clicks, so this flips `checked` and
 * fires `change` — the event nc-vue binds `onToggle` to — exactly as a user
 * clicking the switch does, and it still does nothing when the control is
 * disabled.
 */
async function setSwitch(page: Page, testId: string, on: boolean): Promise<void> {
	const input = page.locator(`[data-testid="${testId}"]`).first()
	const checked = await input.isChecked()
	if (checked !== on) {
		await input.dispatchEvent('click')
	}
	await expect(input).toBeChecked({ checked: on })
}

// @e2e openspec/specs/user-settings/spec.md#configure-vote-notification-preferences
test('Notification preferences: enable Pending vote with both Nextcloud notification and email', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	await setSwitch(page, 'notification-toggle-votingOpened', true)
	await setSwitch(page, 'channel-in-app', true)
	await setSwitch(page, 'channel-email', true)

	await page.locator('[data-testid="notification-preferences-save"]').click()
	await expect(page.getByText('Notification preferences saved.')).toBeVisible()

	// Persistence proof: a reload shows the same enabled state.
	await page.reload()
	await page.locator('[data-testid="decidesk-personal-settings"]').waitFor({ state: 'visible', timeout: 15_000 })
	await expect(page.locator('[data-testid="notification-toggle-votingOpened"]').first()).toBeChecked()
	await expect(page.locator('[data-testid="channel-email"]').first()).toBeChecked()
})

// @e2e openspec/specs/user-settings/spec.md#configure-vote-notification-preferences
test('Notification preferences: both channels off is rejected client-side with a hint', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	await setSwitch(page, 'channel-in-app', false)
	await setSwitch(page, 'channel-email', false)

	await expect(page.getByText('Keep at least one delivery channel enabled', { exact: false })).toBeVisible()
	await expect(page.locator('[data-testid="notification-preferences-save"]')).toBeDisabled()

	// Restore a sane state for the other specs.
	await setSwitch(page, 'channel-in-app', true)
})

// @e2e openspec/specs/user-settings/spec.md#disable-meeting-reminder-notifications
test('Meeting reminders: the toggle can be disabled and persists', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	await setSwitch(page, 'notification-toggle-meetingReminder', false)
	await page.locator('[data-testid="notification-preferences-save"]').click()
	await expect(page.getByText('Notification preferences saved.')).toBeVisible()

	await page.reload()
	await page.locator('[data-testid="decidesk-personal-settings"]').waitFor({ state: 'visible', timeout: 15_000 })
	await expect(page.locator('[data-testid="notification-toggle-meetingReminder"]').first()).not.toBeChecked()

	// Restore the default for subsequent runs.
	await setSwitch(page, 'notification-toggle-meetingReminder', true)
	await page.locator('[data-testid="notification-preferences-save"]').click()
	await expect(page.getByText('Notification preferences saved.')).toBeVisible()
})

// @e2e openspec/specs/user-settings/spec.md#configure-notification-timing-for-meeting-reminders
test('Reminder timing: defaults to 24h + 1h and accepts 48h + 1h', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	// The default hint documents the spec default (24h + 1h before).
	await expect(page.getByText('Default: 24 hours and 1 hour before the meeting.')).toBeVisible()

	await setSwitch(page, 'notification-toggle-meetingReminder', true)
	await setSwitch(page, 'reminder-time-48h', true)
	await setSwitch(page, 'reminder-time-1h', true)
	await setSwitch(page, 'reminder-time-24h', false)

	await page.locator('[data-testid="notification-preferences-save"]').click()
	await expect(page.getByText('Notification preferences saved.')).toBeVisible()

	await page.reload()
	await page.locator('[data-testid="decidesk-personal-settings"]').waitFor({ state: 'visible', timeout: 15_000 })
	await expect(page.locator('[data-testid="reminder-time-48h"]').first()).toBeChecked()
	await expect(page.locator('[data-testid="reminder-time-1h"]').first()).toBeChecked()
	await expect(page.locator('[data-testid="reminder-time-24h"]').first()).not.toBeChecked()

	// Restore the spec default.
	await setSwitch(page, 'reminder-time-24h', true)
	await setSwitch(page, 'reminder-time-48h', false)
	await page.locator('[data-testid="notification-preferences-save"]').click()
	await expect(page.getByText('Notification preferences saved.')).toBeVisible()
})

// @e2e openspec/specs/user-settings/spec.md#set-default-landing-page
test('Display preferences: default view Meetings redirects the app root to the meetings list', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	// Pick "Meetings" in the default-view NcSelect.
	const select = page.locator('[data-testid="display-default-view"]')
	await select.locator('input').first().click()
	await page.getByRole('option', { name: 'Meetings', exact: true }).click()
	await page.locator('[data-testid="display-preferences-save"]').click()
	await expect(page.getByText('Display preferences saved.')).toBeVisible()

	// Opening the app root must land on the meetings list, not the dashboard.
	await page.goto(`${BASE}/apps/decidesk/`)
	await page.waitForURL(/\/apps\/decidesk\/meetings/, { timeout: 15_000 })
	await expect(page).toHaveURL(/\/apps\/decidesk\/meetings/)

	// A deep link is never overridden by the preference.
	await page.goto(`${BASE}/apps/decidesk/decisions`)
	await page.waitForTimeout(1500)
	await expect(page).toHaveURL(/\/apps\/decidesk\/decisions/)

	// Restore the default for subsequent runs — via the API, not the UI.
	//
	// ⚠️ This is the one thing that was actually wrong with this test. Every
	// assertion above already passed on the failing run (trace: the redirect
	// landed on /meetings at 10.6s and the deep link held at 17.6s); the test
	// then died re-loading the settings panel for the restore. Three full app
	// navigations plus the settings panel cost ~16s of the 20s per-test budget
	// on their own, so the reset cannot afford a fourth page load. It goes
	// straight at the per-user endpoint the UI itself writes
	// (PreferencesController::setPreference, @NoCSRFRequired, session-scoped),
	// reusing the browser context's cookies plus a CSRF token (the same
	// `/index.php/csrftoken` handshake the workflow fixtures use). Housekeeping
	// only — no acceptance criterion is asserted through the UI restore.
	const csrf = await page.request.get(`${BASE}/index.php/csrftoken`)
	const reset = await page.request.put(`${BASE}/apps/decidesk/api/preferences/default-view`, {
		headers: { 'Content-Type': 'application/json', requesttoken: (await csrf.json()).token },
		data: { value: 'dashboard' },
	})
	expect(reset.ok(), `restoring default-view returned HTTP ${reset.status()}`).toBeTruthy()
})

// @e2e openspec/specs/user-settings/spec.md#configure-date-format-preference
test('Display preferences: date format DD-MM-YYYY previews and saves', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	await page.locator('[data-testid="display-date-format"] input').first().click()
	// ⚠️ Matched on the option's TEXT, not its accessible name. nc-vue renders
	// every NcSelect option through NcEllipsisedOption, which splits any label
	// of 10+ characters into two spans inside a `display: flex` parent — so the
	// option's accessible name gains a space at the split point and an option
	// literally named "DD-MM-YYYY" never exists. Playwright's own aria snapshot
	// from the failing run records it verbatim: `option "DD-MM -YYYY"`. The
	// element's text content is unaffected, so that is what this asserts on.
	await page.getByRole('option').filter({ hasText: /^DD-MM-YYYY$/ }).click()

	// The example preview renders in the chosen format (DD-MM-YYYY).
	await expect(page.getByText(/Example: \d{2}-\d{2}-\d{4}/)).toBeVisible()

	await page.locator('[data-testid="display-preferences-save"]').click()
	await expect(page.getByText('Display preferences saved.')).toBeVisible()

	// Restore the locale default (the spec default).
	await page.locator('[data-testid="display-date-format"] input').first().click()
	await page.getByRole('option', { name: 'Nextcloud locale (default)', exact: true }).click()
	await page.locator('[data-testid="display-preferences-save"]').click()
	await expect(page.getByText('Display preferences saved.')).toBeVisible()
})

// @e2e openspec/specs/user-settings/spec.md#configure-absence-delegation
test('Delegation: requires an expiry, shows the no-voting-rights notice, saves and clears', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	// The delegation section always explains that delegation ≠ proxy.
	await expect(page.locator('[data-testid="delegation-proxy-note"]'))
		.toContainText('Delegation does not include voting rights. A formal proxy (volmacht) is required for voting.')

	// Pick the admin user itself as delegate (always present on the instance).
	const delegateSelect = page.locator('[data-testid="delegation-delegate"]')
	await delegateSelect.locator('input').first().fill('admin')
	const adminOption = page.getByRole('option', { name: /admin/i }).first()
	try {
		await adminOption.waitFor({ state: 'visible', timeout: 10_000 })
	} catch {
		test.skip(true, 'sharees endpoint returned no users to pick as delegate')
	}
	await adminOption.click()

	// Expiry is mandatory: without an end date the save is blocked.
	await expect(page.getByText('A delegation needs an end date — it expires automatically.')).toBeVisible()
	await expect(page.locator('[data-testid="delegation-save"]')).toBeDisabled()

	// Configure the vacation window and save.
	await page.locator('[data-testid="delegation-from"] input, input#decidesk-delegation-from').first().fill('2026-07-01')
	await page.locator('[data-testid="delegation-until"] input, input#decidesk-delegation-until').first().fill('2026-07-14')
	await page.locator('[data-testid="delegation-save"]').click()
	await expect(page.getByText('Delegation saved.')).toBeVisible()

	// Clear it again so the instance state stays clean.
	await page.locator('[data-testid="delegation-clear"]').click()
	await expect(page.getByText('Delegation saved.')).toBeVisible()
})

// @e2e openspec/specs/user-settings/spec.md#set-preferred-contact-for-governance-communications
test('Communication: governance email overrides the account default and saves', async ({ page }) => {
	test.skip(!(await openPersonalSettings(page)), 'decidesk personal settings panel not deployed on this instance')

	// The section documents the account-email default.
	await expect(page.getByText('Leave empty to use your Nextcloud account email.')).toBeVisible()

	// ⚠️ Same contract as the switches: NcTextField renders NcInputField, which
	// declares `inheritAttrs: false` and merges `$attrs` onto its <input>, so
	// the `data-testid` IS the input. Confirmed from the failing run's trace:
	// <input class="input-field__input" type="email" data-testid="communication-email">
	const emailInput = page.locator('[data-testid="communication-email"]').first()
	await emailInput.fill('not-an-email')
	await expect(page.getByText('Enter a valid email address.')).toBeVisible()
	await expect(page.locator('[data-testid="communication-save"]')).toBeDisabled()

	await emailInput.fill('work@example.com')
	await page.locator('[data-testid="communication-save"]').click()
	await expect(page.getByText('Communication preferences saved.')).toBeVisible()

	await page.reload()
	await page.locator('[data-testid="decidesk-personal-settings"]').waitFor({ state: 'visible', timeout: 15_000 })
	await expect(page.locator('[data-testid="communication-email"]').first()).toHaveValue('work@example.com')

	// Restore the account default.
	await page.locator('[data-testid="communication-email"]').first().fill('')
	await page.locator('[data-testid="communication-save"]').click()
	await expect(page.getByText('Communication preferences saved.')).toBeVisible()
})

// @e2e openspec/specs/user-settings/spec.md#set-default-landing-page
test('SPA mount: /apps/decidesk/user-settings renders the four sections without decidesk errors', async ({ page }) => {
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

	await page.goto(`${BASE}/apps/decidesk/user-settings`)
	const spaPage = page.locator('[data-testid="user-settings-page"]')
	try {
		await spaPage.waitFor({ state: 'visible', timeout: 15_000 })
	} catch {
		test.skip(true, 'decidesk user-settings SPA page not deployed on this instance')
	}

	await expect(page.locator('[data-testid="notification-preferences-section"]')).toBeVisible()
	await expect(page.locator('[data-testid="display-preferences-section"]')).toBeVisible()
	await expect(page.locator('[data-testid="delegation-section"]')).toBeVisible()
	await expect(page.locator('[data-testid="communication-section"]')).toBeVisible()
	expect(appErrors, `decidesk errors on user-settings:\n${appErrors.join('\n')}`).toHaveLength(0)
})
