/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every decidiq board ends the app menu with "Mijn weergaven": the views a
 * person pinned, with a pen to change which, above Help and Advanced. The
 * library draws that section for an app whose lists offer saved views; this
 * checks that decidiq's main lists do, and that the section lands at the
 * bottom of decidiq's own menu, after both captioned groups.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { pageOffersSavedViews } from '@conduction/nextcloud-vue/src/utils/savedViewHelpers.js'
import {
	SAVED_VIEW_MENU_CAPTION,
	SAVED_VIEW_MENU_EMPTY,
	withSavedViewMenu,
} from '@conduction/nextcloud-vue/src/utils/savedViewMenu.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

const simple = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
)
const pageOf = (id) => simple.pages.find((page) => page.id === id)

describe('My views in the decidiq menu', () => {
	it('offers saved views on the lists the boards draw', () => {
		for (const id of [
			'Motions',
			'Meetings',
			'Decisions',
			'Commitments',
			'GovernanceBodies',
			'ActionItems',
		]) {
			expect(pageOffersSavedViews(pageOf(id).config), id).toBe(true)
		}
	})

	it('puts the section at the bottom of the main menu, after Registers', () => {
		const main = simple.menu.filter((entry) => !entry.section)
		const menu = withSavedViewMenu(
			main,
			[{ id: 'v1', name: 'Assigned to me', page: 'ActionItems' }],
			{ showEmpty: true },
		)
		const ids = menu.map((entry) => entry.id)
		const caption = ids.indexOf(SAVED_VIEW_MENU_CAPTION)
		expect(caption).toBe(ids.indexOf('Registers') + 1)
		expect(ids.slice(caption + 1)).toEqual(['saved-view-v1'])
		// CnAppNav sorts on `order`: the section has to outrank every entry.
		const highest = Math.max(
			...main.map((entry) => entry.order).filter(Number.isFinite),
		)
		expect(menu[caption].order).toBeGreaterThan(highest)
	})

	it('shows the section with a hint while nothing is pinned', () => {
		const main = simple.menu.filter((entry) => !entry.section)
		const ids = withSavedViewMenu(main, [], { showEmpty: true }).map(
			(entry) => entry.id,
		)
		expect(ids.slice(-2)).toEqual([
			SAVED_VIEW_MENU_CAPTION,
			SAVED_VIEW_MENU_EMPTY,
		])
	})
})
