/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a new meeting takes its type's defaults, and a voting
 * round follows its body's rules (change meeting-rules-from-body-and-type,
 * matrix rows pla-11 and bod-14).
 *
 * @spec openspec/specs/meeting-management/spec.md
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

// @e2e meeting-management::the-clerk-plans-a-committee-meeting
test('a committee meeting takes 90 minutes and quorum 5 from its type', async ({
	page,
}) => {
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-commissie`,
	})
	const type = await createObject(page, ledger, 'meeting-type', {
		name: `${tag}-Commissie`,
		governanceBody: idOf(body),
		defaultQuorum: 5,
		defaultDurationMinutes: 90,
		isDraft: false,
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-commissievergadering`,
		meetingType: 'committee',
		meetingMode: 'in-person',
		type: idOf(type),
		scheduledDate: '2026-10-06T17:30:00Z',
	})

	expect(meeting.quorumRequired).toBe(5)
	expect(new Date(meeting.endDate).toISOString()).toBe('2026-10-06T19:00:00.000Z')
})

// @e2e meeting-management::a-two-thirds-vote
test('a round on a two-thirds board carries the two-thirds rule', async ({
	page,
}) => {
	const template = await createObject(page, ledger, 'process-template', {
		name: `${tag}-twee-derde`,
		slug: `${tag}-twee-derde`,
		votingRule: { voteThreshold: 'qualified-majority-two-thirds' },
	})
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-rvc`,
		processTemplate: idOf(template),
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-rvc-vergadering`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		governanceBody: idOf(body),
		scheduledDate: new Date().toISOString(),
	})
	const motion = await createObject(page, ledger, 'decision', {
		title: `${tag}-besluit`,
		decisionType: 'motion',
		meeting: idOf(meeting),
	})

	const opened = await page.request.post(
		`${BASE}/index.php/apps/decidiq/api/voting-rounds`,
		{
			headers: await writeHeaders(page),
			data: {
				motionId: idOf(motion),
				meetingId: idOf(meeting),
				votingMethod: 'for-against-abstain',
			},
		},
	)
	expect(opened.ok()).toBe(true)
	expect((await opened.json()).voteThreshold).toBe('qualified-majority-two-thirds')
})
