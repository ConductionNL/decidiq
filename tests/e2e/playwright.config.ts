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
 * Note what is missing: `--project`. Whichever config it picks, EVERY project
 * in it runs. The root `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). It re-shoots every
 *                  tutorial screenshot into `docs/static/screenshots/…` and is
 *                  driven deliberately by `--project docs-capture`.
 *   visual       — pixel-diff baselines. Its own README records the reason it
 *                  cannot gate: "PNG baselines are host-font/GPU specific, so a
 *                  CI Linux runner will not byte-match a dev-container
 *                  baseline". Running it on CI produces guaranteed red that
 *                  says nothing about the app.
 *
 * Letting the root config be picked would therefore make every PR re-shoot the
 * documentation screenshots AND fail on baselines the runner cannot reproduce.
 * Rather than delete or weaken either project, `playwright-test-path: tests/e2e`
 * in the caller makes the workflow's FIRST lookup hit this file, which declares
 * only the regression project. The root config is untouched and stays the entry
 * point for local runs, `--project docs-capture` and `--project visual`.
 *
 * ⚠️ `testIgnore` HAS TO BE REPEATED AT PROJECT LEVEL.
 * A project-level `testIgnore` REPLACES the top-level one, it does not merge
 * with it. Both lists below are therefore identical on purpose: a future reader
 * who deletes one of them does not silently start collecting `global-setup.ts`,
 * `base-url.ts`, `workflows/governance-fixture.ts` and
 * `visual/_visual-helpers.ts` as if they were specs (all four export helpers,
 * not tests — Playwright errors with "no tests found in file").
 *
 * ARTIFACT PATHS
 * --------------
 * Report and traces stay under `tests/e2e/…` rather than moving to the app
 * root. The shared workflow's upload steps list
 * `server/apps/<app>/tests/e2e/playwright-report/` and
 * `.../tests/e2e/test-results/` alongside the app-root paths, so both produce a
 * downloadable artifact — and keeping them here matches the root config, so a
 * local run and a CI run write to the same place.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { BASE_URL } from './base-url'

/**
 * Non-spec modules that live inside `testDir` and must never be collected.
 *
 * - `global-setup.ts`            — the globalSetup entry point itself.
 * - `base-url.ts`                — the shared target resolver.
 * - `workflows/governance-fixture.ts` — seed/teardown helpers for the deep specs.
 * - `visual/**`                  — the `visual` project's specs + helpers +
 *                                 committed PNG baselines (see header).
 * - `docs-screenshots.spec.ts`   — the `docs-capture` project's spec.
 */
const NON_CI_PATTERNS = [
	'**/global-setup.ts',
	'**/base-url.ts',
	'**/governance-fixture.ts',
	'**/visual/**',
	'**/docs-screenshots.spec.ts',
]

export default defineConfig({
	testDir: __dirname,
	// See NON_CI_PATTERNS: also repeated on the project below, because a
	// project-level testIgnore replaces rather than extends this list.
	testIgnore: NON_CI_PATTERNS,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	// TEMPORARY DIAGNOSTIC SETTINGS — revert before merge.
	// The first enabled run (30858373402) hit the shared workflow's
	// `timeout-minutes: 45` and was CANCELLED, which is no verdict at all. It
	// spent ~42 minutes burning the full 60s test timeout on 43 consecutive
	// executions of integration-registry.spec.ts. These settings exist purely to
	// make a run FINISH so it produces a report with real error text.
	timeout: 25_000,
	expect: { timeout: 8_000 },
	fullyParallel: false,
	retries: 0,
	workers: 3,
	reporter: [
		['html', { open: 'never', outputFolder: path.resolve(__dirname, 'playwright-report') }],
		['list'],
	],
	outputDir: path.resolve(__dirname, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/base-url.ts.
		baseURL: BASE_URL,
		// Written by global-setup.ts after the admin login.
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		// DIAGNOSTIC: retries are 0 for this run, so 'on-first-retry' would
		// capture nothing. Revert to 'on-first-retry' with the block above.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: NON_CI_PATTERNS,
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
