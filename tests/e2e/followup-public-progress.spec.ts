/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the clerk adds a progress entry on the commitment page, and
 * the public ORI commitments resource serves the commitment with its progress
 * and public fields only, from its publication date on (change
 * followup-public-progress, matrix row fol-06).
 *
 * @spec openspec/specs/ori-api/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { anonymousActor } from './support/api-actors.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id
const ORI = `${BASE}/index.php/apps/decidiq/api/ori/v1/commitments`

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e ori-api::the-clerk-records-progress
// @e2e ori-api::a-journalist-follows-a-promise
// @e2e ori-api::internal-fields-stay-internal
// @e2e ori-api::a-commitment-before-its-publication-date-is-not-public
test('the clerk records progress and the public reads it from the publication date', async ({
	page,
	playwright,
}) => {
	const council = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
		bodyType: 'legislative',
		domain: 'municipality',
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-raad`,
		meetingType: 'regular',
		scheduledDate: new Date().toISOString(),
		governanceBody: idOf(council),
	})
	const alderman = await createObject(page, ledger, 'person', {
		name: `${tag}-Van Dijk`,
	})
	const base = {
		madeBy: idOf(alderman),
		meeting: idOf(meeting),
		directedTo: idOf(council),
		lifecycle: 'in-execution',
		deadline: '2026-12-01',
	}
	const published = await createObject(page, ledger, 'governance-commitment', {
		...base,
		text: `${tag} housing report by 1 December`,
		publicationDate: new Date(Date.now() - 86400000).toISOString(),
	})
	const future = await createObject(page, ledger, 'governance-commitment', {
		...base,
		text: `${tag} not yet public`,
		publicationDate: new Date(Date.now() + 7 * 86400000).toISOString(),
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/commitments/${idOf(published)}`)
	const widget = page.getByTestId('commitment-progress-tab')
	await expect(widget).toBeVisible()
	await widget
		.getByTestId('commitment-progress-note')
		.locator('textarea')
		.fill('Draft report sent to the committee')
	await widget.getByTestId('commitment-progress-add').click()
	await expect(
		widget.getByTestId('commitment-progress-entry').first(),
	).toContainText('Draft report sent to the committee')

	const { ctx: anonymous } = await anonymousActor(playwright)
	const list = await (await anonymous.get(ORI)).json()
	const item = list.items.find((i: any) => i.id === idOf(published))
	expect(item.deadline).toBe('2026-12-01')
	expect(item.progress).toEqual([
		{ date: expect.any(String), note: 'Draft report sent to the committee' },
	])
	expect(item).not.toHaveProperty('madeBy')
	expect(item).not.toHaveProperty('meeting')
	expect(list.items.some((i: any) => i.id === idOf(future))).toBe(false)
	expect((await anonymous.get(`${ORI}/${idOf(future)}`)).status()).toBe(404)
	await anonymous.dispose()
})
