/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A written resolution (board DcSchriftelijkBesluit): a decision taken
 * outside a meeting, by the members' written responses. Its page gets a
 * Responses tab, shown on a written resolution only, listing the responses
 * that name the decision, where a member adds theirs; the decisions list
 * gets a view on them.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
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
 * A schema's properties, base register plus fragments, by slug.
 *
 * @param {string} slug The schema slug.
 * @return {object} The merged properties.
 */
function propertiesOf(slug) {
	const properties = {}
	const files = [path.join('lib', 'Settings', 'decidesk_register.json')]
	for (const name of fs
		.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
		.sort()) {
		if (name.endsWith('.json'))
			files.push(path.join('lib', 'Settings', 'register.d', name))
	}
	for (const file of files) {
		for (const [key, schema] of Object.entries(
			readJson(file).components?.schemas ?? {},
		)) {
			if (
				schema?.slug === slug
				|| (!schema?.slug && key.toLowerCase() === slug)
			) {
				Object.assign(properties, schema.properties ?? {})
			}
		}
	}
	return properties
}

describe('a written resolution', () => {
	const config = pageOf('DecisionDetail').config
	const widgets = new Map(config.widgets.map((widget) => [widget.id, widget]))
	const tabs = widgets.get('decision-panels').content.tabs
	const tab = tabs.find((item) => item.widgetId === 'decision-written-responses')

	it('shows a Responses tab after the overview, on a written resolution only', () => {
		expect(tabs.indexOf(tab)).toBe(1)
		expect(dutch[tab.label]).toBe('Reacties')
		expect(propertiesOf('decision').resolutionType.enum).toContain(
			'written-resolution',
		)
		expect(
			evaluateVisibleWhenLocal(tab.visibleWhen, {
				resolutionType: 'written-resolution',
			}),
		).toBe(true)
		expect(
			evaluateVisibleWhenLocal(tab.visibleWhen, {
				resolutionType: 'approval',
			}),
		).toBe(false)
		expect(widgets.get('decision-panels').content.maxVisibleTabs).toBe(7)
	})

	it('lists the responses that name the decision, where a member adds theirs', () => {
		const list = widgets.get('decision-written-responses').content
		expect(list.schema).toBe('governance-consultation-response')
		expect(list.filter).toEqual({ relatedDecision: '@objectId' })
		expect(list.allowCreate).not.toBe(false)
		const properties = propertiesOf('governance-consultation-response')
		for (const column of list.columns) {
			expect(properties[column.key], column.key).toBeDefined()
			expect(dutch[column.label], column.label).toBeTruthy()
		}
	})

	it('has a view on the decisions list', () => {
		const views = pageOf('Decisions').config.quickFilters
		const view = views.find(
			(item) => item.filter?.resolutionType === 'written-resolution',
		)
		expect(dutch[view.label]).toBe('Schriftelijk')
	})
})
