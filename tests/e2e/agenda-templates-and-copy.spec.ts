/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the secretary starts an agenda from a template and copies
 * items from an earlier meeting (change agenda-templates-and-copy, matrix rows
 * age-04 and pla-14).
 *
 * @spec openspec/specs/agenda-builder/spec.md
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

// @e2e agenda-builder::the-secretary-uses-the-standard-agenda
test('a new agenda starts from the Raadsvergadering template', async ({ page }) => {
	const titles = [
		'Opening',
		'Agenda',
		'Vragenhalfuur',
		'Hamerstukken',
		'Bespreekstukken',
		'Sluiting',
	]
	await createObject(page, ledger, 'agenda-template', {
		name: `${tag}-Raadsvergadering`,
		items: titles.map((title) => ({ title, itemType: 'informational' })),
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-14-oktober`,
		scheduledDate: '2026-10-14T19:30:00Z',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}`)
	await page.getByTestId('agenda-copy-items').click()
	await page.getByTestId('agenda-copy-template').click()
	await page.getByRole('option', { name: `${tag}-Raadsvergadering` }).click()
	await page.getByTestId('agenda-copy-submit').click()
	await expect(page.getByTestId('agenda-copy-notice')).toContainText('6')
	for (const title of titles) {
		await expect(page.getByTestId('agenda-tab')).toContainText(title)
	}
})

// @e2e agenda-builder::an-item-carries-over
test('two items of an earlier meeting land after the last item', async ({
	page,
}) => {
	const earlier = await createObject(page, ledger, 'meeting', {
		title: `${tag}-7-oktober`,
		scheduledDate: '2026-10-07T19:30:00Z',
	})
	const a = await createObject(page, ledger, 'agenda-item', {
		meeting: idOf(earlier),
		title: `${tag}-Begroting`,
		itemType: 'decision',
		orderNumber: 1,
	})
	await createObject(page, ledger, 'agenda-item', {
		meeting: idOf(earlier),
		title: `${tag}-Overig`,
		itemType: 'informational',
		orderNumber: 2,
	})
	const b = await createObject(page, ledger, 'agenda-item', {
		meeting: idOf(earlier),
		title: `${tag}-Rondvraag`,
		itemType: 'informational',
		orderNumber: 3,
	})
	const later = await createObject(page, ledger, 'meeting', {
		title: `${tag}-21-oktober`,
		scheduledDate: '2026-10-21T19:30:00Z',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(later)}`)
	await page.getByTestId('agenda-copy-items').click()
	await page.getByTestId('agenda-copy-source-meeting').click()
	await page.getByTestId('agenda-copy-meeting').click()
	await page.getByRole('option', { name: `${tag}-7-oktober` }).click()
	await page.getByTestId(`agenda-copy-item-${idOf(a)}`).click()
	await page.getByTestId(`agenda-copy-item-${idOf(b)}`).click()
	await page.getByTestId('agenda-copy-submit').click()
	await expect(page.getByTestId('agenda-tab')).toContainText(`${tag}-Rondvraag`)
	await expect(page.getByTestId('agenda-tab')).not.toContainText(`${tag}-Overig`)
})
