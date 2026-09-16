/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from decidiq's `lib/Settings/connections.json`, with `app` equal to
 * `decidiq`. Decidiq writes no row: a settings save asks integriq to resolve
 * again, and integriq decides the status. So this spec needs integriq
 * installed and synced, and reads the rows from
 * `/apps/openregister/api/objects/integriq/app_connection?app=decidiq`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * WHAT A RED HERE USUALLY MEANS. Integriq refuses a declaration with a field
 * its schema does not know, whole and in silence. Decidiq declares
 * `reportedOnly` (hydra#673). An integriq without that amendment syncs no
 * decidiq row, and the first test below fails on an empty list.
 *
 * Locale: nothing forces the E2E language, so statuses are read from the
 * API and rows are found by their declared titles, which are not translated.
 *
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#the-page-lists-only-decidiqs-rows
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#add-integration-goes-to-integriq
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#a-saved-ori-endpoint-reads-configured
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { writeHeaders } from './governance-fixture.ts'

/** Integriq's objects endpoint for decidiq's connection rows. */
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection?app=decidiq&_limit=50'

/** Decidiq's settings endpoint. */
const SETTINGS_API = '/index.php/apps/decidiq/api/settings'

/** The declared keys and titles, in declared order. */
const DECLARED = [
	{ key: 'ori', title: 'ORI publication' },
	{ key: 'eidas', title: 'eIDAS signatures' },
	{ key: 'translation', title: 'Translation' },
]

/**
 * Decidiq's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(
	request: APIRequestContext,
): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, {
		headers: { Accept: 'application/json' },
	})
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe('decidiq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto('/apps/decidiq/settings/integrations?app=decidiq', {
		timeout: 60_000,
	})
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations over the connection registry', () => {
	test("lists the three declared connections, all of them decidiq's", async ({
		page,
	}) => {
		const byKey = await rowsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())

		await openIntegrations(page)
		for (const { title } of DECLARED) {
			await expect(
				page.getByRole('row', { name: new RegExp(title, 'i') }),
			).toHaveCount(1)
		}
	})

	test('marks ORI configured once an endpoint is saved', async ({ page }) => {
		const headers = { ...(await writeHeaders(page)), 'OCS-APIRequest': 'true' }

		// Snapshot the one key this test writes, and put the VALUE back, so the
		// next run starts from a page that claims nothing it has not checked.
		const before = await page.request.get(SETTINGS_API)
		expect(before.ok(), `settings read -> ${before.status()}`).toBeTruthy()
		const previous = String((await before.json())?.ori_endpoint ?? '')

		try {
			// `.invalid` never resolves, and saving an endpoint publishes nothing:
			// only closing a voting round posts to it.
			const res = await page.request.post(SETTINGS_API, {
				headers,
				data: { ori_endpoint: 'https://ori.example.invalid/v1/stemmingen' },
			})
			expect(res.ok(), `settings save -> ${res.status()}`).toBeTruthy()

			// The poll reads without asserting: a throw inside `expect.poll` ends
			// the poll instead of retrying it.
			await expect
				.poll(
					async () => {
						const list = await page.request.get(CONNECTIONS_API, {
							headers: { Accept: 'application/json' },
						})
						const rows = list.ok()
							? ((await list.json()).results ?? [])
							: []
						const ori = rows.find(
							(row: Record<string, unknown>) =>
								row.key === 'ori' && row.app === 'decidiq',
						)
						return String(ori?.status ?? '')
					},
					{ timeout: 15_000 },
				)
				.toBe('configured')
		} finally {
			await page.request.post(SETTINGS_API, {
				headers,
				data: { ori_endpoint: previous },
			})
		}
	})

	test('sends Add integration to integriq instead of offering a form', async ({
		page,
	}) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale.
		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=decidiq&link=1$/, {
				timeout: 30_000,
			}),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
	})
})
