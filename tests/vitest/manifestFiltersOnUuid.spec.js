/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A list widget that reads one object by its identifier filters on `uuid`,
 * never on a plain `id`: OpenRegister's list route takes `id` as a route
 * parameter and drops it, so an `id` filter lists every object of the schema. Found live on
 * 25 Nov 2026: a decision's "Taken under" list showed another delegation
 * ("1–1 of 9") instead of the one the decision names.
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-006-assistive-bevoegdheidsgrondslag-link-on-decision-never-enforcement
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const sources = [
	['src/manifest.json', readJson('src', 'manifest.json')],
	...fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.map((name) => [`src/manifest.d/${name}`, readJson('src', 'manifest.d', name)]),
]

/**
 * Every object-list widget's filter in a manifest tree, with where it sits.
 *
 * @param {*} node The tree.
 * @param {string} where The path so far.
 * @param {Array} out Collected filters.
 * @return {Array<{where: string, filter: object}>}
 */
function filtersOf(node, where = '', out = []) {
	if (Array.isArray(node)) {
		node.forEach((child, i) => filtersOf(child, `${where}[${i}]`, out))
	} else if (node && typeof node === 'object') {
		const filter = node.content?.filter
		if (node.type === 'object-list' && filter && typeof filter === 'object' && !Array.isArray(filter)) {
			out.push({ where: `${where} ${node.id}`, filter })
		}
		for (const [key, value] of Object.entries(node)) {
			filtersOf(value, `${where}.${key}`, out)
		}
	}
	return out
}

describe('list widgets that read one object by its identifier', () => {
	it('never filter on a plain id', () => {
		const onId = sources.flatMap(([file, tree]) => filtersOf(tree)
			.filter(({ filter }) => Object.prototype.hasOwnProperty.call(filter, 'id'))
			.map(({ where }) => `${file} ${where}`))
		expect(onId).toEqual([])
	})

	it('the decision\'s "Taken under" list reads the delegation it names by uuid', () => {
		const [, manifest] = sources[0]
		const widget = manifest.pages
			.flatMap((page) => page.config?.widgets ?? [])
			.find((w) => w.id === 'decision-authority-basis')
		expect(widget.content.filter).toEqual({ uuid: '@object.authorityBasis' })
	})
})
