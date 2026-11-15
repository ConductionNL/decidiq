/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a body's Members widget shows past members with their
 * dates, keeps contact details, and a faction lists its members and its
 * workspace (change bodies-membership-terms-contacts-and-factions, matrix rows
 * bod-02 bod-06 bod-16).
 *
 * @spec openspec/specs/governance-bodies/spec.md
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

// @e2e governance-bodies::the-clerk-sees-who-left-the-council
// @e2e governance-bodies::the-clerk-adds-a-phone-number
// @e2e governance-bodies::a-faction-gets-its-workspace
test('past members, contact details and a faction on the body page', async ({
	page,
}) => {
	const council = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
		bodyType: 'legislative',
		domain: 'municipality',
	})
	const groen = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-Groen`,
		bodyType: 'faction',
		domain: 'municipality',
		parentBody: idOf(council),
	})
	const anna = await createObject(page, ledger, 'person', { name: `${tag}-Anna` })
	const pieter = await createObject(page, ledger, 'person', {
		name: `${tag}-Pieter`,
	})
	await createObject(page, ledger, 'membership', {
		person: idOf(anna),
		governanceBody: idOf(council),
		role: 'member',
		startDate: '2022-03-01T00:00:00Z',
		endDate: '2026-06-01T00:00:00Z',
	})
	await createObject(page, ledger, 'membership', {
		person: idOf(pieter),
		governanceBody: idOf(council),
		role: 'member',
		startDate: '2022-03-01T00:00:00Z',
		faction: idOf(groen),
	})

	await page.goto(
		`${BASE}/index.php/apps/decidiq/governance-bodies/${idOf(council)}`,
	)
	const members = page.getByTestId('body-members-tab')
	await expect(members).toContainText(`${tag}-Pieter`)
	await expect(members).not.toContainText(`${tag}-Anna`)
	await page.getByTestId('body-members-past').click()
	await expect(members).toContainText(`${tag}-Anna`)

	await page.getByTestId('body-members-past').click()
	await page
		.getByRole('row', { name: new RegExp(`${tag}-Pieter`) })
		.getByRole('button', { name: /actions/i })
		.click()
	await page.getByRole('menuitem', { name: 'Contact details' }).click()
	await page
		.getByTestId('contact-details-value')
		.locator('input')
		.fill('06 12345678')
	await page.getByTestId('contact-details-add').click()
	await page.getByTestId('contact-details-close').click()
	await page.reload()
	await expect(page.getByTestId('body-members-tab')).toContainText('06 12345678')

	await page.goto(
		`${BASE}/index.php/apps/decidiq/governance-bodies/${idOf(groen)}`,
	)
	await expect(page.getByTestId('body-members-tab')).toContainText(`${tag}-Pieter`)
	await expect(page.getByText('Workspace').first()).toBeVisible()
})
