/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Choose a lens on an index page whose manifest sets `quickFilterMode: dropdown`.
 *
 * In that mode CnQuickFilterBar draws one NcSelect (vue-select) instead of a
 * strip of buttons, so `getByRole('button', { name: 'Public' })` finds nothing.
 * The "All" lens has an empty filter and is no option of the select: choosing
 * it means clearing the selection. Option labels carry a count ("Public (3)"),
 * so the label is matched at the start.
 */
import type { Page } from '@playwright/test'

/**
 * Choose one lens, clearing any lens chosen before.
 *
 * @param {Page}   page  The page.
 * @param {string} label The lens label as the manifest names it; 'All' clears.
 * @return {Promise<void>}
 */
export async function chooseLens(page: Page, label: string): Promise<void> {
	const select = page.locator('.cn-quick-filter-bar__select').first()
	const clear = select.locator('.vs__clear')
	if (await clear.isVisible()) {
		await clear.click()
	}

	if (label !== 'All') {
		await select.locator('.vs__search').click()
		await page.getByRole('option', { name: new RegExp(`^${label}\\b`) }).first().click()
	}

	await page.keyboard.press('Escape')
}
