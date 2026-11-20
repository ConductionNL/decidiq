/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: every page works at phone width (change
 * platform-baseline-on-nextcloud, REQ-PLB-008, plt-05). Opens every route the
 * manifest and its fragments declare at 360 by 740 and asserts the page does
 * not scroll sideways and that a page declaring a primary header action
 * still shows it. Routes with a parameter are reached through their list:
 * the meeting page is opened from the meetings list, as a member on a phone
 * would.
 *
 * The routes are read from the manifest files at run time, so a page added
 * later is covered without editing this file. The failures it prints are the
 * list task 4 asks for; each is fixed with a layout rule on that page, not a
 * phone-only copy of it.
 *
 * Written, not yet run: it needs a live instance with an example set, so the
 * lists have rows and the meeting page has an agenda.
 *
 * @spec openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-008-every-page-works-at-phone-width
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'

const WIDTH = 360
const HEIGHT = 740

interface ManifestPage {
	id: string
	route: string
	type?: string
	config?: { headerButtons?: Array<{ action?: string; variant?: string }> }
}

/**
 * Every page the manifest and its fragments declare, by id (a fragment's page
 * replaces the main manifest's page with the same id, as the app merges them).
 *
 * @return {ManifestPage[]} The pages.
 */
function manifestPages(): ManifestPage[] {
	const root = path.resolve(__dirname, '../..')
	const fragments = fs
		.readdirSync(path.join(root, 'src/manifest.d'))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.map((name) => path.join(root, 'src/manifest.d', name))
	const byId = new Map<string, ManifestPage>()
	for (const file of [path.join(root, 'src/manifest.json'), ...fragments]) {
		const manifest = JSON.parse(fs.readFileSync(file, 'utf8'))
		for (const page of (manifest.pages ?? []) as ManifestPage[]) {
			byId.set(page.id, page)
		}
	}
	return [...byId.values()]
}

/**
 * How far the page scrolls sideways beyond the viewport, in pixels.
 *
 * @param {Page} page The page.
 * @return {Promise<number>} The overflow; 0 when none.
 */
async function sidewaysOverflow(page: Page): Promise<number> {
	return page.evaluate(() =>
		Math.max(0, document.documentElement.scrollWidth - window.innerWidth),
	)
}

test.use({ viewport: { width: WIDTH, height: HEIGHT } })

const staticPages = manifestPages().filter((page) => !page.route.includes(':'))

for (const manifestPage of staticPages) {
	// @e2e platform-baseline::a-member-reads-the-agenda-on-a-phone
	test(`${manifestPage.id} (${manifestPage.route}) does not scroll sideways at ${WIDTH} pixels`, async ({
		page,
	}) => {
		await page.goto(
			`${BASE}/index.php/apps/decidiq${manifestPage.route === '/' ? '/' : manifestPage.route}`,
		)
		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('main').first()).toBeVisible()

		expect(
			await sidewaysOverflow(page),
			`${manifestPage.route} scrolls sideways`,
		).toBe(0)

		const declaresPrimary = (manifestPage.config?.headerButtons ?? []).some(
			(button) => button.variant === 'primary',
		)
		if (declaresPrimary) {
			await expect(
				page
					.locator(
						'main .button-vue--vue-primary, main [class*="--primary"]',
					)
					.first(),
			).toBeInViewport()
		}
	})
}

// @e2e platform-baseline::a-member-reads-the-agenda-on-a-phone
test(`MeetingDetail: the agenda reads in one column and its actions are reachable at ${WIDTH} pixels`, async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings`)
	const firstRow = page.locator('tbody tr').first()
	await expect(firstRow).toBeVisible()
	await openRow(page, (await firstRow.innerText()).split('\n')[0].trim())
	await page.waitForLoadState('domcontentloaded')
	await expect(page.locator('main').first()).toBeVisible()

	expect(await sidewaysOverflow(page), 'the meeting page scrolls sideways').toBe(0)
	for (const action of await page.locator('main button').all()) {
		if (await action.isVisible()) {
			const box = await action.boundingBox()
			expect(
				box === null || box.x + box.width <= WIDTH + 1,
				`${await action.innerText()} lies off screen`,
			).toBe(true)
		}
	}
})
