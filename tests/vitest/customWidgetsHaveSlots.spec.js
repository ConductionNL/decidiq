/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A `custom` widget on a detail page renders through the page's top-level
 * `slots` map (`widget-<id>` names its registry component). A custom widget
 * without its slot renders an empty card. Found live on 25 Nov 2026: in the
 * full structure the agenda item page showed nine of its custom widgets
 * (among them Annotate, Papers as PDF and Readers) as empty cards, because
 * its slots map named only four.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import registry from '../../src/registry.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

const STRUCTURES = {
	full: () => buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.json')),
	simple: () => buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.simple.json')),
}

/**
 * The custom widgets of every page that have no slot, as `page widget`.
 *
 * @param {object} manifest A built manifest.
 * @return {Array<string>} The widgets without a slot.
 */
function customWidgetsWithoutSlot(manifest) {
	const missing = []
	for (const page of manifest.pages ?? []) {
		const slots = page.slots ?? {}
		for (const widget of page.config?.widgets ?? []) {
			if (widget?.type === 'custom' && !slots[`widget-${widget.id}`]) {
				missing.push(`${page.id} ${widget.id}`)
			}
		}
	}
	return missing
}

describe.each(Object.keys(STRUCTURES))('the %s structure', (structure) => {
	const manifest = STRUCTURES[structure]()

	it('gives every custom widget its slot', () => {
		const missing = customWidgetsWithoutSlot(manifest)
		if (missing.length) console.log(structure, 'MISSING', JSON.stringify(missing))
		expect(missing).toEqual([])
	})

	it('names only registered components in its slots', () => {
		const unknown = (manifest.pages ?? []).flatMap((page) => Object.entries(page.slots ?? {})
			.filter(([, component]) => !registry[component])
			.map(([slot, component]) => `${page.id} ${slot} ${component}`))
		if (unknown.length) console.log(structure, 'UNKNOWN', JSON.stringify(unknown))
		expect(unknown).toEqual([])
	})
})
