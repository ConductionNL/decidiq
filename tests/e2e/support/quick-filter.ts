/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Choose a lens on an index page whose manifest sets `quickFilterMode: dropdown`.
 *
 * In that mode CnQuickFilterBar draws one NcSelect (vue-select) instead of a
 * strip of buttons, so `getByRole('button', { name: 'Public' })` finds nothing.
 * The "All" lens has an empty filter and is no option of the select: choosing
 * it means removing every chosen lens. Option labels carry a count ("Public (3)"),
 * so the label is matched at the start. A long label may carry a soft hyphen
 * ("Invita\u00ADtions"), so any soft hyphen or break between letters is allowed.
 */
import type { Locator, Page } from '@playwright/test'

/**
 * Choose one lens, clearing any lens chosen before.
 *
 * @param {Page}   page  The page.
 * @param {string} label The lens label as the manifest names it; 'All' clears.
 * @return {Promise<void>}
 */
export async function chooseLens(page: Page, label: string): Promise<void> {
	const select = page.locator('.cn-quick-filter-bar__select').first()
	// The select is `multiple`: each chosen lens is a tag with its own remove button.
	const chosen = select.locator('.vs__deselect')
	while (await chosen.count() > 0) {
		await chosen.first().click()
	}

	if (label !== 'All') {
		await select.locator('.vs__search').click()
		const pattern = label.split('').map((c) => c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('[\\u00AD\\s]*')
		await page.getByRole('option', { name: new RegExp(`^${pattern}`) }).first().click()
	}

	await page.keyboard.press('Escape')
}

/**
 * Open an NcSelect that sits inside a dialog and pick its first option.
 *
 * Inside a dialog the select does not open on a click (aria-expanded stays
 * false; seen on the mass-export format and the declaration dialogs, 25 Nov
 * 2026). Typing opens it, as it does for a keyboard user: a character and a
 * backspace leave the full list open, and Enter picks the first option.
 *
 * @param {Page}    page  The page.
 * @param {Locator} input The select's input (the combobox).
 * @return {Promise<void>}
 */
export async function pickFirstOptionByKeyboard(page: Page, input: Locator): Promise<void> {
	await input.focus()
	await page.keyboard.type('a')
	await page.keyboard.press('Backspace')
	await page.getByRole('option').first().waitFor()
	await page.keyboard.press('Enter')
}

