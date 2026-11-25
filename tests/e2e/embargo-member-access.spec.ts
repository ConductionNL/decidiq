/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a paper under embargo is read only by its embargo audience
 * until the release (change embargo-geheimhouding, REQ-EMB-005), through
 * OpenRegister's own read rules on real accounts.
 *
 * Seeds a group standing for the embargo audience (fractievoorzitters), one
 * account in it and one member outside it, and a paper whose embargo runs
 * until 2099 for that group. The audience reads the paper at once; the member
 * gets a 404 and no list row. When the embargo is released (embargoActive
 * written false, as the release job does) the member reads it too.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
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
const audienceGroup = `${tag}-fractievoorzitters`.slice(0, 60)
const chair = `${tag}-chair`.slice(0, 60)
const member = `${tag}-member`.slice(0, 60)
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
let paperId = ''

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
 * What one account reads of the paper, from a context without the suite's admin session.
 *
 * @param playwright The Playwright object.
 * @param user       The account.
 */
async function reads(playwright: PlaywrightWorkerArgs['playwright'], user: string): Promise<{ paper: number, listed: boolean }> {
	const request = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	const headers = basic(user, password)
	const paper = await request.get(`${OR}/digital-document/${paperId}`, { headers })
	const list = await request.get(`${OR}/digital-document?_limit=500`, { headers })
	expect(list.status(), await list.text()).toBe(200)
	const rows = ((await list.json()).results ?? []) as Array<Record<string, unknown>>
	const seen = { paper: paper.status(), listed: rows.some((row) => objId(row) === paperId) }
	await request.dispose()
	return seen
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	const admin = basic(ADMIN_USER, ADMIN_PASS)
	const group = await page.request.post(`${BASE}/ocs/v2.php/cloud/groups?format=json`, { headers: admin, data: { groupid: audienceGroup } })
	expect(group.ok(), await group.text()).toBe(true)
	for (const user of [chair, member]) {
		const created = await page.request.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, { headers: admin, data: { userid: user, password } })
		expect(created.ok(), await created.text()).toBe(true)
	}
	const joined = await page.request.post(`${BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(chair)}/groups?format=json`, {
		headers: admin,
		data: { groupid: audienceGroup },
	})
	expect(joined.ok(), await joined.text()).toBe(true)

	const paper = await createObject(page, ledger, 'digital-document', {
		name: `${tag} Begroting 2027 concept`,
		documentType: 'report',
		embargoActive: true,
		embargoUntil: '2099-01-01T00:00:00Z',
		embargoAudience: audienceGroup,
	})
	paperId = objId(paper)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	const admin = basic(ADMIN_USER, ADMIN_PASS)
	await cleanupAll(page, ledger)
	for (const user of [chair, member]) {
		await page.request.delete(`${BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(user)}?format=json`, { headers: admin }).catch(() => undefined)
	}
	await page.request.delete(`${BASE}/ocs/v2.php/cloud/groups/${encodeURIComponent(audienceGroup)}?format=json`, { headers: admin }).catch(() => undefined)
	await page.close()
})

// @e2e embargo-geheimhouding::entitled-member-sees-the-document-immediately
test('the embargo audience reads the paper at once, a regular member does not', async ({ playwright }) => {
	expect(await reads(playwright, chair)).toEqual({ paper: 200, listed: true })
	expect(await reads(playwright, member)).toEqual({ paper: 404, listed: false })
})

// @e2e embargo-geheimhouding::wider-member-access-unlocks-at-the-embargo-moment
test('after the release a regular member reads the paper', async ({ page, playwright }) => {
	const headers = await writeHeaders(page)
	const current = await (await page.request.get(`${OR}/digital-document/${paperId}`, { headers })).json()
	const body = Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('@') && key !== 'id'))
	// The release job writes exactly this flip through the object API once embargoUntil has passed.
	const released = await page.request.put(`${OR}/digital-document/${paperId}`, { headers, data: { ...body, embargoActive: false } })
	expect(released.ok(), await released.text()).toBe(true)

	expect(await reads(playwright, member)).toEqual({ paper: 200, listed: true })
})
