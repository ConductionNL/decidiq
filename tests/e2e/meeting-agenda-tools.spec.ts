/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the agenda tools on the meeting page and the per-item minutes
 * editor on the minutes page (change agenda-meeting-page-item-tools).
 *
 * The fixtures are one governance body, one meeting of that body, the admin as
 * its chair, three agenda items and two minutes records (one draft, one
 * approved). A second account is provisioned without a role for the member
 * scenarios, and given the secretary role for the role endpoint scenario.
 *
 * @spec openspec/specs/agenda-management/spec.md
 * @spec openspec/specs/resolution-minutes/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	listObjects,
	newLedger,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const memberUid = `dq-agenda-${ledger.runId}`.slice(0, 40)
const memberPass = `Dq-${randomBytes(12).toString('hex')}-Pw1!`

let meetingId = ''
let bodyId = ''
let itemIds: Record<string, string> = {}
let draftMinutesId = ''
let approvedMinutesId = ''

/**
 * Pull the id out of an OpenRegister object.
 *
 * @param o The object.
 */
function idOf(o: { id?: string; '@self'?: { id?: string } }): string {
	return o?.id ?? o?.['@self']?.id ?? ''
}

/**
 * Admin basic-auth header for the OCS user API.
 */
function adminAuth(): Record<string, string> {
	return {
		Authorization:
			'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * The agenda of the seeded meeting in stored order, as titles.
 *
 * @param page Playwright page.
 */
async function agendaTitles(page: Page): Promise<string[]> {
	const items = (await listObjects(page, 'agenda-item')).filter(
		(i) => i.meeting === meetingId,
	)
	return items
		.sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
		.map((i) => String(i.title).replace(`${tag}-`, ''))
}

/**
 * Open the meeting page and wait for the agenda widget with the chair tools.
 *
 * @param page Playwright page.
 */
async function openMeeting(page: Page): Promise<void> {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${meetingId}`)
	await expect(page.getByTestId('agenda-tab')).toBeVisible({ timeout: 20_000 })
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-body`,
		bodyType: 'legislative',
		domain: `${tag} council`,
	})
	bodyId = idOf(body)
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-meeting`,
		meetingType: 'regular',
		scheduledDate: '2026-10-14T19:30:00Z',
		meetingMode: 'in-person',
		lifecycle: 'scheduled',
		governanceBody: bodyId,
	})
	meetingId = idOf(meeting)
	await createObject(page, ledger, 'participant', {
		displayName: `${tag}-chair`,
		role: 'chair',
		governanceBody: bodyId,
		nextcloudUserId: ADMIN_USER,
	})
	const titles = ['Opening', 'Minutes', 'Budget']
	itemIds = {}
	for (const [i, title] of titles.entries()) {
		const item = await createObject(page, ledger, 'agenda-item', {
			title: `${tag}-${title}`,
			itemType: 'discussion',
			orderNumber: i + 1,
			meeting: meetingId,
		})
		itemIds[title] = idOf(item)
	}
	draftMinutesId = idOf(
		await createObject(page, ledger, 'minutes', {
			title: `${tag}-draft-minutes`,
			lifecycle: 'draft',
			meeting: meetingId,
		}),
	)
	approvedMinutesId = idOf(
		await createObject(page, ledger, 'minutes', {
			title: `${tag}-approved-minutes`,
			lifecycle: 'approved',
			meeting: meetingId,
		}),
	)
	const resp = await page.request.post(
		`${BASE}/ocs/v2.php/cloud/users?format=json`,
		{ headers: adminAuth(), data: { userid: memberUid, password: memberPass } },
	)
	expect(resp.ok(), `provisioning ${memberUid}: ${await resp.text()}`).toBe(true)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.request
		.delete(`${BASE}/ocs/v2.php/cloud/users/${memberUid}?format=json`, {
			headers: adminAuth(),
		})
		.catch(() => undefined)
	await page.close()
})

// @e2e agenda-management::the-chair-drags-an-item-to-the-top
test('the chair drags Budget above Opening and the order is saved', async ({
	page,
}) => {
	await openMeeting(page)
	const handle = page.getByTestId(`agenda-drag-${itemIds.Budget}`)
	await expect(handle).toBeVisible()
	await handle.dragTo(page.getByTestId(`agenda-drag-${itemIds.Opening}`))
	await expect
		.poll(() => agendaTitles(page))
		.toEqual(['Budget', 'Opening', 'Minutes'])
})

// @e2e agenda-management::a-secretary-moves-an-item-with-the-keyboard
test('Move up puts Minutes above Opening and saves the order', async ({ page }) => {
	await openMeeting(page)
	const row = page.locator('tr', { hasText: `${tag}-Minutes` })
	await row.getByTestId('cn-row-actions').getByRole('button').first().click()
	await page.getByTestId('cn-action-item-move-up').click()
	await expect
		.poll(() => agendaTitles(page))
		.toEqual(['Budget', 'Minutes', 'Opening'])
})

// @e2e agenda-management::a-clerk-attaches-a-paper-to-one-item
test('Open on an agenda row opens the agenda item page', async ({ page }) => {
	await openMeeting(page)
	const row = page.locator('tr', { hasText: `${tag}-Opening` })
	await row.getByTestId('cn-row-actions').getByRole('button').first().click()
	await page.getByTestId('cn-action-item-open').click()
	await expect(page).toHaveURL(new RegExp(`/agenda-items/${itemIds.Opening}`))
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e agenda-management::the-chair-starts-running-the-meeting
test('the chair opens the live meeting screen from the meeting page', async ({
	page,
}) => {
	await openMeeting(page)
	await page.getByTestId('agenda-open-live').click()
	await expect(page).toHaveURL(new RegExp(`/meetings/${meetingId}/live`))
})

// @e2e agenda-management::a-member-cannot-reorder
// @e2e agenda-management::a-member-does-not-see-the-button
test('a member without a presiding role sees no reorder tools and no live button', async ({
	browser,
}) => {
	const context = await browser.newContext({
		httpCredentials: {
			username: memberUid,
			password: memberPass,
			send: 'always',
		},
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	const roles = await page.request.get(
		`${BASE}/index.php/apps/decidiq/api/meetings/${meetingId}/my-roles`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(await roles.json()).toEqual({
		chair: false,
		secretary: false,
		admin: false,
	})
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${meetingId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 20_000 })
	await expect(page.getByTestId('agenda-open-live')).toHaveCount(0)
	await expect(page.locator('.decidiq-tab__drag-handle')).toHaveCount(0)
	await context.close()
})

// @e2e agenda-management::a-secretary-is-recognised-on-the-meeting-page
// @e2e agenda-management::nobody-is-answered-for-someone-else
test('the role endpoint recognises a secretary and refuses anonymous callers', async ({
	browser,
	playwright,
}) => {
	const page = await browser.newPage()
	await createObject(page, ledger, 'participant', {
		displayName: `${tag}-secretary`,
		role: 'secretary',
		governanceBody: bodyId,
		nextcloudUserId: memberUid,
	})
	await page.close()
	const member = await playwright.request.newContext({
		httpCredentials: {
			username: memberUid,
			password: memberPass,
			send: 'always',
		},
		storageState: { cookies: [], origins: [] },
	})
	const resp = await member.get(
		`${BASE}/index.php/apps/decidiq/api/meetings/${meetingId}/my-roles`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.status()).toBe(200)
	expect(await resp.json()).toEqual({
		chair: false,
		secretary: true,
		admin: false,
	})
	await member.dispose()

	const anonymous = await playwright.request.newContext({
		storageState: { cookies: [], origins: [] },
	})
	const anon = await anonymous.get(
		`${BASE}/index.php/apps/decidiq/api/meetings/${meetingId}/my-roles`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(anon.status()).toBe(401)
	await anonymous.dispose()
})

// @e2e resolution-minutes::the-secretary-writes-the-minutes-after-the-meeting
test('a note typed on the minutes page is saved per agenda item', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/minutes/${draftMinutesId}`)
	const panel = page.getByTestId(`minutes-panel-item-${itemIds.Budget}`)
	await expect(panel).toBeVisible({ timeout: 20_000 })
	await panel.locator('textarea').first().fill('Adopted without a vote')
	await expect(page.getByTestId('minutes-panel-save-state')).toHaveText(
		/All changes saved/,
		{ timeout: 10_000 },
	)
	await page.reload()
	await expect(
		page
			.getByTestId(`minutes-panel-item-${itemIds.Budget}`)
			.locator('textarea')
			.first(),
	).toHaveValue('Adopted without a vote', { timeout: 20_000 })
})

// @e2e resolution-minutes::approved-minutes-cannot-be-changed-here
test('approved minutes show the per-item notes read-only', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/minutes/${approvedMinutesId}`)
	const panel = page.getByTestId(`minutes-panel-item-${itemIds.Budget}`)
	await expect(panel).toBeVisible({ timeout: 20_000 })
	await expect(panel.locator('textarea').first()).toBeDisabled()
})
