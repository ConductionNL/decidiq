/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the chair's current agenda item is shared with every live
 * screen and the room screen (the live page with ?view=screen),
 * and a decision is recorded on it (change
 * live-meeting-shared-current-item, matrix rows liv-01 liv-04 liv-05 liv-11).
 *
 * @spec openspec/specs/agenda-live-management/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e agenda-live-management::members-follow-the-chair
// @e2e agenda-live-management::the-room-sees-the-vote
// @e2e agenda-live-management::the-secretary-records-the-decision
test('the chair makes an item current, the room screen follows and a decision is recorded', async ({
	page,
}) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		lifecycle: 'opened',
		scheduledDate: '2026-10-14T19:30:00Z',
	})
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-woningbouwplan`,
		meeting: idOf(meeting),
		orderNumber: 5,
		itemType: 'decision',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/live`)
	await page
		.getByRole('button', { name: new RegExp(`Activate ${tag}-woningbouwplan`) })
		.click()

	await page.goto(
		`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/live?view=screen`,
	)
	await expect(page.getByTestId('meeting-screen-item')).toContainText(
		`${tag}-woningbouwplan`,
	)

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/live`)
	await page.getByTestId('meeting-live-record-decision').click()
	await page
		.getByTestId('live-decision-text')
		.locator('textarea')
		.fill('De raad stemt in.')
	await page.getByTestId('live-decision-submit').click()
	await expect(page.getByText('Decision recorded.')).toBeVisible()
	expect(idOf(item)).toBeTruthy()
})

// @e2e agenda-live-management::who-spoke-on-which-item
test('a question raised on the current item is listed under that item', async ({
	page,
}) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-commissie`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		lifecycle: 'opened',
		scheduledDate: '2026-10-14T19:30:00Z',
	})
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-begroting`,
		meeting: idOf(meeting),
		orderNumber: 5,
		itemType: 'decision',
	})
	const pieter = await createObject(page, ledger, 'participant', {
		displayName: `${tag}-Pieter`,
		role: 'member',
	})
	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	const headers = {
		...(await writeHeaders(page)),
		'Content-Type': 'application/json',
	}
	const current = await page.request.put(
		`${BASE}/index.php/apps/decidiq/api/agendas/${idOf(meeting)}/current-item`,
		{ headers, data: { agendaItem: idOf(item) } },
	)
	expect(current.ok()).toBeTruthy()
	const logged = await page.request.post(
		`${BASE}/index.php/apps/decidiq/api/engagement`,
		{
			headers,
			data: {
				meeting: idOf(meeting),
				participant: idOf(pieter),
				eventType: 'question',
				eventData: { agendaItem: idOf(item) },
			},
		},
	)
	expect(logged.ok()).toBeTruthy()

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/live`)
	await expect(page.getByTestId('speaker-queue-contributions')).toContainText(
		'raised a question',
	)
})
