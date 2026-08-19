/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — the Goals index and detail pages
 * (src/manifest.d/organisation-goals.json), declared entirely via the
 * generic `data`/`related` widgets with no bespoke Vue component. This
 * surface had no e2e coverage at all.
 *
 * Read-only: the five seeded `goal` objects
 * (lib/Settings/register.d/66-organisation-goals.json) already cover the
 * index listing and one is used for the detail page — no fixtures are
 * created, so no cleanup is needed.
 *
 * @e2e openspec/specs/organisation-goals/spec.md#scenario-goals-pages-render-without-new-vue-components
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidesk`

const SEEDED_GOAL_TITLES = [
	'Duurzame omzetgroei 2028',
	'Operationele effectiviteit 2026',
	'Amsterdam klimaatneutraal',
	'Herzien parkeerbeleid vastgesteld',
	'Digitale dienstverlening leden',
]

/** Dismiss the cn-support-dialog if it auto-opened and is intercepting clicks. */
async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page
		.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]')
		.first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

/**
 * Navigate to a decidesk page, preferring the APP's left navigation entry
 * (app-scoped) when it exists. `menu-layout.json` folds the "Goals" nav
 * entry into the "ActionItems" (Tasks & Commitments) cluster, so
 * `cn-nav-entry-Goals` is not expected to exist as a top-level entry — the
 * fallback to the app-scoped route is the normal path here, not an error
 * case. `route` is the app-scoped path used when `cn-nav-entry-<entryId>`
 * is absent.
 */
async function appNavClick(
	page: Page,
	entryId: string,
	route: string,
): Promise<void> {
	await page.goto(`${BASE}/apps/decidesk/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
	const entry = page.locator(`[data-testid="cn-nav-entry-${entryId}"]`).first()
	if (await entry.isVisible().catch(() => false)) {
		await entry.click()
		return
	}
	await page.goto(`${BASE}/apps/decidesk${route}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
}

/** GET a collection through the authenticated page session (read-only). */
async function listObjects(page: Page, schema: string): Promise<any[]> {
	const resp = await page.request.get(`${OR}/${schema}?_limit=200`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) return []
	const body = await resp.json()
	return body.results ?? body.items ?? []
}

// @e2e openspec/specs/organisation-goals/spec.md#scenario-goals-pages-render-without-new-vue-components
test('Goals: index lists all five seeded goals', async ({ page }) => {
	test.setTimeout(35_000)
	await appNavClick(page, 'Goals', '/goals')

	await expect(page).toHaveURL(/\/apps\/decidesk\/.*goals/)
	await expect(page.getByTestId('cn-index-page').first()).toBeVisible()

	for (const title of SEEDED_GOAL_TITLES) {
		await expect(page.getByText(title, { exact: true })).toBeVisible()
	}
})

// @e2e openspec/specs/organisation-goals/spec.md#scenario-goals-pages-render-without-new-vue-components
test('Goals: detail page renders a seeded goal via the generic data/related widgets', async ({
	page,
}) => {
	test.setTimeout(35_000)

	const goals = await listObjects(page, 'goal')
	const target = goals.find((g) => g.title === 'Amsterdam klimaatneutraal')
	test.skip(!target, 'Seed goal "Amsterdam klimaatneutraal" not found')
	const goalId = target.id ?? target['@self']?.id
	test.skip(!goalId, 'Seed goal has no id')

	await page.goto(`${BASE}/apps/decidesk/goals/${goalId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await expect(
		page.getByRole('heading', { name: 'Goal', exact: true }),
	).toBeVisible()
	await expect(
		page.getByRole('heading', { name: 'Related', exact: true }),
	).toBeVisible()
	await expect(
		page.getByText('Amsterdam klimaatneutraal', { exact: true }),
	).toBeVisible()
})
