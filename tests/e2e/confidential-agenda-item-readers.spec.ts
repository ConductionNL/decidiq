/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: only authorised readers read a non-public agenda item and
 * its papers (change agenda-item-confidential-papers, REQ-ACP-002), through
 * OpenRegister's own read rules on real accounts.
 *
 * Seeds two bodies, a member account in each (a participant row names the
 * account), and an external account. One meeting of the first body holds a
 * non-public item with one paper. The member of that body reads both; the
 * member of the other body gets no row from the list and a 404 from the single
 * read; once the secretariat adds the external account to the item's readers,
 * that account reads the item and its paper, and the other member still does
 * not.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-002-only-authorised-readers-can-read-a-non-public-item-and-its-papers
 */
import type { PlaywrightWorkerArgs } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'
import { cleanupAll, createObject, newLedger, objId, writeHeaders } from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const member = `${tag}-sanne`.slice(0, 60)
const other = `${tag}-pieter`.slice(0, 60)
const outsider = `${tag}-accountant`.slice(0, 60)
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
const ids: Record<string, string> = {}

/**
 * Basic-auth headers for an account.
 *
 * @param user The account.
 * @param pass Its password.
 */
function basic(user: string, pass: string): Record<string, string> {
	return {
		Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * What one account reads of the item and its paper, from a context without the suite's admin session.
 *
 * @param playwright The Playwright object.
 * @param user       The account.
 */
async function reads(playwright: PlaywrightWorkerArgs['playwright'], user: string): Promise<{ item: number, paper: number, listed: boolean }> {
	const request = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	const headers = basic(user, password)
	const item = await request.get(`${OR}/agenda-item/${ids.item}`, { headers })
	const paper = await request.get(`${OR}/digital-document/${ids.paper}`, { headers })
	const list = await request.get(`${OR}/agenda-item?_limit=200&meeting=${ids.meeting}`, { headers })
	expect(list.status(), await list.text()).toBe(200)
	const rows = ((await list.json()).results ?? []) as Array<Record<string, unknown>>
	const seen = { item: item.status(), paper: paper.status(), listed: rows.some((row) => objId(row) === ids.item) }
	await request.dispose()
	return seen
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const user of [member, other, outsider]) {
		const created = await page.request.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
			headers: basic(ADMIN_USER, ADMIN_PASS),
			data: { userid: user, password },
		})
		expect(created.ok(), await created.text()).toBe(true)
	}
	for (const [key, user] of [['raad', member], ['rekenkamer', other]]) {
		const body = await createObject(page, ledger, 'governance-body', { name: `${tag}-${key}`, bodyType: 'legislative', domain: 'municipality' })
		ids[key] = objId(body)
		await createObject(page, ledger, 'participant', {
			displayName: `${tag}-${user}`,
			role: 'member',
			nextcloudUserId: user,
			governanceBody: ids[key],
		})
	}
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag} Raadsvergadering`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: '2026-12-10T19:30:00Z',
		lifecycle: 'scheduled',
		governanceBody: ids.raad,
	})
	ids.meeting = objId(meeting)
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag} Verlichting fietspaden`,
		itemType: 'decision',
		orderNumber: 7,
		meeting: ids.meeting,
		public: false,
	})
	ids.item = objId(item)
	const paper = await createObject(page, ledger, 'digital-document', {
		name: `${tag} Kostenraming verlichting`,
		documentType: 'report',
		agendaItem: ids.item,
	})
	ids.paper = objId(paper)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	for (const user of [member, other, outsider]) {
		await page.request
			.delete(`${BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(user)}?format=json`, { headers: basic(ADMIN_USER, ADMIN_PASS) })
			.catch(() => undefined)
	}
	await page.close()
})

// @e2e agenda-item-confidentiality::a-member-of-the-body-reads-the-paper
test('a member of the body reads the closed item and its paper', async ({ playwright }) => {
	expect(await reads(playwright, member)).toEqual({ item: 200, paper: 200, listed: true })
})

// @e2e agenda-item-confidentiality::a-user-outside-the-body-cannot
test('a member of another body gets no item and no paper', async ({ playwright }) => {
	expect(await reads(playwright, other)).toEqual({ item: 404, paper: 404, listed: false })
})

// @e2e agenda-item-confidentiality::the-griffier-adds-a-reader
test('a reader the secretariat adds reads the item and its papers, nobody else outside the body does', async ({ page, playwright }) => {
	expect(await reads(playwright, outsider)).toEqual({ item: 404, paper: 404, listed: false })

	const headers = await writeHeaders(page)
	const current = await (await page.request.get(`${OR}/agenda-item/${ids.item}`, { headers })).json()
	const body = Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('@') && key !== 'id'))
	const saved = await page.request.put(`${OR}/agenda-item/${ids.item}`, {
		headers,
		data: { ...body, authorisedReaders: [outsider] },
	})
	expect(saved.ok(), await saved.text()).toBe(true)

	expect(await reads(playwright, outsider)).toEqual({ item: 200, paper: 200, listed: true })
	expect(await reads(playwright, other)).toEqual({ item: 404, paper: 404, listed: false })
})
