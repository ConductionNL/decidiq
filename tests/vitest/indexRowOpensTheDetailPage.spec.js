/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A row on an index page opens the record's own detail page. Without
 * `config.rowRoute`, CnPageRenderer opens the FIRST detail page bound to the
 * same register and schema, and a sub-page such as `/agenda-items/:id/integrations`
 * listed before the detail page then answers every row click.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

/**
 * Build one structure from the manifest, the fragments and a profile file.
 *
 * @param {string} file The profile file under src/.
 * @return {object} The built manifest.
 */
function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', file),
	)
}

/**
 * The index pages whose rows open a sub-page rather than a detail page.
 *
 * @param {object} manifest The built manifest.
 * @return {Array<string>} `index -> opened page` for every wrong target.
 */
function wrongRowTargets(manifest) {
	const pages = manifest.pages ?? []
	const byId = new Map(pages.map((page) => [page.id, page]))
	const firstDetail = new Map()
	for (const page of pages) {
		if (page.type !== 'detail') continue
		const key = `${page.config?.register} ${page.config?.schema}`
		if (!firstDetail.has(key)) firstDetail.set(key, page)
	}
	const wrong = []
	for (const page of pages) {
		if (page.type !== 'index') continue
		const cfg = page.config ?? {}
		const opened = cfg.rowRoute
			? byId.get(cfg.rowRoute)
			: firstDetail.get(`${cfg.register} ${cfg.schema}`)
		if (opened && !/^\/[^:]*:id$/.test(opened.route ?? '')) {
			wrong.push(`${page.id} -> ${opened.id}`)
		}
	}
	return wrong
}

describe('an index row opens the detail page', () => {
	for (const file of ['menu-layout.json', 'menu-layout.simple.json']) {
		it(`in ${file}`, () => {
			expect(wrongRowTargets(build(file))).toEqual([])
		})
	}
})
