/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The WCAG 2.1 AA scan's own config (platform-accessibility-audit-report,
 * matrix row plt-22). Run it with `npm run a11y:scan`, or `npm run a11y:audit`
 * to scan and then write docs/compliance/wcag-2.1-aa-audit.md.
 *
 * It is a separate config, not a project in tests/e2e/playwright.config.ts,
 * because the shared workflow runs that file without `--project`: every
 * project in it runs on every E2E run. Both other configs ignore `a11y/`.
 *
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from '../base-url.ts'

const APP_ROOT = path.resolve(__dirname, '..', '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, '..', 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: 0,
	workers: 1,
	reporter: [['list']],
	outputDir: path.join(APP_ROOT, 'test-results', 'a11y'),
	use: {
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, '..', '.auth', 'admin.json'),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'a11y',
			testMatch: /wcag-audit\.spec\.ts$/,
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
