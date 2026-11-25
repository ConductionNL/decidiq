/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the public ORI feeds serve without a session and list only
 * public objects (change ori-adoption, REQ-ORIA-004).
 *
 * Seeds one public and one non-public meeting, each with one agenda item that
 * carries one document. An anonymous caller (a context with an empty storage
 * state) then reads the three feeds: each answers 200 with an RSS document,
 * lists the public meeting, its item and its document, and leaves out every
 * object of the non-public meeting.
 *
 * The target comes from base-url.ts (BASE_URL / PLAYWRIGHT_BASE_URL /
 * NEXTCLOUD_URL); there is no fallback to a shared container.
 *
 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md#requirement-req-oria-004-ori-wire-format-interop-shall-live-in-an-adapter-only
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { cleanupAll, createObject, newLedger, objId } from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const ids: Record<string, string> = {}

/**
 * Read one feed without any session.
 *
 * @param anonymous A request context with an empty storage state.
 * @param feed      The feed's file name.
 */
async function readFeed(anonymous: APIRequestContext, feed: string): Promise<string> {
	const resp = await anonymous.get(`${BASE}/index.php/apps/decidiq/feed/ori/${feed}`)
	expect(resp.status(), await resp.text()).toBe(200)
	expect(resp.headers()['content-type']).toContain('application/rss+xml')
	const xml = await resp.text()
	expect(xml).toContain('<rss')
	return xml
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const [key, isPublic] of Object.entries({ open: true, closed: false })) {
		const meeting = await createObject(page, ledger, 'meeting', {
			title: `${tag} ${key} meeting`,
			meetingType: 'regular',
			meetingMode: 'in-person',
			scheduledDate: '2026-12-10T19:30:00Z',
			lifecycle: 'scheduled',
			isPublic,
		})
		ids[`${key}Meeting`] = objId(meeting)
		const doc = await createObject(page, ledger, 'digital-document', {
			name: `${tag} ${key} document`,
			documentType: 'report',
		})
		ids[`${key}Document`] = objId(doc)
		const item = await createObject(page, ledger, 'agenda-item', {
			title: `${tag} ${key} item`,
			itemType: 'discussion',
			orderNumber: 1,
			meeting: ids[`${key}Meeting`],
			documents: [ids[`${key}Document`]],
		})
		ids[`${key}Item`] = objId(item)
	}
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e ori-adoption::public-feeds-serve-without-a-session
test('the three ORI feeds serve anonymously and list only public objects', async ({ playwright }) => {
	const anonymous = await playwright.request.newContext({
		storageState: { cookies: [], origins: [] },
	})
	const meetings = await readFeed(anonymous, 'meetings.rss')
	expect(meetings).toContain(`${tag} open meeting`)
	expect(meetings).not.toContain(`${tag} closed meeting`)

	const items = await readFeed(anonymous, 'agenda-items.rss')
	expect(items).toContain(`${tag} open item`)
	expect(items).not.toContain(`${tag} closed item`)

	const documents = await readFeed(anonymous, 'documents.rss')
	expect(documents).toContain(`${tag} open document`)
	expect(documents).not.toContain(`${tag} closed document`)
	await anonymous.dispose()
})
