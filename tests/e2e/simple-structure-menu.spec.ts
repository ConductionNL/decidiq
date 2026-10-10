/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The simple structure, in a browser: eight entries under three captions, and
 * the pages that left the menu one link away.
 *
 * The CI instance runs on the full structure (tests/e2e/ci-seed.sh sets it),
 * so this spec turns the setting to `simple` through the same endpoint the
 * admin tab uses, and puts back what it found. The suite runs one worker,
 * serially, so no other spec sees the menu change under it.
 *
 * WHAT WOULD MAKE THIS PASS FOR THE WRONG REASON, and what stops it. A menu
 * that failed to build renders nothing, and "the nested entries are absent"
 * holds on an empty navigation. So the eight entries are asserted PRESENT and
 * in order first, and absence is only read after that.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/apps/decidiq'
const SETTINGS_API = '/index.php/apps/decidiq/api/settings'
/** The admin session global-setup.ts signs in and saves. */
const STORAGE_STATE = 'tests/e2e/.auth/admin.json'

/** The main menu of the simple structure, captions included, in order. */
const MAIN = [
	'cn-nav-caption-StartCaption',
	'cn-nav-entry-Dashboard',
	'cn-nav-entry-ActionItems',
	'cn-nav-caption-DecisionMakingCaption',
	'cn-nav-entry-Proposals',
	'cn-nav-entry-Meetings',
	'cn-nav-entry-Decisions',
	'cn-nav-entry-Commitments',
	'cn-nav-caption-OrganisationCaption',
	'cn-nav-entry-GovernanceBodies',
	'cn-nav-entry-Registers',
]

/** Entries the full structure nests under a parent. The simple one links to them. */
const NESTED = ['UrgentDecisions', 'GoverningDocuments', 'PlannedAgenda']

/**
 * The CSRF token for a write.
 *
 * @param api The request context.
 */
async function requestToken(api: APIRequestContext): Promise<string> {
	const answer = await api.get('/index.php/csrftoken')
	return (await answer.json()).token
}

/**
 * Store a structure and read back what the server kept.
 *
 * @param api The request context.
 * @param structure `simple` or `full`.
 */
async function store(api: APIRequestContext, structure: string): Promise<string> {
	const saved = await api.post(SETTINGS_API, {
		headers: {
			requesttoken: await requestToken(api),
			'Content-Type': 'application/json',
		},
		data: { menu_structure: structure },
	})
	expect(saved.status(), `POST menu_structure=${structure}`).toBe(200)
	return String((await saved.json()).config.menu_structure)
}

/**
 * Close the first-run wizard when it is open: its modal takes every click.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('The simple structure', () => {
	test.setTimeout(300_000)

	let before = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		const current = await api.get(SETTINGS_API)
		expect(current.status(), 'GET /api/settings').toBe(200)
		before = String((await current.json()).menu_structure ?? '')
		expect(await store(api, 'simple')).toBe('simple')
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		// An instance that never set the key gets `simple` back, which is what
		// an unset key reads as.
		await store(api, before === 'full' ? 'full' : 'simple')
		await api.dispose()
	})

	// @e2e openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#somebody-opens-decidiq-on-a-new-instance
	test('the menu shows eight entries under three captions', async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(nav).toBeVisible({ timeout: 60_000 })
		await dismissSetupWizard(page)

		for (const id of MAIN) {
			await expect(
				nav.getByTestId(id),
				`${id} must be in the menu`,
			).toBeVisible({ timeout: 30_000 })
		}

		// Order, read from the document: every caption and entry of the main
		// menu, in the order the navigation draws them.
		const drawn = await nav.evaluate((root, wanted) => {
			const ids = Array.from(root.querySelectorAll('[data-testid]')).map(
				(node) => node.getAttribute('data-testid') ?? '',
			)
			return ids.filter((id) => wanted.includes(id))
		}, MAIN)
		expect(drawn).toEqual(MAIN)

		// Only now is absence worth reading.
		for (const id of NESTED) {
			await expect(nav.getByTestId(`cn-nav-entry-${id}`)).toHaveCount(0)
		}
	})

	// @e2e openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#a-list-that-left-the-menu-is-one-link-away
	test('urgent decisions are one link away from Proposals, and the page still opens by address', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/motions`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 60_000,
		})
		await dismissSetupWizard(page)

		const link = page
			.getByRole('link', { name: /^(Urgent decisions|Spoedbesluiten)$/i })
			.or(
				page.getByRole('button', {
					name: /^(Urgent decisions|Spoedbesluiten)$/i,
				}),
			)
		await expect(link.first()).toBeVisible({ timeout: 60_000 })
		await link.first().click()
		await expect(page).toHaveURL(/\/urgent-decisions/, { timeout: 30_000 })

		// The deep link works without the menu entry.
		await page.goto(`${APP_BASE}/governing-documents`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page).toHaveURL(/\/governing-documents/)
	})

	// @e2e openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#registers-opens-a-page-with-a-tile-for-each-register
	test('Registers opens a page with a tile for each register', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(nav).toBeVisible({ timeout: 60_000 })
		await dismissSetupWizard(page)

		await nav.getByTestId('cn-nav-entry-Registers').getByRole('link').click()
		await expect(page).toHaveURL(/\/registers$/, { timeout: 30_000 })
		for (const label of [
			/Governing documents|Bestuurlijke documenten/i,
			/Delegations & mandates|Delegaties en mandaten/i,
			/Confidentiality register|Geheimhoudingsregister/i,
			/Proxy authorizations|Volmachten/i,
		]) {
			await expect(page.getByText(label).first()).toBeVisible({
				timeout: 60_000,
			})
		}
	})
})
