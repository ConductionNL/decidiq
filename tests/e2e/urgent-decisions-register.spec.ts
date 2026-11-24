/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the urgent decisions register (change
 * urgent-decisions-register). The declaring body, the report action and the
 * report date are covered by PHPUnit (UrgencyDeclarationServiceTest,
 * UrgentReportServiceTest, RatificationPlacedReportListenerTest) and the
 * dialog payload by tests/vitest/urgentDecisionsRegister.spec.js; this file
 * walks the list and the report action on a live instance.
 *
 * Needs the municipality example set, which seeds five urgent decisions:
 * three awaiting ratification, two ratified. Runs as the administrator.
 *
 * Written, not yet run: it needs a live instance (the letter scenario
 * runs in the nightly), and the list scenarios
 * (REQ-022) wait on task 4, the list as the board draws it.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	adminActor,
	createObject,
	disposeActors,
	listObjects,
	ObjectLedger,
	readObject,
} from './support/api-actors.ts'
import { openRow } from './support/open-row.ts'

const LIST = `${BASE}/index.php/apps/decidiq/urgent-decisions`

// @e2e urgent-decision-procedure::counts-on-the-quick-filters
test('UrgentDecisions: the quick filters count all, waiting and ratified urgent decisions', async ({
	page,
}) => {
	await page.goto(LIST)
	await expect(
		page.getByRole('button', { name: /All urgent decisions\s*5/ }),
	).toBeVisible()
	await expect(
		page.getByRole('button', { name: /Awaiting ratification\s*3/ }),
	).toBeVisible()
	await expect(page.getByRole('button', { name: /Ratified\s*2/ })).toBeVisible()
})

// @e2e urgent-decision-procedure::filter-on-waiting
test('UrgentDecisions: filtering on waiting shows three rows, each with the pill Yes', async ({
	page,
}) => {
	await page.goto(LIST)
	await page.getByRole('button', { name: /Awaiting ratification/ }).click()
	const rows = page.locator('tbody tr')
	await expect(rows).toHaveCount(3)
	for (const row of await rows.all()) {
		await expect(row.getByText('Yes', { exact: true })).toBeVisible()
	}
	await expect(page.getByText(/3 of 5 urgent decisions/)).toBeVisible()
})

// @e2e urgent-decision-procedure::the-mayor-declares-an-emergency-ordinance
// @e2e urgent-decision-procedure::the-college-declares
test('UrgentDecisions: the line under the title names the office, else the body', async ({
	page,
}) => {
	await page.goto(LIST)
	const ordinance = page.locator('tr', {
		hasText: 'Noodverordening Havenfestival 2026',
	})
	await expect(ordinance.getByText(/Burgemeester/)).toBeVisible()
	const shelter = page.locator('tr', {
		hasText: 'Tijdelijke opvang in sporthal De Linde',
	})
	await expect(shelter.getByText(/College van B&W/)).toBeVisible()
})

// @e2e urgent-decision-procedure::reported-before-the-ratifying-meeting
test('DecisionDetail: marking an urgent decision as reported records the date once', async ({
	page,
}) => {
	await page.goto(LIST)
	await openRow(page, 'Tijdelijke opvang in sporthal De Linde')

	const banner = page.getByTestId('urgency-banner')
	await banner.getByTestId('urgency-report').click()
	await expect(banner.getByTestId('urgency-reported')).toBeVisible()
	await expect(banner.getByTestId('urgency-report')).toHaveCount(0)
})

// @e2e urgent-decision-procedure::a-letter-reports-the-decision
test('AgendaItem: a letter whose reportsOnDecision names an urgent decision sets its report date once', async ({
	playwright,
}) => {
	const admin = await adminActor(playwright)
	const ledger = new ObjectLedger(['agenda-item'])
	try {
		const { results } = await listObjects(admin, 'decision', {
			isUrgent: 'true',
		})
		const open = results.find((d) => !d.reportedToRatifyingBodyAt)
		if (open === undefined) {
			test.skip(true, 'every seeded urgent decision is already reported')
			return
		}
		const decisionId = String(open.id ?? open['@self']?.id)

		await createObject(admin, ledger, 'agenda-item', {
			title: 'Raadsinformatiebrief over het spoedbesluit',
			typeFields: { reportsOnDecision: decisionId },
		})
		const first = await (await readObject(admin, 'decision', decisionId)).json()
		expect(first.reportedToRatifyingBodyAt).toMatch(/^\d{4}-\d{2}-\d{2}/)

		await createObject(admin, ledger, 'agenda-item', {
			title: 'Tweede raadsinformatiebrief over het spoedbesluit',
			typeFields: { reportsOnDecision: decisionId },
		})
		const second = await (await readObject(admin, 'decision', decisionId)).json()
		expect(second.reportedToRatifyingBodyAt).toBe(
			first.reportedToRatifyingBodyAt,
		)
	} finally {
		await ledger.cleanup(admin)
		await disposeActors(admin)
	}
})

// @e2e urgent-decision-procedure::download
test('UrgentDecisions: the ratified list downloads with two rows', async ({
	page,
}) => {
	await page.goto(LIST)
	await page.getByRole('button', { name: /Ratified/ }).click()
	await expect(page.locator('tbody tr')).toHaveCount(2)
	await page
		.getByRole('button', { name: /Download|Export/ })
		.first()
		.click()
	const dialog = page.getByRole('dialog')
	const download = page.waitForEvent('download')
	await dialog
		.getByRole('button', { name: /Download|Export/ })
		.last()
		.click()
	expect((await download).suggestedFilename()).toMatch(/\.csv$/)
})
