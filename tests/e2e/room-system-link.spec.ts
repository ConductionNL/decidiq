// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: the Room system widget on a meeting page
 * (live-room-conference-system-link, REQ-RCSL-001 and REQ-RCSL-006).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * `RoomSystemControllerTest`, `RoomSystemMessageServiceTest` and the vitest
 * `meetingRoomSystemTab.spec.js` pin the guard, the ordering and the widget's
 * text against doubles. Only a real instance shows that MeetingDetail mounts
 * the widget, that it reads the instance's real connection state, and that
 * "Apply again" on a rejected message reaches the controller and comes back
 * applied once the seat is linked.
 *
 * WITH AND WITHOUT A LINKED ROOM SYSTEM. The no-room-system scenario runs when
 * integriq links no source to the `room-system` connection; the apply-again
 * scenario needs a linked source, since decidiq applies nothing while none is
 * linked. Each skips with the reason when the instance is the other way.
 *
 * The two authorization scenarios (a member creating a message, a member
 * calling apply) carry `@e2e exclude` in the spec: they are covered by
 * PHPUnit and Newman.
 *
 * WHAT IT CREATES AND REMOVES. One governance body, a meeting, a person and
 * participant, a voting round and a room system message, all removed in
 * afterAll.
 *
 * Written, not yet run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-001-the-room-system-is-an-integriq-connection
 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
 */

import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { cleanupAll, createObject, getObject, newLedger, objId, writeHeaders } from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const APP = `${BASE}/index.php/apps/decidiq`
const SEAT = `27-${ledger.runId}`.slice(0, 20)

let meetingId = ''
let personId = ''
let participantId = ''
let roundId = ''
let connected = false

/**
 * Open the meeting page and bring the Room system widget into view.
 *
 * @param page The page.
 * @return The widget.
 */
async function openRoomSystem(page: Page): Promise<Locator> {
	await page.goto(`${APP}/meetings/${meetingId}`)
	const widget = page.getByTestId('meeting-room-system')
	if (!(await widget.isVisible().catch(() => false))) {
		// The simple structure keeps it under More.
		const more = page.getByRole('tab', { name: 'More' })
		if (await more.isVisible().catch(() => false)) {
			await more.click()
		}

		const tab = page.getByRole('tab', { name: 'Room system' })
		if (await tab.isVisible().catch(() => false)) {
			await tab.click()
		}
	}

	await expect(widget).toBeVisible()
	return widget
}

test.describe('The Room system widget on a meeting page', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		const body = await createObject(page, ledger, 'governance-body', { name: `${tag}-room-body`, bodyType: 'legislative' })
		meetingId = objId(await createObject(page, ledger, 'meeting', {
			title: `${tag} Raadsvergadering zaal`,
			meetingType: 'regular',
			meetingMode: 'in-person',
			scheduledDate: '2026-12-09T19:30:00Z',
			governanceBody: objId(body),
			lifecycle: 'scheduled',
		}))
		personId = objId(await createObject(page, ledger, 'person', { name: `${tag} P. Jansen` }))
		participantId = objId(await createObject(page, ledger, 'participant', {
			meeting: meetingId,
			person: personId,
			displayName: `${tag} P. Jansen`,
			role: 'member',
		}))
		roundId = objId(await createObject(page, ledger, 'voting-round', {
			meeting: meetingId,
			title: `${tag} Stemming motie`,
			method: 'roll-call',
			lifecycle: 'open',
		}))

		const state = await page.request.get(`${APP}/api/meetings/${meetingId}/room-system`)
		expect(state.status(), await state.text()).toBe(200)
		connected = (await state.json()).connected === true
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupAll(page, ledger)
		await page.close()
	})

	test('without a linked room system the widget says so', async ({ page }) => {
		test.skip(connected, 'a room system is linked on this instance')

		const widget = await openRoomSystem(page)
		await expect(widget.getByTestId('meeting-room-system-not-connected')).toHaveText('No room system is connected')
		await expect(widget.getByTestId('meeting-room-system-apply')).toHaveCount(0)
	})

	test('the clerk links an unlinked seat and applies the rejected vote again', async ({ page }) => {
		test.skip(!connected, 'no room system is linked on this instance, and decidiq applies nothing without one')

		const message = await createObject(page, ledger, 'room-system-message', {
			meeting: meetingId,
			kind: 'vote',
			seat: SEAT,
			votingRound: roundId,
			value: 'for',
			externalId: `${tag}-vote-1`,
		})
		const messageId = objId(message)
		await expect.poll(async () => (await getObject(page, 'room-system-message', messageId))?.status).toBe('rejected')

		const widget = await openRoomSystem(page)
		const row = widget.getByTestId('meeting-room-system-message').filter({ hasText: SEAT })
		await expect(row).toContainText('rejected')

		const linked = await page.request.patch(`${BASE}/index.php/apps/openregister/api/objects/decidiq/participant/${participantId}`, {
			headers: await writeHeaders(page),
			data: { roomSeat: SEAT },
		})
		expect(linked.status(), await linked.text()).toBeLessThan(300)

		await row.getByTestId('meeting-room-system-apply').click()
		await expect(widget).toContainText('The message is applied.')
		const applied = await getObject(page, 'room-system-message', messageId)
		expect(applied?.status).toBe('applied')
		expect(applied?.participant).toBe(participantId)
	})
})
