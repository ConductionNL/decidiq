/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a member subscribes to the meeting calendar in the settings
 * dialog and stops it again, and joins a digital meeting in Microsoft Teams
 * from the live meeting page (change platform-microsoft-365-calendar-and-teams,
 * REQ-M365-003 and REQ-M365-005).
 *
 * Runs as a member the suite creates (no admin rights, an empty storage
 * state) and removes again. The join scenario reads the municipality
 * profile's seeded digital meeting "Commissie Ruimte (digitaal)" and skips
 * with the reason when the instance does not have it.
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md
 */
import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const run = `${Date.now()}-${randomBytes(3).toString('hex')}`
const member = `e2e-${run}-sanne`.slice(0, 60)
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
const SEEDED_DIGITAL_MEETING = 'Commissie Ruimte (digitaal)'

/**
 * Basic-auth headers.
 *
 * @param {string} user The user.
 * @param {string} pass The password.
 * @return {Record<string, string>} The headers.
 */
function basic(user: string, pass: string): Record<string, string> {
	return {
		Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * A page signed in as the member, in a context without the suite's admin state.
 *
 * @param {Browser} browser The browser.
 * @return {Promise<Page>} The page.
 */
async function signInAsMember(browser: Browser): Promise<Page> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto(`${BASE}/index.php/login`)
	await page.locator('input[name="user"]').fill(member)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	return page
}

test.beforeAll(async ({ playwright }) => {
	const admin = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	const created = await admin.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
		headers: basic(ADMIN_USER, ADMIN_PASS),
		data: { userid: member, password },
	})
	expect(created.ok(), await created.text()).toBe(true)
	await admin.dispose()
})

test.afterAll(async ({ playwright }) => {
	const admin = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	await admin
		.delete(`${BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(member)}?format=json`, {
			headers: basic(ADMIN_USER, ADMIN_PASS),
		})
		.catch(() => undefined)
	await admin.dispose()
})

// @e2e microsoft-365-work::a-member-subscribes-from-the-personal-settings
test('a member subscribes in Outlook, then stops the subscription', async ({ browser, playwright }) => {
	const page = await signInAsMember(browser)
	// The user settings dialog (no personal settings page since 11 Oct 2026).
	await page.goto(`${BASE}/index.php/apps/decidiq/decisions#user-settings`)
	const section = page.getByTestId('calendar-subscription-section')
	await section.waitFor({ state: 'visible', timeout: 20_000 })

	const rows = section.getByTestId('calendar-subscription-row')
	await expect(rows).toHaveCount(0)

	await section.getByTestId('calendar-subscription-add').click()
	await expect(rows).toHaveCount(1)
	const address = section.getByTestId('calendar-subscription-url').locator('input').first()
	await expect(address).toHaveValue(/^https?:\/\/\S+$/)
	await expect(rows.first()).toContainText(/Outlook/)
	const url = await address.inputValue()

	// The address works without a session: that is what Outlook fetches.
	const outlook = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	const feed = await outlook.get(url)
	expect(feed.status()).toBe(200)
	expect(await feed.text()).toContain('BEGIN:VCALENDAR')

	await rows.first().getByTestId('calendar-subscription-stop').click()
	await expect(rows).toHaveCount(0)
	expect((await outlook.get(url)).status()).toBe(404)
	await outlook.dispose()
	await page.context().close()
})

// @e2e microsoft-365-work::members-join-the-teams-meeting-from-decidiq
test('the live page of a digital meeting links to its Teams meeting in a new tab', async ({ browser, playwright }) => {
	const admin = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	const list = await admin.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/meeting?_limit=200&_search=${encodeURIComponent(SEEDED_DIGITAL_MEETING)}`,
		{ headers: basic(ADMIN_USER, ADMIN_PASS) },
	)
	const meetings: Array<Record<string, any>> = list.ok() ? ((await list.json()).results ?? []) : []
	await admin.dispose()
	const meeting = meetings.find((row) => row.title === SEEDED_DIGITAL_MEETING && row.teamsMeeting?.joinUrl)
	test.skip(!meeting, `the instance has no seeded "${SEEDED_DIGITAL_MEETING}" with a Teams meeting`)

	const id = meeting!.id ?? meeting!['@self']?.id ?? meeting!.uuid
	const page = await signInAsMember(browser)
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${id}/live`)
	const join = page.getByTestId('meeting-live-teams-join')
	await expect(join).toBeVisible({ timeout: 20_000 })
	await expect(join).toHaveAttribute('href', meeting!.teamsMeeting.joinUrl)
	await expect(join).toHaveAttribute('target', '_blank')
	await page.context().close()
})
