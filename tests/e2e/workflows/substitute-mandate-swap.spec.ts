/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the Seats panel (SeatsPanel on the LiveMeeting page) swaps a
 * member for a substitute and ends the swap without a reload (change
 * bodies-substitute-mandate-swap, matrix row bod-18). The fixture builds the
 * design's audit committee as admin, who presides as an admin may. The
 * refusal for a member without a presiding role is covered by
 * tests/Unit/Service/MandateSubstitutionServiceTest.php
 * (testAMemberWithoutAPresidingRoleIsRefused), as this suite signs in as admin.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'
import { cleanupAll, createObject, newLedger } from './governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e meeting-attendees::a-committee-member-leaves-and-her-substitute-takes-the-seat
// @e2e meeting-attendees::mr-bos-returns
test('the secretary swaps seat 3 for the substitute and ends the swap', async ({
	page,
}) => {
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-Auditcommissie`,
		bodyType: 'committee',
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag} Auditcommissie 4 maart`,
		governanceBody: idOf(body),
		scheduledDate: '2026-03-04T19:30:00Z',
		lifecycle: 'opened',
	})
	await createObject(page, ledger, 'participant', {
		displayName: `${tag} Bos`,
		role: 'member',
		party: 'VVD',
		seatNumber: 3,
		governanceBody: idOf(body),
		votingWeight: 1,
	})
	await createObject(page, ledger, 'participant', {
		displayName: `${tag} Kaya`,
		role: 'member',
		party: 'D66',
		seatNumber: 4,
		governanceBody: idOf(body),
		votingWeight: 1,
	})
	await createObject(page, ledger, 'participant', {
		displayName: `${tag} De Wit`,
		role: 'observer',
		party: 'VVD',
		governanceBody: idOf(body),
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${idOf(meeting)}/live`)
	const panel = page.getByTestId('seats-panel')
	const seats = panel.getByTestId('seats-panel-seat')
	await expect(seats).toHaveCount(2, { timeout: 15_000 })
	await expect(seats.nth(0)).toContainText(`${tag} Bos`)

	await seats.nth(0).getByTestId('seats-panel-swap').click()
	const modal = page.getByTestId('mandate-swap-modal')
	await modal.getByTestId('mandate-swap-substitute').click()
	await page.getByRole('option', { name: new RegExp(`${tag} De Wit`) }).click()
	await modal
		.getByTestId('mandate-swap-reason')
		.locator('input')
		.fill('Mr Bos left for another appointment')
	await modal.getByTestId('mandate-swap-confirm').click()

	await expect(seats.nth(0)).toContainText(`${tag} De Wit`)
	await expect(
		seats.nth(0).getByTestId('seats-panel-substitute-for'),
	).toContainText(`${tag} Bos`)

	await seats.nth(0).getByTestId('seats-panel-end').click()
	await expect(seats.nth(0)).toContainText(`${tag} Bos`)
	await expect(seats.nth(0).getByTestId('seats-panel-substitute-for')).toHaveCount(
		0,
	)
})
