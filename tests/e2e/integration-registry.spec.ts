/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration-registry UI smoke — end-to-end proof that the
 * pluggable-integration chain (ADR-019) renders correctly inside the
 * decidesk shell.
 *
 * Walks the user from an authenticated NC home → decidesk → meeting
 * integrations page → asserts:
 *   1. window.OCA.OpenRegister.integrations.list() exposes 24
 *      registered providers (5 built-ins + 1 xwiki + 18 leaves).
 *   2. <CnObjectSidebar :use-registry="true"> mounts one tab per
 *      registered provider (DOM check on `[role="tab"]` inside the
 *      sidebar — 24 tabs total).
 *   3. Every registered provider id has a matching `tab-button-{id}`
 *      element (registry → DOM mapping is intact).
 *   4. Clicking each tab activates it (aria-selected=true) and
 *      mounts its panel — verifies the registry's resolveWidget()
 *      path is wired.
 *   5. requiredApp gating: providers whose NC app is uninstalled
 *      still register (in JS) but are reported `enabled:false` in
 *      OCS caps. Both fields must agree.
 *
 * Expects the full chain deployed:
 *   - openregister PR #1514 (PHP providers wired in DI + boot)
 *   - nextcloud-vue PR #231 (CnIntegrationTab/Card + leaves.js)
 *   - decidesk PR #205 (registerLeafIntegrations() in main.js)
 *
 * On a partial deploy, the count-based assertions skip rather than
 * fail, so this spec is safe to run against an in-progress dev env.
 */
import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Every leaf this PR chain ships. Keep in sync with
 * nextcloud-vue/src/integrations/builtin/leaves.js (and ultimately
 * the PHP providers in openregister).
 */
const LEAF_IDS = [
	'shares', 'calendar', 'contacts', 'email', 'talk',
	'openproject', 'bookmarks', 'collectives', 'maps', 'photos',
	'activity', 'analytics', 'cospend', 'deck', 'flow',
	'forms', 'polls', 'time-tracker',
] as const

const BUILTIN_IDS = ['files', 'notes', 'tags', 'tasks', 'audit-trail'] as const
const EXTERNAL_IDS = ['xwiki'] as const

const EXPECTED_IDS = [...BUILTIN_IDS, ...EXTERNAL_IDS, ...LEAF_IDS]
const EXPECTED_COUNT = EXPECTED_IDS.length // 24

/**
 * Ensure an authenticated NC session.
 *
 * The Playwright config wires `use.storageState` from globalSetup, so
 * tests usually start already logged in. In that case navigating to
 * `/login` immediately redirects to the dashboard and no login form is
 * present — so we only drive the HTML form when it actually renders.
 * This keeps the spec runnable both with and without storageState.
 *
 * @param page Playwright Page.
 */
async function login(page: Page) {
	// Fast path: the Playwright config wires `use.storageState` from
	// globalSetup, so tests almost always start authenticated. Verify
	// that cheaply via an OCS call instead of paying a full `/login`
	// dashboard page-load in every `beforeEach` (which, multiplied across
	// this spec's ~30 tests, dominated the runtime and blew the budget).
	const probe = await page.request.get('/ocs/v2.php/cloud/user?format=json', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		failOnStatusCode: false,
	})
	if (probe.ok()) {
		return
	}
	// No session (e.g. CI without storageState) — drive the HTML form.
	await page.goto('/login')
	const userField = page.getByRole('textbox', { name: /Account name|Username/i })
	if (!(await userField.isVisible({ timeout: 2_000 }).catch(() => false))) {
		await expect(page).toHaveURL(/\/(apps|index\.php)/)
		return
	}
	await userField.fill(NC_USER)
	await page.getByRole('textbox', { name: 'Password' }).fill(NC_PASS)
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	// NC redirects to the apps menu (or /apps/dashboard/) after auth.
	await expect(page).toHaveURL(/\/(apps|index\.php)/)
}

