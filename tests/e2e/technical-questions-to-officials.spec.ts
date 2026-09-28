/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: technical questions assigned to an official (change
 * motions-technical-questions-to-officials, matrix row mot-17). The meeting
 * page lists the meeting's technical questions with their official, deadline
 * and status; answering one turns it from overdue to answered.
 *
 * @spec openspec/specs/motion-management/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e motion-management::the-official-answers-in-time
test('an assigned question shows as overdue, and as answered once answered', async ({ page }) => {
	const type = await createObject(page, ledger, 'agenda-item-type', {
		name: `${tag}-technische-vraag`,
		fields: [
			{ key: 'question', label: 'Vraag', fieldType: 'text', required: true },
			{ key: 'answer', label: 'Antwoord', fieldType: 'text' },
			{ key: 'assignedTo', label: 'Ambtenaar', fieldType: 'user' },
			{ key: 'answerDeadline', label: 'Antwoord uiterlijk', fieldType: 'date' },
		],
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-meeting`,
		meetingType: 'regular',
		scheduledDate: new Date(Date.now() + 7 * 86_400_000).toISOString(),
	})
	const meetingId = meeting.id ?? meeting['@self']?.id
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-question`,
		meeting: meetingId,
		type: type.id ?? type['@self']?.id,
		typeFields: { question: `${tag} klopt de planning?`, assignedTo: 'admin', answerDeadline: '2020-01-01' },
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${meetingId}`)
	const row = page.getByTestId('technical-question-row').filter({ hasText: `${tag} klopt de planning?` })
	await expect(row).toHaveAttribute('data-status', 'overdue', { timeout: 20_000 })

	const headers = await writeHeaders(page)
	const itemId = item.id ?? item['@self']?.id
	const saved = await page.request.put(`${OR}/agenda-item/${itemId}`, {
		headers,
		data: { ...item, typeFields: { ...item.typeFields, answer: 'Ja, de planning klopt.' } },
	})
	expect(saved.ok()).toBe(true)

	await page.reload()
	await expect(row).toHaveAttribute('data-status', 'answered', { timeout: 20_000 })
})
