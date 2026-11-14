/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: AI summaries of the papers on the agenda item page (change
 * agenda-ai-paper-summaries, matrix rows age-16 age-20 plt-27). The request
 * checks, the schedule and the refusals are covered by PaperSummaryServiceTest
 * and PaperSummaryAccessTest; the task result by PaperSummaryTaskListenerTest;
 * the review rules and payloads by paperSummaries.spec.js.
 *
 * Needs the municipality example set: agenda item "Kadernota begroting 2026"
 * carries a shown summary and a draft comparison. Runs as the administrator,
 * who counts as the secretariat. The provider-dependent tests skip with a
 * named reason on an instance without (or with) a TaskProcessing provider.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

/**
 * Open the seeded budget item and wait for the summaries widget.
 *
 * @param page The page.
 * @return The widget.
 */
async function openBudgetItem(page) {
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await page.getByText('Kadernota begroting 2026').first().click()
	const widget = page.getByTestId('paper-summaries')
	await expect(widget).toBeVisible()
	return widget
}

/**
 * Whether the instance has a provider that can summarise.
 *
 * @param page The page.
 * @return True when summaries can be asked for.
 */
async function canSummarise(page): Promise<boolean> {
	const response = await page.request.get(
		`${BASE}/index.php/apps/decidiq/api/paper-summaries/availability`,
		{
			headers: { 'OCS-APIREQUEST': 'true', requesttoken: '' },
		},
	)
	if (!response.ok()) return false
	return (await response.json())?.summary === true
}

// @e2e agenda-ai-paper-summaries::a-clerk-sees-every-summary
test('a clerk sees the draft comparison with its review actions', async ({
	page,
}) => {
	const widget = await openBudgetItem(page)
	await expect(widget).toContainText(
		'Comparison of Raadsvoorstel kadernota 2026.pdf with Kadernota 2026.docx',
	)
	await expect(widget.getByTestId('paper-summaries-show').first()).toBeVisible()
	await expect(widget.getByTestId('paper-summaries-hide').first()).toBeVisible()
	await expect(widget.getByTestId('paper-summaries-edit').first()).toBeVisible()
})

// @e2e agenda-ai-paper-summaries::the-griffier-corrects-and-shows-a-summary
test('a shown summary carries the AI-generated label with the clerk who checked it', async ({
	page,
}) => {
	const widget = await openBudgetItem(page)
	await expect(widget.getByTestId('paper-summaries-label').first()).toContainText(
		'AI-generated summary, checked by Jan de Vries',
	)
})

// @e2e agenda-ai-paper-summaries::no-ai-provider-installed
test('without an AI provider the actions are left out and the widget says so', async ({
	page,
}) => {
	test.skip(
		await canSummarise(page),
		'this instance has a TaskProcessing provider; the no-provider state cannot be shown here',
	)
	const widget = await openBudgetItem(page)
	await expect(widget.getByTestId('paper-summaries-no-provider')).toBeVisible()
	await expect(widget.getByTestId('paper-summaries-summarise')).toHaveCount(0)
})

// @e2e agenda-ai-paper-summaries::the-griffier-asks-for-a-summary
test('the griffier asks for a summary and it appears as requested', async ({
	page,
}) => {
	test.skip(
		!(await canSummarise(page)),
		'no TaskProcessing provider on this instance; PaperSummaryServiceTest covers the request',
	)
	const widget = await openBudgetItem(page)
	await widget.getByTestId('paper-summaries-summarise').first().click()
	await expect(widget.getByTestId('paper-summaries-status').first()).toContainText(
		'Requested',
	)
})
