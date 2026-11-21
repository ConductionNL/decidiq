/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: agenda change notices reach members (change
 * agenda-change-notices-reach-members). A member of a meeting whose agenda
 * is published gets a bell notice when the clerk adds an item, rendered by
 * decidiq's notifier and linking the meeting.
 *
 * @spec openspec/specs/decidesk-notifications/spec.md
 */
import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const member = `${tag}-pieter`.slice(0, 60)
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
let meetingId = ''

/**
 * Basic-auth headers.
 *
 * @param user The user.
 * @param pass The password.
 */
function basic(user: string, pass: string): Record<string, string> {
	return {
		Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	const created = await page.request.post(
		`${BASE}/ocs/v2.php/cloud/users?format=json`,
		{
			headers: basic(ADMIN_USER, ADMIN_PASS),
			data: { userid: member, password },
		},
	)
	expect(created.ok(), await created.text()).toBe(true)
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-body`,
		bodyType: 'legislative',
	})
	const bodyId = body.id ?? body['@self']?.id
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag} Raadsvergadering 14 oktober`,
		meetingType: 'regular',
		scheduledDate: '2026-10-14T19:30:00Z',
		governanceBody: bodyId,
	})
	meetingId = meeting.id ?? meeting['@self']?.id
	await createObject(page, ledger, 'participant', {
		displayName: member,
		role: 'member',
		nextcloudUserId: member,
		governanceBody: bodyId,
	})
	await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-Opening`,
		itemType: 'informational',
		orderNumber: 1,
		meeting: meetingId,
	})
	const headers = await writeHeaders(page)
	const published = await page.request.post(
		`${BASE}/index.php/apps/decidiq/api/agendas/${meetingId}/publish`,
		{ headers },
	)
	expect(published.status(), await published.text()).toBeLessThan(300)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.request
		.delete(`${BASE}/ocs/v2.php/cloud/users/${member}?format=json`, {
			headers: basic(ADMIN_USER, ADMIN_PASS),
		})
		.catch(() => undefined)
	await page.close()
})

// @e2e decidesk-notifications::a-member-sees-that-the-agenda-changed
test('a member sees in the bell that the agenda changed, linking the meeting', async ({
	page,
	playwright,
}) => {
	await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-Motie vreemd aan de orde`,
		itemType: 'discussion',
		orderNumber: 2,
		meeting: meetingId,
	})
	const pieter = await playwright.request.newContext({
		extraHTTPHeaders: basic(member, password),
		storageState: { cookies: [], origins: [] },
	})
	await expect
		.poll(
			async () => {
				const resp = await pieter.get(
					`${BASE}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`,
				)
				const list = (await resp.json())?.ocs?.data ?? []
				return list.find((n: { app: string }) => n.app === 'decidiq') ?? null
			},
			{ timeout: 30_000 },
		)
		.toMatchObject({
			subject: expect.stringContaining('changed'),
			link: expect.stringContaining(`/meetings/${meetingId}`),
		})
	await pieter.dispose()
})
