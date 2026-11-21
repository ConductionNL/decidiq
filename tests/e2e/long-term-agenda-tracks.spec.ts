
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the griffier splits a long-term agenda topic into tracks,
 * lists topics only, and filters the list by portfolio holder and by author
 * (change planning-long-term-agenda-tracks-and-report, tasks 3 and 4, matrix
 * rows pla-18 and pla-19).
 *
 * The topic, its tracks and the people are seeded per run through the
 * OpenRegister object API with the `e2e-<runId>` marker and removed in
 * afterAll, mirroring the municipality profile's "Energietransitie" seed. The
 * one-level guard, the denied-row report contract and the HTML fallback are
 * PHPUnit's (the spec excludes them from e2e).
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id
const INDEX = `${BASE}/index.php/apps/decidiq/planned-agenda`

let bodyId = ''
let topicId = ''

/**
 * A planned agenda item of the run's body with the schema's required fields.
 *
 * @param subject The item's subject, prefixed with the run marker.
 * @param plannedPeriod The period it is planned for.
 * @param extra Further fields (parentItem, owner, author, lifecycle).
 * @return the request body
 */
function item(
	subject: string,
	plannedPeriod: string,
	extra: Record<string, unknown> = {},
): Record<string, unknown> {
	return {
		subject: `${tag}-${subject}`,
		governanceBody: bodyId,
		plannedPeriod,
		expectedType: 'council-proposal',
		ownerType: 'portfolio-holder',
		lifecycle: 'planned',
		...extra,
	}
}

/**
 * Narrow the long-term agenda to this run's rows through the search box, so
 * other items on the instance never change what a filter shows.
 *
 * @param page The page, on the long-term agenda index.
 */
async function searchRun(page: Page): Promise<void> {
	await page.getByRole('searchbox').first().fill(tag)
	await page.keyboard.press('Enter')
}

/**
 * Pick one value under a facet of the index sidebar.
 *
 * @param page The page, on the long-term agenda index.
 * @param facet The facet's label.
 * @param value The option to pick.
 */
async function pickFacet(page: Page, facet: string, value: string): Promise<void> {
	await page.getByRole('combobox', { name: facet }).click()
	await page.getByRole('option', { name: value }).click()
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-gemeenteraad`,
		bodyType: 'council',
	})
	bodyId = idOf(body)
	const deBoer = await createObject(page, ledger, 'person', {
		name: `${tag}-De Boer`,
		familyName: 'de Boer',
	})
	const yilmaz = await createObject(page, ledger, 'person', {
		name: `${tag}-Yilmaz`,
		familyName: 'Yilmaz',
	})
	const visser = await createObject(page, ledger, 'person', {
		name: `${tag}-S. Visser`,
		familyName: 'Visser',
	})
	const other = await createObject(page, ledger, 'person', {
		name: `${tag}-A. Terpstra`,
		familyName: 'Terpstra',
	})
	const topic = await createObject(page, ledger, 'planned-agenda-item', item('Energietransitie', '2026-Q4', {
		owner: idOf(deBoer),
		author: idOf(visser),
	}))
	topicId = idOf(topic)
	await createObject(page, ledger, 'planned-agenda-item', item('Warmtevisie 2.0', '2026-Q4', {
		parentItem: topicId,
		owner: idOf(deBoer),
		author: idOf(visser),
	}))
	await createObject(page, ledger, 'planned-agenda-item', item('Regionale energiestrategie 2.0', '2027-Q1', {
		parentItem: topicId,
		owner: idOf(deBoer),
		author: idOf(other),
	}))
	await createObject(page, ledger, 'planned-agenda-item', item('Mobiliteitsplan 2027', '2027-Q2', {
		owner: idOf(yilmaz),
		author: idOf(other),
	}))
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e termijnagenda-register::the-clerk-splits-a-topic-into-tracks
test('the topic page lists its two tracks and adds a third in place', async ({ page }) => {
	await page.goto(`${INDEX}/${topicId}`)
	const tracks = page.locator('.cn-detail-page__catalog-card').filter({
		has: page.getByText('Tracks', { exact: true }),
	})
	await expect(tracks.getByRole('row', { name: new RegExp(`${tag}-Warmtevisie 2\\.0`) })).toContainText('2026-Q4')
	await expect(tracks.getByRole('row', { name: new RegExp(`${tag}-Regionale energiestrategie 2\\.0`) })).toContainText('2027-Q1')

	await tracks.getByRole('button', { name: /^Add/ }).first().click()
	const dialog = page.getByRole('dialog')
	await dialog.getByRole('textbox', { name: 'Subject' }).fill(`${tag}-Netcapaciteit`)
	await dialog.getByRole('textbox', { name: 'Planned period' }).fill('2027-Q2')
	await dialog.getByRole('button', { name: /^(Save|Create)$/ }).click()
	await expect(dialog).toBeHidden()
	await expect(tracks.getByRole('row', { name: new RegExp(`${tag}-Netcapaciteit`) })).toContainText('2027-Q2')
})

// @e2e termijnagenda-register::the-list-can-show-topics-only
test('the Topics only quick filter lists the topic without its tracks', async ({ page }) => {
	await page.goto(INDEX)
	await searchRun(page)
	await page.getByRole('button', { name: /Topics only/ }).first().click()
	await expect(page.getByRole('row', { name: new RegExp(`${tag}-Energietransitie`) })).toBeVisible()
	await expect(page.getByRole('row', { name: new RegExp(`${tag}-Warmtevisie`) })).toHaveCount(0)
	await expect(page.getByRole('row', { name: new RegExp(`${tag}-Regionale energiestrategie`) })).toHaveCount(0)
})

// @e2e termijnagenda-register::a-councillor-asks-for-everything-of-one-portfolio-holder
test('the Portfolio holder facet lists only De Boer\'s items, with her name', async ({ page }) => {
	await page.goto(INDEX)
	await searchRun(page)
	await pickFacet(page, 'Portfolio holder', `${tag}-De Boer`)
	const rows = page.getByRole('row').filter({ hasText: tag })
	await expect(rows).toHaveCount(3)
	for (const row of await rows.all()) {
		await expect(row).toContainText(`${tag}-De Boer`)
	}
	await expect(page.getByRole('row', { name: new RegExp(`${tag}-Mobiliteitsplan`) })).toHaveCount(0)
})

// @e2e termijnagenda-register::the-clerk-finds-what-one-official-is-drafting
test('the Author facet lists the two items S. Visser drafts', async ({ page }) => {
	await page.goto(INDEX)
	await searchRun(page)
	await pickFacet(page, 'Author', `${tag}-S. Visser`)
	const rows = page.getByRole('row').filter({ hasText: tag })
	await expect(rows).toHaveCount(2)
	await expect(page.getByRole('row', { name: new RegExp(`${tag}-Energietransitie`) })).toBeVisible()
	await expect(page.getByRole('row', { name: new RegExp(`${tag}-Warmtevisie 2\\.0`) })).toBeVisible()

	// The row opens the item it lists.
	await openRow(page, `${tag}-Warmtevisie 2.0`)
	await expect(page).toHaveURL(/\/planned-agenda\/[0-9a-f-]+$/)
})
