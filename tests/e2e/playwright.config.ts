/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite (28 spec files). This is the one CI
 *                  wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). Re-shoots every
 *                  tutorial screenshot; the dedicated `Journeydoc Capture`
 *                  job runs it explicitly with `--project docs-capture`.
 *   visual       — pixel-diff baselines. The root config's own header says the
 *                  PNGs are host-font/GPU specific and "a CI Linux runner will
 *                  not byte-match a dev-container baseline".
 *
 * Letting the root config be picked therefore runs two projects that are
 * documented as unable to pass on a CI runner, on top of the one that can.
 * Rather than delete or weaken them, `playwright-test-path: tests/e2e` in the
 * caller makes the workflow's FIRST lookup hit this file, which declares only
 * the regression project. The root config is untouched and stays the entry
 * point for local runs, `npm run test:e2e:docs` and `--project visual`.
 *
 * The report/output paths also differ deliberately: the workflow uploads
 * `server/apps/decidesk/playwright-report/` and
 * `server/apps/decidesk/test-results/`, so on CI the artifacts must land at
 * the app root, not under `tests/e2e/`. With the root config's paths the
 * "Upload Playwright report" step matches nothing and silently uploads an
 * empty artifact (`if-no-files-found: ignore`) — a failing run with no report
 * to read.
 *
 * TARGET RESOLUTION
 * -----------------
 * The workflow's "Run Playwright tests" step exports BASE_URL, NEXTCLOUD_URL
 * and NC_BASE_URL (all `http://localhost:8080`, its own `php -S`). The specs
 * themselves resolve `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`,
 * so the order below keeps `baseURL` identical to what the specs compute — a
 * mismatch between the two would send `page.goto()` and `page.request` to
 * different hosts.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

const APP_ROOT = path.resolve(__dirname, '..', '..')

const BASE_URL = process.env.NEXTCLOUD_URL
	|| process.env.BASE_URL
	|| process.env.NC_BASE_URL
	|| 'http://localhost:8080'

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts', '**/visual/**'],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
