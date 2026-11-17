/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Open the index row that shows a given text.
 *
 * CnDataTable lays its row link (`cn-row-link`) over the title cell, so a click
 * on the text in that cell lands on the link, a sibling of the text: Playwright
 * then reports "subtree intercepts pointer events" and retries until the test
 * times out. Clicking the row's link is what a user's click does.
 */
import type { Page } from '@playwright/test'

/**
 * Click the row link of the first table row containing the text.
 *
 * @param page The page, on an index.
 * @param text The text the row shows.
 * @param exact Match the text exactly.
 */
export async function openRow(page: Page, text: string, exact = false): Promise<void> {
	const row = page.locator('tr').filter({ has: page.getByText(text, { exact }) }).first()
	await row.getByTestId('cn-row-link').first().click()
}
