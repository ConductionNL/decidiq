/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Consultaties as one page with tabs (board DcConsultaties): the four
 * consultation lists and the moderation queue each carry the same tab bar
 * (nextcloud-vue `pageTabs`, 2.114), so the reader moves between them as
 * between tabs of one page, each with its count.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
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
const dutch = readJson('l10n', 'nl.json').translations

/**
 * Every schema slug the register declares, base and fragments.
 *
 * @return {Set<string>} The slugs.
 */
function schemaSlugs() {
	const slugs = new Set()
	const files = [path.join('lib', 'Settings', 'decidesk_register.json')]
	for (const name of fs.readdirSync(
		path.join(ROOT, 'lib', 'Settings', 'register.d'),
	)) {
		if (name.endsWith('.json'))
			files.push(path.join('lib', 'Settings', 'register.d', name))
	}
	for (const file of files) {
		for (const schema of Object.values(
			readJson(file).components?.schemas ?? {},
		)) {
			if (schema?.slug) slugs.add(schema.slug)
		}
	}
	return slugs
}

const LISTS = [
	'GovernanceConsultations',
	'WorksCouncilConsultations',
	'Consultations',
	'BudgetRounds',
]

describe('Consultaties as one page with tabs', () => {
	const tabs = pageOf('GovernanceConsultations').config.pageTabs

	it('draws the board tabs in the board order', () => {
		expect(tabs.map((tab) => dutch[tab.label])).toEqual([
			'Bestuurlijke consultaties',
			'OR-adviesaanvragen',
			'Publieke consultaties',
			'Participatieve begrotingen',
			'Moderatie',
		])
		expect(tabs.map((tab) => tab.route)).toEqual([...LISTS, 'ModerationQueue'])
		for (const tab of tabs) {
			expect(pageOf(tab.route), tab.route).toBeDefined()
		}
	})

	it('gives every list the same tab bar', () => {
		for (const id of LISTS) {
			expect(pageOf(id).config.pageTabs, id).toEqual(tabs)
		}
	})

	it('counts each tab on a schema the register has, with the list own schema', () => {
		const slugs = schemaSlugs()
		for (const tab of tabs) {
			expect(slugs.has(tab.countFrom.schema), tab.countFrom.schema).toBe(true)
			expect(tab.countFrom.register).toBe('decidiq')
		}
		for (const [index, id] of LISTS.entries()) {
			expect(tabs[index].countFrom.schema, id).toBe(pageOf(id).config.schema)
		}
		expect(tabs[4].countFrom.filter).toEqual({ moderationStatus: 'pending' })
	})
})
