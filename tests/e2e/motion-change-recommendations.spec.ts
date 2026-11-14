/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the secretariat recommends a line-numbered text change to a
 * motion, and the chair or secretary accepts or rejects it without a vote
 * (change motion-change-recommendations, matrix row mot-19). Who may decide,
 * the stale-passage refusal and the deliberation window are covered by
 * ChangeRecommendationServiceTest and ChangeRecommendationControllerTest; the
 * line numbering and the passage check by lineNumbering.spec.js and
 * changeRecommendations.spec.js.
 *
 * Needs a motion "Verlichting fietspaden 2027" in deliberation with at least
 * five lines of text, opened by a user who is chair or secretary of its
 * meeting.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

const MOTION = 'Verlichting fietspaden 2027'

async function openMotion(page) {
	await page.goto(`${BASE}/index.php/apps/decidiq/motions`)
	await page.getByText(MOTION).first().click()
	await expect(page.getByTestId('motion-text-lines-tab')).toBeVisible()
}

async function recommend(page, from, to, text, reason) {
	await page.getByTestId('motion-text-lines-toggle').click()
	await page.getByTestId('motion-text-lines-recommend').click()
	const dialog = page.getByTestId('change-recommendation-dialog')
	await dialog.getByTestId('change-recommendation-from').click()
	await page.getByRole('option', { name: from, exact: true }).click()
	await dialog.getByTestId('change-recommendation-to').click()
	await page.getByRole('option', { name: to, exact: true }).click()
	await expect(
		dialog.getByTestId('change-recommendation-current').locator('textarea'),
	).toHaveValue(/\S/)
	await dialog
		.getByTestId('change-recommendation-new')
		.locator('textarea')
		.fill(text)
	await dialog
		.getByTestId('change-recommendation-reason')
		.locator('textarea')
		.fill(reason)
	await dialog.getByTestId('change-recommendation-submit').click()
}

// @e2e motion-amendment::the-griffier-turns-on-line-numbers
test('the line numbers are the same every time the motion opens', async ({
	page,
}) => {
	await openMotion(page)
	const tab = page.getByTestId('motion-text-lines-tab')
	await tab.getByTestId('motion-text-lines-toggle').click()
	await expect(tab).toContainText('1')
	const first = await tab.innerText()
	await openMotion(page)
	await page.getByTestId('motion-text-lines-toggle').click()
	expect(await page.getByTestId('motion-text-lines-tab').innerText()).toBe(first)
})

// @e2e motion-amendment::the-griffier-recommends-a-corrected-amount
test('the secretary recommends a change to lines 4 to 5', async ({ page }) => {
	await openMotion(page)
	await recommend(
		page,
		'4',
		'5',
		'Een krediet van 645.000 euro beschikbaar stellen',
		'Bedrag uit de begrotingswijziging',
	)
	const list = page.getByTestId('motion-change-recommendations-tab')
	await expect(list).toContainText('645.000 euro')
	await expect(
		list.getByTestId('change-recommendation-accept').first(),
	).toBeVisible()
})

// @e2e motion-amendment::accepting-the-corrected-amount
test('accepting a recommendation works it into the motion text', async ({
	page,
}) => {
	await openMotion(page)
	const list = page.getByTestId('motion-change-recommendations-tab')
	await list.getByTestId('change-recommendation-accept').first().click()
	await expect(page.getByTestId('motion-text-lines-tab')).toContainText(
		'645.000 euro',
	)
})

// @e2e motion-amendment::rejecting-a-wording-suggestion
test('rejecting a recommendation leaves the motion text alone', async ({ page }) => {
	await openMotion(page)
	await recommend(page, '1', '1', 'Een andere eerste regel', 'Duidelijker')
	await page.getByTestId('motion-text-lines-toggle').click()
	const before = await page.getByTestId('motion-text-lines-tab').innerText()
	const list = page.getByTestId('motion-change-recommendations-tab')
	await list.getByTestId('change-recommendation-reject').last().click()
	await expect(list).toContainText('Rejected')
	await expect(page.getByTestId('motion-text-lines-tab')).toHaveText(before)
})
