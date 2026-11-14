/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the meeting Transcription widget plays the recording and
 * jumps to the moment an agenda item started (change
 * live-recording-jump-to-item, matrix row liv-08).
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e meeting-transcription::a-member-replays-a-debate
test('Play from here puts the recording at the moment the item started', async ({
	page,
}) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: '2026-10-14T19:30:00Z',
	})
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-woningbouwplan`,
		meeting: idOf(meeting),
		orderNumber: 5,
	})
	await createObject(page, ledger, 'transcript', {
		title: `${tag}-transcript`,
		meeting: idOf(meeting),
		sourceType: 'uploaded-file',
		sourceFilePath: `Decidiq/${tag}/opname.mp3`,
		status: 'done',
		segments: [
			{
				startTime: 480,
				endTime: 600,
				speakerLabel: 'Speaker 1',
				text: 'Het woningbouwplan.',
				agendaItem: idOf(item),
			},
		],
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}`)
	await page.getByTestId('transcript-play-from-item').first().click()
	const position = await page
		.getByTestId('transcript-player')
		.evaluate((player: HTMLMediaElement) => player.currentTime)
	expect(position).toBe(480)
})
