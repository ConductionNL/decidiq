/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a motion is moved through its stages from its own page,
 * its submitter can withdraw it, and motions are tagged by theme and
 * filtered on it (change motions-stages-and-themes, matrix rows mot-08 and
 * mot-15).
 *
 * @spec openspec/specs/motion-status-management/spec.md
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

// @e2e motion-status-management::a-member-withdraws-her-motion
test('the submitter withdraws her motion from the motion page', async ({
	page,
}) => {
	const motion = await createObject(page, ledger, 'decision', {
		title: `${tag}-M-12`,
		decisionType: 'motion',
		proposer: 'Anna',
		lifecycle: 'proposed',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/motions/${idOf(motion)}`)
	await page.getByTestId('motion-stage-withdrawn').click()
	await expect(page.getByTestId('motion-stage')).toHaveText(/withdrawn/i)
})

// @e2e motion-status-management::filter-on-a-theme
test('the motions list filters on a theme', async ({ page }) => {
	await createObject(page, ledger, 'theme', { name: `${tag}-Housing` })
	await createObject(page, ledger, 'decision', {
		title: `${tag}-wonen`,
		decisionType: 'motion',
		proposer: 'Anna',
		themes: [`${tag}-Housing`],
	})
	await createObject(page, ledger, 'decision', {
		title: `${tag}-klimaat`,
		decisionType: 'motion',
		proposer: 'Anna',
		themes: ['Climate'],
	})

	await page.goto(
		`${BASE}/index.php/apps/decidiq/motions?themes=${encodeURIComponent(`${tag}-Housing`)}`,
	)
	await expect(page.getByText(`${tag}-wonen`)).toBeVisible()
	await expect(page.getByText(`${tag}-klimaat`)).toHaveCount(0)
})
