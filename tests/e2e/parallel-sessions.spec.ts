/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: an evening split into parallel sessions (change
 * planning-parallel-sessions, matrix row pla-17). The save rules (no nesting,
 * inside the evening's times, the evening's defaults) are covered by
 * ParallelSessionListenerTest; the grouping and the columns by
 * meetingCalendarSessions.spec.js and meetingSessionsTab.spec.js.
 *
 * Needs the municipality example set: "Commissieavond 3 november" (18:00 to
 * 23:00) with the sessions Commissie Ruimte, Commissie Bestuur and Commissie
 * Samenleving. Runs as the administrator.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

const EVENING = 'Commissieavond 3 november'
const SESSIONS = ['Commissie Ruimte', 'Commissie Bestuur', 'Commissie Samenleving']

/**
 * Open a seeded meeting and wait for the Sessions widget.
 *
 * @param page The page.
 * @param title The meeting title.
 * @return The widget.
 */
async function openMeeting(page, title: string) {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings`)
	await page.getByText(title, { exact: true }).first().click()
	const widget = page.getByTestId('meeting-sessions')
	await expect(widget).toBeVisible()
	return widget
}

// @e2e meeting-management::the-griffier-splits-a-committee-evening
// @e2e meeting-management::one-overview-of-the-evening
test('the evening shows its three sessions side by side, and a column opens the session', async ({
	page,
}) => {
	const widget = await openMeeting(page, EVENING)
	const columns = widget.locator('.sessions__column')
	await expect(columns).toHaveCount(3)
	for (const title of SESSIONS) {
		await expect(widget.getByRole('link', { name: title })).toBeVisible()
	}

	await widget.getByRole('link', { name: 'Commissie Bestuur' }).click()
	const session = page.getByTestId('meeting-sessions')
	await expect(session.getByTestId('meeting-sessions-evening-link')).toHaveText(
		EVENING,
	)
	await expect(
		session.getByRole('link', { name: /Commissie Ruimte/ }),
	).toBeVisible()
	await expect(
		session.getByRole('link', { name: /Commissie Samenleving/ }),
	).toBeVisible()
})

// @e2e meeting-management::a-session-outside-the-evening
test("a session at 23:30 is refused with the evening's times", async ({ page }) => {
	const widget = await openMeeting(page, EVENING)
	const form = widget.getByTestId('meeting-sessions-add')
	await form.getByLabel('Session title').fill('Commissie Laat')
	await form.getByLabel('Start time').fill('23:30')
	await form.getByRole('button', { name: 'Add session' }).click()

	await expect(widget.getByRole('alert')).toContainText('18:00 to 23:00')
	await expect(widget.locator('.sessions__column')).toHaveCount(3)
})

// @e2e meeting-management::the-calendar-on-3-november
test('the calendar shows the evening once with its sessions inside it', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/calendar`)
	const calendar = page.getByTestId('meeting-calendar')
	await expect(calendar).toBeVisible()
	const heading = calendar.getByTestId('meeting-calendar-title')
	for (let step = 0; step < 36; step++) {
		const title = (await heading.innerText()).toLowerCase()
		if (title.includes('2026') && title.includes('nov')) break
		const later =
			/2027|2028|2029/.test(title)
			|| (title.includes('2026') && title.includes('dec'))
		await calendar
			.getByTestId(later ? 'meeting-calendar-prev' : 'meeting-calendar-next')
			.click()
	}

	await expect(calendar.getByRole('button', { name: EVENING })).toHaveCount(1)
	const sessions = calendar.getByRole('list', { name: `Sessions of ${EVENING}` })
	await expect(sessions.getByRole('button')).toHaveCount(3)
})

// @e2e meeting-management::a-resident-looks-for-tonights-committees
test("residents read each session's broadcast with its evening's title", async ({
	page,
}) => {
	const response = await page.request.get(
		`${BASE}/index.php/apps/portaliq/api/contributions`,
		{ headers: { 'OCS-APIREQUEST': 'true' } },
	)
	test.skip(!response.ok(), 'portaliq is not installed on this instance')
	const body = JSON.stringify(await response.json())
	expect(body).toContain('publicBroadcasts')
	expect(body).toContain('eveningTitle')
})
