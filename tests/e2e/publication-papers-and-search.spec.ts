/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: publishing an agenda leaves out every item under an imposed
 * or ratified confidentiality restriction, keeps an item whose restriction was
 * dissolved, and names the document type, body and date citizens filter on
 * (change publication-papers-and-search, matrix rows pub-01 pub-09).
 *
 * @spec openspec/specs/agenda-publication/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	getObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id
const PUBLISH = `${BASE}/index.php/apps/decidiq/api/publications`

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e agenda-publication::a-resident-reads-the-papers
// @e2e agenda-publication::a-confidential-item-stays-out
// @e2e agenda-publication::a-dissolved-restriction-no-longer-keeps-the-item-out
test('a published agenda leaves out the confidential items and names what citizens filter on', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	const council = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
		bodyType: 'legislative',
		domain: 'municipality',
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad 14 oktober`,
		meetingType: 'regular',
		scheduledDate: '2026-10-14T19:30:00+02:00',
		governanceBody: idOf(council),
		isPublic: true,
	})
	const titles = ['Housing plan', 'Land purchase Noordkade', 'Budget amendment']
	const items = []
	for (const [i, title] of titles.entries()) {
		items.push(
			await createObject(page, ledger, 'agenda-item', {
				title: `${tag} ${title}`,
				orderNumber: i + 1,
				meeting: idOf(meeting),
			}),
		)
	}
	await createObject(page, ledger, 'confidentiality-restriction', {
		scope: 'item',
		targetAgendaItem: idOf(items[1]),
		ground: 'Financial interest of the municipality',
		lifecycle: 'imposed',
	})
	await createObject(page, ledger, 'confidentiality-restriction', {
		scope: 'item',
		targetAgendaItem: idOf(items[2]),
		ground: 'Financial interest of the municipality',
		lifecycle: 'dissolved',
	})

	const response = await page.request.post(PUBLISH, {
		headers: await writeHeaders(page),
		data: { sourceType: 'agenda', sourceId: idOf(meeting) },
	})
	expect(response.status()).toBe(201)
	const { record } = await response.json()

	const payload = await getObject(
		page,
		'publication-payload',
		record.payloadObject,
	)
	expect(payload.documentType).toBe('agenda')
	expect(payload.meetingDate).toBeTruthy()
	expect(payload.agendaItems.map((i: any) => i.title)).toEqual([
		`${tag} Housing plan`,
		`${tag} Budget amendment`,
	])
	expect(JSON.stringify(payload)).not.toContain('Noordkade')
	expect(JSON.stringify(record)).not.toContain('Noordkade')
})