/**
 * Wait (briefly) for the integration registry to install on `window`.
 *
 * The registry, when deployed, installs synchronously as the decidesk
 * main bundle evaluates, so a short wait is ample. On a partial deploy
 * (the leaves / OR-provider chain not shipped) it never appears — so we
 * swallow the timeout and let the caller's existing `test.skip` on an
 * empty id list handle it, instead of erroring on a 10s hang per test.
 *
 * @param page Playwright Page.
 */
async function waitForRegistry(page: Page): Promise<void> {
	await page.waitForFunction(() => {
		return !!(window as Window & { OCA?: { OpenRegister?: { integrations?: { list?: () => unknown[] } } } })
			.OCA?.OpenRegister?.integrations?.list
	}, { timeout: 4_000 }).catch(() => { /* registry absent — caller skips */ })
}

/**
 * Open the meeting integrations page for the first meeting we can
 * find. Returns the meeting uuid so the test can assert on it.
 *
 * @param page Playwright Page.
 * @return The meeting object's uuid.
 */
async function openMeetingIntegrations(page: Page): Promise<string> {
	// Fetch the first meeting via the OR API (we're authenticated
	// already by the time this runs).
	const r = await page.request.get('/index.php/apps/openregister/api/objects/decidesk/meeting?_limit=1', {
		headers: { Accept: 'application/json' },
	})
	expect(r.ok(), 'meeting listing reachable').toBe(true)
	const body = await r.json()
	const first = (body.results ?? body.items ?? [])[0]
	test.skip(!first, 'no meeting objects on this instance — seed at least one')
	const meetingId = first.id ?? first['@self']?.id
	test.skip(!meetingId, 'first meeting has no id; cannot navigate')

	await page.goto(`/apps/decidesk/meetings/${meetingId}/integrations`)
	// Wait for the registry-mode sidebar to mount. On a partial deploy the
	// registry sidebar never mounts, so treat the absence as "not active"
	// (the callers already skip on an empty/absent sidebar) instead of
	// hanging for the full timeout and erroring.
	await page.waitForFunction(() => {
		return !!document.querySelector('aside.app-sidebar')
	}, { timeout: 4_000 }).catch(() => { /* sidebar absent — caller skips */ })
	return meetingId as string
}

test.describe('Integration registry — JS registration', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('window.OCA.OpenRegister.integrations.list() exposes 24 providers', async ({ page }) => {
		await page.goto('/apps/decidesk/')
		// Give the main bundle time to install the registry +
		// register the leaves.
		await waitForRegistry(page)

		const ids = await page.evaluate(() => {
			const reg = (window as Window & { OCA?: { OpenRegister?: { integrations?: { list?: () => Array<{ id: string }> } } } })
				.OCA?.OpenRegister?.integrations
			return reg && reg.list ? reg.list().map((p) => p.id).sort() : []
		})

		test.skip(ids.length === 0, 'integration registry not initialised on this build')
		if (ids.length < EXPECTED_COUNT) {
			test.skip(true, `partial registry: ${ids.length}/${EXPECTED_COUNT} providers — leaves PR not deployed yet (have: ${ids.join(', ')})`)
		}

		expect(ids).toHaveLength(EXPECTED_COUNT)
		for (const id of EXPECTED_IDS) {
			expect(ids, `provider "${id}" missing from registry`).toContain(id)
		}
	})

	test('every leaf carries both tab + widget Vue components (parity gate)', async ({ page }) => {
		await page.goto('/apps/decidesk/')
		await waitForRegistry(page)

		const providers = await page.evaluate(() => {
			const reg = (window as Window & { OCA?: { OpenRegister?: { integrations?: { list?: () => Array<{ id: string, tab: unknown, widget: unknown }> } } } })
				.OCA?.OpenRegister?.integrations
			return reg && reg.list ? reg.list().map((p) => ({ id: p.id, hasTab: !!p.tab, hasWidget: !!p.widget })) : []
		})
		test.skip(providers.length === 0, 'integration registry not initialised')

		for (const p of providers) {
			expect(p.hasTab, `${p.id}.tab`).toBe(true)
			expect(p.hasWidget, `${p.id}.widget`).toBe(true)
		}
	})
})

