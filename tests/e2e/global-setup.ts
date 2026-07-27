/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/admin.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * Why a real browser login (instead of POSTing to /login directly):
 * Nextcloud's login form ships a CSRF token (`requesttoken`) plus a
 * `oc_session_passphrase` cookie that must be set in the same browser
 * context. Driving the form via Playwright sidesteps having to
 * reverse-engineer the token-rotation contract, which has shifted across
 * NC 28 / 29 / 30.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/), mirrored
 * from launchpad's journeydoc setup.
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'decidesk-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/decidesk/`.
 *
 * The shared `ConductionNL/.github/quality.yml` Playwright job runs
 * `npm ci` + `npx playwright install` before the spec run, but never
 * `npm run build`. On a fresh CI VM the `js/decidesk-main.js` artefact
 * doesn't exist, so the rendered page loads a 404 script tag and the
 * Vue app never mounts — every selector wait then times out.
 *
 * Note: locally, the app running in the dev container is mounted from a
 * *separate* checkout (`openregister/custom_apps/decidesk`), so this
 * build only helps CI / a checkout that serves its own `js/`.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// eslint-disable-next-line no-console
	console.log(`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. ` +
				`Make sure the docker container is running and reachable.`,
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined)
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		?? 'http://localhost:8080'
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Hit the login form so the CSRF token + session passphrase land in
	// the browser jar.
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(username)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').first().click()
	// Nextcloud bounces to /apps/dashboard/ (or another default app) on
	// success. Wait for the global header that only renders on
	// authenticated pages.
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. ` +
			`Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).`,
		)
	}

	// Suppress the first-visit onboarding walkthrough for the whole suite.
	//
	// CnAppRoot (nc-vue v2) auto-starts the `first-visit` tour whenever the
	// per-origin localStorage key `cn-walkthrough-seen:<appId>` is empty
	// (see CnWalkthrough autoStartTour: `trigger === 'first-visit' && !seenVersion`).
	// The tour paints a full-viewport `cn-walkthrough__dim` overlay that
	// intercepts every click, so `getByRole('link', …).click()` navigation
	// times out and ~40 specs cascade-fail.
	//
	// We visit the app once and seed that key with a version far above any
	// manifest version. Playwright's storageState captures per-origin
	// localStorage, so every spec that reuses this storage state starts with
	// the walkthrough already marked as seen — no occ, no API, CI-safe and
	// self-contained.
	await page.goto('/apps/decidesk/')
	await page.evaluate(() => {
		try {
			window.localStorage.setItem('cn-walkthrough-seen:decidesk', '9999.0.0')
		} catch (e) {
			// Non-fatal: private mode / no storage.
		}
	})

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
