// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: every read of a paper of a non-public agenda item lands on
 * that item's audit trail with the reader (agenda-item-confidential-papers,
 * REQ-ACP-006).
 *
 * `ClosedPaperReadsTest` and `PaperControllerTest` pin the entry against a
 * double of the audit log. Only a real instance shows that opening a paper
 * through `GET /api/papers/agenda-item/{itemId}/{fileId}/view` appends a
 * `closed-paper-read` entry to OpenRegister's audit trail of the item itself,
 * naming the reader and the file, and that a paper of a public item adds none.
 *
 * WHAT IT CREATES AND REMOVES. One governance body, one meeting with
 * watermarking switched off (so filinq is not needed), a closed and a public
 * agenda item with one fixture paper each, all removed in afterAll.
 *
 * Written, not run here: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
 */

import type { Page } from '@playwright/test'
import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import * as path from 'node:path'
import { BASE_URL as BASE } from './base-url.ts'
import { adminActor, disposeActors } from './support/api-actors.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	objId,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const APP = `${BASE}/index.php/apps/decidiq`
const ITEMS = `${BASE}/index.php/apps/openregister/api/objects/decidiq/agenda-item`
const PAPER = 'tagged-paper.pdf'

let admin: Actor | undefined
let closedItem = ''
let closedPaper = 0
let publicItem = ''
let publicPaper = 0

/**
 * Attach the fixture paper to an agenda item through OpenRegister's files API.
 *
 * @param page   A page with the administrator's session.
 * @param itemId The agenda item.
 * @return The Nextcloud file id.
 */
async function attach(page: Page, itemId: string): Promise<number> {
	const resp = await page.request.post(`${ITEMS}/${itemId}/files`, {
		headers: await writeHeaders(page),
		data: {
			name: PAPER,
			content: readFileSync(
				path.resolve(__dirname, 'fixtures', PAPER),
				'latin1',
			),
		},
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const body = await resp.json()
	return Number(body?.id ?? body?.fileId ?? 0)
}

/**
 * The closed-paper-read entries on an item's audit trail.
 *
 * @param page   A page with the administrator's session.
 * @param itemId The agenda item.
 * @return The entries, as JSON text each.
 */
async function readEntries(page: Page, itemId: string): Promise<string[]> {
	const trail = await page.request.get(`${ITEMS}/${itemId}/audit-trails`, {
		headers: { Accept: 'application/json' },
	})
	expect(trail.ok(), await trail.text()).toBe(true)
	const body = await trail.json()
	const entries = (Array.isArray(body) ? body : (body.results ?? [])) as unknown[]
	return entries
		.map((entry) => JSON.stringify(entry))
		.filter((text) => text.includes('closed-paper-read'))
}

test.describe('Every read of a closed paper is logged on its item', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	test.beforeAll(async ({ browser, playwright }) => {
		const page = await browser.newPage()
		admin = await adminActor(playwright)
		const body = await createObject(page, ledger, 'governance-body', {
			name: `${tag}-read-log`,
			bodyType: 'legislative',
			domain: 'municipality',
		})
		const meeting = objId(
			await createObject(page, ledger, 'meeting', {
				title: `${tag} Raadsvergadering`,
				meetingType: 'regular',
				meetingMode: 'in-person',
				scheduledDate: '2026-12-10T19:30:00Z',
				governanceBody: objId(body),
				watermarkPapers: 'off',
				lifecycle: 'scheduled',
			}),
		)
		closedItem = objId(
			await createObject(page, ledger, 'agenda-item', {
				title: `${tag} Verlichting fietspaden`,
				itemType: 'decision',
				orderNumber: 7,
				meeting,
				public: false,
			}),
		)
		closedPaper = await attach(page, closedItem)
		publicItem = objId(
			await createObject(page, ledger, 'agenda-item', {
				title: `${tag} Rondvraag`,
				itemType: 'information',
				orderNumber: 8,
				meeting,
				public: true,
			}),
		)
		publicPaper = await attach(page, publicItem)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupAll(page, ledger)
		await page.close()
		await disposeActors(admin)
	})

	// @e2e agenda-item-confidentiality::the-griffie-checks-who-read-the-paper
	test('opening a paper of a closed item adds an entry naming the reader and the paper to the item history', async ({
		page,
	}) => {
		const before = (await readEntries(page, closedItem)).length

		const resp = await admin!.ctx.get(
			`${APP}/api/papers/agenda-item/${closedItem}/${closedPaper}/view`,
		)
		expect(resp.status(), await resp.text()).toBe(200)

		const entries = await readEntries(page, closedItem)
		expect(entries.length).toBe(before + 1)
		const entry = entries[entries.length - 1]
		expect(entry).toContain(ADMIN_USER)
		expect(entry).toContain(String(closedPaper))
	})

	// @e2e agenda-item-confidentiality::the-griffie-checks-who-read-the-paper
	test('opening a paper of a public item adds no such entry', async ({ page }) => {
		const resp = await admin!.ctx.get(
			`${APP}/api/papers/agenda-item/${publicItem}/${publicPaper}/view`,
		)
		expect(resp.status(), await resp.text()).toBe(200)

		expect(await readEntries(page, publicItem)).toEqual([])
	})
})