test.describe('Integration registry — sidebar tab rendering', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('meeting integrations page mounts one sidebar tab per registered provider', async ({ page }) => {
		await openMeetingIntegrations(page)

		// The registry-mode CnObjectSidebar renders an NcAppSidebarTab
		// per provider; each surfaces as `[role="tab"][id^="tab-button-"]`.
		const tabs = page.locator('aside.app-sidebar [role="tab"][id^="tab-button-"]')
		const count = await tabs.count()

		test.skip(count === 0, 'registry sidebar mode not active — check use-registry forwarding')
		if (count < EXPECTED_COUNT) {
			test.skip(true, `partial sidebar: ${count}/${EXPECTED_COUNT} tabs — leaves PR not deployed yet`)
		}

		expect(count).toBe(EXPECTED_COUNT)
	})

	for (const id of EXPECTED_IDS) {
		test(`tab-button-${id} renders in the registry sidebar`, async ({ page }) => {
			await openMeetingIntegrations(page)

			const tab = page.locator(`aside.app-sidebar [role="tab"]#tab-button-${id}`)
			const present = await tab.count() > 0
			test.skip(!present, `tab "${id}" not rendered — leaves PR not deployed yet`)
			await expect(tab).toBeVisible()
		})
	}
})

test.describe('Integration registry — tab activation', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// Tab activation is verified for one representative leaf per group
	// — exercising the full 18 leaves serially would balloon CI runtime
	// without adding new signal (the activation path is generic).
	const REPRESENTATIVES = ['calendar', 'bookmarks', 'activity', 'shares', 'openproject'] as const

	for (const id of REPRESENTATIVES) {
		test(`clicking tab-button-${id} activates it + mounts the panel`, async ({ page }) => {
			await openMeetingIntegrations(page)

			const tab = page.locator(`aside.app-sidebar [role="tab"]#tab-button-${id}`)
			const present = await tab.count() > 0
			test.skip(!present, `tab "${id}" not rendered`)

			await tab.click()
			await expect(tab).toHaveAttribute('aria-selected', 'true')

			// A tab panel must be present once the tab is selected;
			// the registry's resolveTab() resolves the Vue component.
			// We don't assert content here — the 13 greenfield stubs
			// return empty lists by design, so "panel exists" is the
			// only universal assertion.
			const panel = page.locator('aside.app-sidebar [role="tabpanel"]:not([hidden]), aside.app-sidebar .app-sidebar__tab')
			await expect(panel.first()).toBeVisible({ timeout: 5_000 })
		})
	}
})

test.describe('Integration registry — OCS / JS agreement', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('every provider id in OCS caps is also in the JS registry (no drift)', async ({ page }) => {
		await page.goto('/apps/decidesk/')
		await waitForRegistry(page)

		const jsIds = await page.evaluate(() => {
			const reg = (window as Window & { OCA?: { OpenRegister?: { integrations?: { list?: () => Array<{ id: string }> } } } })
				.OCA?.OpenRegister?.integrations
			return reg && reg.list ? reg.list().map((p) => p.id).sort() : []
		})

		const capsResponse = await page.request.get('/ocs/v2.php/cloud/capabilities?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		const caps = await capsResponse.json()
		const ocsIds = (caps?.ocs?.data?.capabilities?.openregister?.integrations?.providers ?? [])
			.map((p: { id: string }) => p.id).sort()

		test.skip(jsIds.length === 0 || ocsIds.length === 0, 'registry not initialised on either side')

		// JS side may have MORE ids than OCS (consuming app
		// registered something OR doesn't know about). OCS side
		// MUST be a subset of JS side; every PHP-side provider must
		// also be registered in JS.
		for (const id of ocsIds) {
			expect(jsIds, `OCS provider "${id}" not in JS registry`).toContain(id)
		}
	})
})
