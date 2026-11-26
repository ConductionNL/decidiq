/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a non-public agenda item closes its papers, an imposed
 * restriction closes the item, and the meeting pack lists a closed item as
 * "Besloten punt" without its papers (change agenda-item-confidential-papers,
 * matrix row age-09).
 *
 * Runs as the suite's signed-in administrator (the secretariat), on rows it
 * creates and removes again. The read rules of REQ-ACP-002 (a member of the
 * body reads, a member of another body does not) run on real accounts in
 * confidential-agenda-item-readers.spec.ts.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	getObject,
	listObjects,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>): string => o?.id ?? o?.['@self']?.id ?? o?.uuid ?? ''

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

/**
 * Merge fields into an OpenRegister object.
 *
 * @param {Page} page The signed-in page.
 * @param {string} schema The schema slug.
 * @param {string} id The object.
 * @param {object} data The fields to change.
 * @return {Promise<void>}
 */
async function patchObject(page: Page, schema: string, id: string, data: Record<string, unknown>): Promise<void> {
	const res = await page.request.patch(`${OR}/${schema}/${id}`, { headers: await writeHeaders(page), data })
	expect(res.status(), await res.text()).toBeLessThan(300)
}

/**
 * A meeting with three agenda items numbered 6, 7 and 8.
 *
 * @param {Page} page The signed-in page.
 * @return {Promise<{meetingId: string, items: Record<number, string>}>} The ids.
 */
async function meetingWithItems(page: Page): Promise<{ meetingId: string; items: Record<number, string> }> {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		lifecycle: 'scheduled',
		scheduledDate: new Date().toISOString(),
	})
	const meetingId = idOf(meeting)
	const items: Record<number, string> = {}
	for (const n of [6, 7, 8]) {
		items[n] = idOf(
			await createObject(page, ledger, 'agenda-item', {
				title: `${tag}-item-${n}`,
				meeting: meetingId,
				orderNumber: n,
			}),
		)
	}
	return { meetingId, items }
}

// @e2e agenda-item-confidentiality::the-griffier-closes-an-item
test('closing an item closes every paper on it', async ({ page }) => {
	const { meetingId, items } = await meetingWithItems(page)
	const paper = await createObject(page, ledger, 'digital-document', {
		name: `${tag}-Kostenraming verlichting`,
		documentType: 'raadsvoorstel',
		meeting: meetingId,
		agendaItem: items[7],
	})
	expect((await getObject(page, 'digital-document', idOf(paper)))?.public ?? true).toBe(true)

	await patchObject(page, 'agenda-item', items[7], { public: false })

	await expect
		.poll(async () => (await getObject(page, 'digital-document', idOf(paper)))?.public, { timeout: 20_000 })
		.toBe(false)
	expect((await getObject(page, 'agenda-item', items[7]))?.public).toBe(false)
})

// @e2e agenda-item-confidentiality::a-paper-added-later
test('a paper added to a closed item is saved as not public', async ({ page }) => {
	const { meetingId, items } = await meetingWithItems(page)
	await patchObject(page, 'agenda-item', items[7], { public: false })

	const later = await createObject(page, ledger, 'digital-document', {
		name: `${tag}-Advies Fietsersbond`,
		documentType: 'advies',
		meeting: meetingId,
		agendaItem: items[7],
		public: true,
	})

	expect((await getObject(page, 'digital-document', idOf(later)))?.public).toBe(false)
})

// @e2e agenda-item-confidentiality::geheimhouding-imposed-by-the-college
// @e2e agenda-item-confidentiality::lifting-leaves-the-choice-to-the-griffier
test('an imposed restriction closes the item, and lifting it does not reopen it', async ({ page }) => {
	const grounds = await listObjects(page, 'confidentiality-ground', 20)
	test.skip(grounds.length === 0, 'this instance has no confidentiality ground seeded')

	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-college`,
		bodyType: 'executive',
		domain: `${tag} college`,
	})
	const { items } = await meetingWithItems(page)
	expect((await getObject(page, 'agenda-item', items[8]))?.public ?? true).toBe(true)

	const restriction = await createObject(page, ledger, 'confidentiality-restriction', {
		scope: 'item',
		targetAgendaItem: items[8],
		ground: idOf(grounds[0]),
		imposedBy: 'body',
		imposedByBody: idOf(body),
		imposedAt: new Date().toISOString(),
		lifecycle: 'imposed',
	})

	await expect
		.poll(async () => (await getObject(page, 'agenda-item', items[8]))?.public, { timeout: 20_000 })
		.toBe(false)

	await patchObject(page, 'confidentiality-restriction', idOf(restriction), { lifecycle: 'dissolved' })
	expect((await getObject(page, 'agenda-item', items[8]))?.public).toBe(false)
})

// @e2e agenda-item-confidentiality::assembling-the-pack
test('the pack lists a closed item as "Besloten punt" without its papers', async ({ page }) => {
	const { meetingId, items } = await meetingWithItems(page)
	await patchObject(page, 'agenda-item', items[7], { public: false })

	const res = await page.request.post(`${BASE}/index.php/apps/decidiq/api/meetings/${meetingId}/package`, {
		headers: await writeHeaders(page),
	})
	expect(res.status(), await res.text()).toBe(200)
	const pack = await res.json()
	expect(pack.success).toBe(true)
	expect(pack.items).toBe(3)

	// The table of contents names items 6 and 8, and item 7 only by number.
	const user = (await (await page.request.get(`${BASE}/ocs/v2.php/cloud/user?format=json`, {
		headers: { 'OCS-APIRequest': 'true' },
	})).json())?.ocs?.data?.id ?? 'admin'
	const toc = await page.request.get(
		`${BASE}/remote.php/dav/files/${encodeURIComponent(user)}/${String(pack.path).replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')}/${encodeURIComponent('00 - Table of contents.md')}`,
	)
	expect(toc.status()).toBe(200)
	const text = await toc.text()
	expect(text).toContain(`${tag}-item-6`)
	expect(text).toContain(`${tag}-item-8`)
	expect(text).toContain('Besloten punt')
	expect(text).not.toContain(`${tag}-item-7`)
})
