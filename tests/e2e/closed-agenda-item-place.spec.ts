/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a reader who may not read a non-public agenda item still
 * sees where it sits, as "Besloten punt" with its number only (change
 * agenda-item-confidential-papers, REQ-ACP-005), through
 * GET /api/meetings/{id}/closed-items on real accounts.
 *
 * Seeds two bodies, a member account in each, and one meeting of the first
 * body with a public item 6, a non-public item 7 and a public item 8. The
 * member of the other body is answered number 7 and nothing else of it; the
 * member of the body, who reads item 7 itself, is answered nothing.
 *
 * Written, not run here: it needs a live instance with OpenRegister's
 * `$memberOf` read rules (openregister#11190).
 *
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */
import type { PlaywrightWorkerArgs } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	objId,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const API = `${BASE}/index.php/apps/decidiq/api`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const member = `${tag}-sanne`.slice(0, 60)
const other = `${tag}-pieter`.slice(0, 60)
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
const ids: Record<string, string> = {}
const closedTitle = `${tag} Aankoop percelen Lindelaan`

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
 * What one account is answered about the meeting's hidden closed items.
 *
 * @param playwright The Playwright object.
 * @param user       The account.
 */
async function closedItems(
	playwright: PlaywrightWorkerArgs['playwright'],
	user: string,
): Promise<{ status: number; body: string }> {
	const request = await playwright.request.newContext({
		storageState: { cookies: [], origins: [] },
	})
	const response = await request.get(
		`${API}/meetings/${ids.meeting}/closed-items`,
		{ headers: basic(user, password) },
	)
	const seen = { status: response.status(), body: await response.text() }
	await request.dispose()
	return seen
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const user of [member, other]) {
		const created = await page.request.post(
			`${BASE}/ocs/v2.php/cloud/users?format=json`,
			{
				headers: basic(ADMIN_USER, ADMIN_PASS),
				data: { userid: user, password },
			},
		)
		expect(created.ok(), await created.text()).toBe(true)
	}
	for (const [key, user] of [
		['raad', member],
		['rekenkamer', other],
	]) {
		const body = await createObject(page, ledger, 'governance-body', {
			name: `${tag}-${key}`,
			bodyType: 'legislative',
			domain: 'municipality',
		})
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
	for (const [number, isPublic, title] of [
		[6, true, `${tag} Verlichting fietspaden`],
		[7, false, closedTitle],
		[8, true, `${tag} Rondvraag`],
	] as const) {
		const item = await createObject(page, ledger, 'agenda-item', {
			title,
			itemType: 'decision',
			orderNumber: number,
			meeting: ids.meeting,
			public: isPublic,
		})
		ids[`item${number}`] = objId(item)
	}
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	for (const user of [member, other]) {
		await page.request
			.delete(
				`${BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(user)}?format=json`,
				{ headers: basic(ADMIN_USER, ADMIN_PASS) },
			)
			.catch(() => undefined)
	}
	await page.close()
})

// @e2e agenda-item-confidentiality::a-reader-who-may-not-read-the-item-sees-its-place
test('a reader outside the body is answered the number of the closed item and nothing else', async ({
	playwright,
}) => {
	const seen = await closedItems(playwright, other)
	expect(seen.status, seen.body).toBe(200)
	expect(JSON.parse(seen.body)).toEqual({
		closedItems: [{ orderNumber: 7, parentItem: null }],
	})
	expect(seen.body).not.toContain(ids.item7)
	expect(seen.body).not.toContain(closedTitle)
})

// @e2e agenda-item-confidentiality::a-reader-who-may-not-read-the-item-sees-its-place
test('a member of the body, who reads the item, is answered no placeholder', async ({
	playwright,
}) => {
	const seen = await closedItems(playwright, member)
	expect(seen.status, seen.body).toBe(200)
	expect(JSON.parse(seen.body)).toEqual({ closedItems: [] })
})
