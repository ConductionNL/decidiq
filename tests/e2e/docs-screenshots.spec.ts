/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Documentation screenshot capture suite — decidesk.
 *
 * This spec is *not* a regression test — it drives the Decidesk UI
 * through every flow documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * The tests below are SKELETONS — selectors are TODOs the team fills
 * in once the relevant Vue components have stable `data-testid`
 * attributes. Add a story by appending a new `test(...)` block — see
 * `/journeydoc-add-story`. Add testids with `/journeydoc-instrument`.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')

/**
 * Save a screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

// Capture flows are independent — each test re-navigates from
// `/apps/decidesk/` so a selector miss on one doesn't cascade.
// Selector misses are the expected first-run failure mode (UI markup
// drifts faster than docs); failures land per-test in `test-results/`
// rather than killing the suite.
test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
	await page.goto('/apps/decidesk/')
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		/* TODO: see /journeydoc-add-story — capture each numbered step.
		   Add data-testids first via /journeydoc-instrument. */
		await shoot(page, 'user', '01-first-launch.png')
	})

	test('UN schedule-meeting', async ({ page }) => {
		// docs/tutorials/user/02-schedule-meeting.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'user', '02-schedule-meeting.png')
	})

	test('UN add-motion', async ({ page }) => {
		// docs/tutorials/user/03-add-motion.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'user', '03-add-motion.png')
	})

	test('UN propose-amendment', async ({ page }) => {
		// docs/tutorials/user/04-propose-amendment.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'user', '04-propose-amendment.png')
	})

	test('UN run-vote', async ({ page }) => {
		// docs/tutorials/user/05-run-vote.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'user', '05-run-vote.png')
	})

	test('UN take-minutes', async ({ page }) => {
		// docs/tutorials/user/06-take-minutes.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'user', '06-take-minutes.png')
	})

	test('UN track-decisions', async ({ page }) => {
		// docs/tutorials/user/07-track-decisions.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'user', '07-track-decisions.png')
	})

	test('UN ai-companion', async ({ page }) => {
		// docs/tutorials/user/08-ai-companion.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'user', '08-ai-companion.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/settings/admin/decidesk')
		await page.waitForLoadState('networkidle')
	})

	test('UN configure-workflow', async ({ page }) => {
		// docs/tutorials/admin/01-configure-workflow.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'admin', '01-configure-workflow.png')
	})

	test('UN manage-members', async ({ page }) => {
		// docs/tutorials/admin/02-manage-members.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'admin', '02-manage-members.png')
	})

	test('UN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md
		// TODO: see /journeydoc-add-story
		await shoot(page, 'admin', '03-admin-settings.png')
	})
})
