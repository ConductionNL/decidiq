/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The search page (board DcZoeken): one search over meetings, decisions,
 * minutes and documents, the four as a Type filter, each result opening its
 * own page. The page is config over nextcloud-vue's `search` page type with
 * `sources` (feat/search-page-sources).
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
 * Every schema slug the register declares.
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

describe('the search page', () => {
	const page = pageOf('Search')

	it('is a search page at /search', () => {
		expect(page.type).toBe('search')
		expect(page.route).toBe('/search')
	})

	it('searches meetings, decisions, minutes and documents, in the board order', () => {
		const sources = page.config.sources
		expect(sources.map((source) => dutch[source.label])).toEqual([
			'Vergaderingen',
			'Besluiten',
			'Notulen',
			'Documenten',
		])
		const slugs = schemaSlugs()
		for (const source of sources) {
			expect(source.register).toBe('decidiq')
			expect(slugs.has(source.schema), source.schema).toBe(true)
			if (source.route) {
				expect(pageOf(source.route), source.route).toBeDefined()
			}
		}
	})

	it('reads in Dutch', () => {
		for (const text of [
			page.title,
			page.config.placeholder,
			page.config.typeLabel,
		]) {
			expect(dutch[text], text).toBeTruthy()
		}
		expect(dutch[page.title]).toBe('Zoeken')
	})
})
