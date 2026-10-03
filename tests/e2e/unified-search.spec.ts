/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: minutes in Nextcloud's unified search (change
 * minutes-in-unified-search). Reads decidiq's provider through Nextcloud's
 * OCS search API, the same call the search bar makes.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const word = `woningbouw${ledger.runId.replace(/[^0-9]/g, '')}`

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e nextcloud-integration::a-member-finds-what-was-said
test('minutes are found by a word in them and open the minutes page', async ({
	page,
}) => {
	const minutes = await createObject(page, ledger, 'minutes', {
		title: `Notulen raad ${word}`,
		lifecycle: 'approved',
		approvedAt: '2026-11-01T10:00:00+00:00',
		content: `De raad sprak over ${word} Noord.`,
	})
	const id = minutes.id ?? minutes['@self']?.id
	await expect
		.poll(
			async () => {
				const resp = await page.request.get(
					`${BASE}/ocs/v2.php/search/providers/decidiq/search?term=${word}&format=json`,
					{
						headers: {
							'OCS-APIRequest': 'true',
							Accept: 'application/json',
						},
					},
				)
				const entries = (await resp.json())?.ocs?.data?.entries ?? []
				return (
					entries.find((e: { resourceUrl: string }) =>
						e.resourceUrl.endsWith(`/minutes/${id}`),
					) ?? null
				)
			},
			{ timeout: 20_000 },
		)
		.toMatchObject({ subline: expect.stringContaining('Minutes') })
})
