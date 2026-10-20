/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The motion page's tabs in the simple structure (boards DcVoorstel,
 * DcVoorstelOndertekenaars, DcStemvolgorde, DcMotieInStemming,
 * DcStemuitslag): Overview, Papers, Amendments, Voting and History in the
 * strip, the facts and the two side blocks beside it. Every name the overlay
 * uses is checked against what it names: the declared widgets, the schema,
 * the registry and the Dutch catalogue.
 *
 * @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { LIBRARY_WIDGET_KEYS } from '@conduction/nextcloud-vue/src/utils/libraryWidgetKeys.js'
import { validateManifest } from '@conduction/nextcloud-vue/src/utils/validateManifest.js'
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
 * The decision schema's properties as an instance gets them: the base
 * register plus every fragment that adds to the schema. A motion is a
 * decision.
 *
 * @return {object} The merged properties.
 */
function decisionProperties() {
	const properties = {
		...readJson('lib', 'Settings', 'decidesk_register.json').components.schemas
			.Decision.properties,
	}
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const name of fs.readdirSync(dir).sort()) {
		if (!name.endsWith('.json')) continue
		const schemas = readJson('lib', 'Settings', 'register.d', name).components
			?.schemas ?? {}
		for (const [key, schema] of Object.entries(schemas)) {
			if (key.toLowerCase() === 'decision' || schema?.slug === 'decision') {
				Object.assign(properties, schema.properties ?? {})
			}
		}
	}
	return properties
}

const simple = build('menu-layout.simple.json')
const full = build('menu-layout.json')
const pageOf = (built, id) => built.pages.find((page) => page.id === id)
const registrySource = read('src', 'registry.js')
const dutch = readJson('l10n', 'nl.json').translations
const properties = decisionProperties()

const config = pageOf(simple, 'MotionDetail').config
const widgets = new Map(config.widgets.map((widget) => [widget.id, widget]))
const strip = widgets.get('motion-panels')
const tabs = strip?.content?.tabs ?? []

/**
 * The blocks a tab shows: the panel itself, or each block of a sections panel.
 *
 * @param {object} tab A tab entry.
 * @return {Array<object>} The widgets.
 */
function blocksOf(tab) {
	const panel = widgets.get(tab.widgetId)
	return panel?.type === 'detail-sections'
		? panel.content.sections.map((section) => section.widget)
		: [panel]
}

/**
 * The side column's widgets, an id resolved to the page's own widget.
 *
 * @return {Array<object>} The widgets.
 */
function sideWidgets() {
	return (config.sideColumn ?? []).map((entry) =>
		typeof entry === 'string' ? widgets.get(entry) : entry,
	)
}

describe('the motion page in the simple structure', () => {
	it('lays out one tab strip and nothing else in the body', () => {
		expect(strip?.type).toBe('tabs')
		expect(config.layout.map((item) => item.widgetId)).toEqual([
			'motion-panels',
		])
	})

	it('shows the board tabs in the board order, History last', () => {
		const visible = tabs.filter((tab) => !tab.overflow)
		expect(visible.map((tab) => tab.label)).toEqual([
			'Overview',
			'Papers',
			'Amendments',
			'Voting',
			'History',
		])
		expect(visible.slice(0, 4).map((tab) => dutch[tab.label])).toEqual([
			'Overzicht',
			'Stukken',
			'Amendementen',
			'Stemming',
		])
		expect(tabs.filter((tab) => tab.overflow).map((tab) => tab.label)).toEqual([
			'Signers',
			'Voting order',
			'Stage',
		])
	})

	it('shows the record history in the History tab', () => {
		const history = tabs.find((tab) => tab.label === 'History')
		expect(widgets.get(history.widgetId).type).toBe('audit-trail')
		expect(LIBRARY_WIDGET_KEYS).toContain('audit-trail')
	})

	it('loses no block, and shows none twice', () => {
		const declared = pageOf(full, 'MotionDetail').config.widgets.map(
			(widget) => widget.id,
		)
		const shown = [
			...tabs.flatMap((tab) => blocksOf(tab).map((widget) => widget.id)),
			...sideWidgets().map((widget) => widget.id),
		]
		expect(declared.filter((id) => !shown.includes(id))).toEqual([])
		expect(new Set(shown).size).toBe(shown.length)
	})

	it('gives every block in a tab or the side column a type that resolves', () => {
		const placed = [
			...tabs.map((tab) => widgets.get(tab.widgetId)),
			...tabs.flatMap(blocksOf),
			...sideWidgets(),
		]
		for (const widget of placed) {
			expect(widget, 'a placed widget exists').toBeDefined()
			expect(widget.type, widget.id).not.toBe('custom')
			const resolves =
				widget.type === 'detail-sections'
				|| LIBRARY_WIDGET_KEYS.includes(widget.type)
				|| new RegExp(`\\n\\t${widget.type}: page\\(`).test(registrySource)
			expect(resolves, `${widget.id}: ${widget.type}`).toBe(true)
		}
		// A custom block is named by the component the full structure's slot
		// map gives it, so both structures render the same component.
		const slots = pageOf(full, 'MotionDetail').slots
		for (const widget of pageOf(full, 'MotionDetail').config.widgets) {
			if (widget.type === 'custom') {
				expect(widgets.get(widget.id).type, widget.id).toBe(
					slots[`widget-${widget.id}`],
				)
			}
		}
	})

	it('puts the facts, the residents advice and the interests beside the tabs', () => {
		const side = sideWidgets()
		expect(side.map((widget) => widget.id)).toEqual([
			'motion-side-facts',
			'motion-citizen-advice',
			'motion-conflicts',
		])
		const facts = side[0]
		expect(facts.type).toBe('data')
		expect(facts.content.editable).toBe(false)
		expect(dutch[facts.title]).toBe('Kerngegevens')
		// It leads with a field every motion has, so it is never an empty card.
		expect(facts.content.include[0]).toBe('decisionType')
		for (const field of facts.content.include) {
			expect(properties[field], field).toBeDefined()
		}
	})

	it('reads every text in Dutch', () => {
		const texts = [
			strip.content.ariaLabel,
			strip.content.moreLabel,
			...tabs.map((tab) => tab.label),
			...config.widgets
				.filter((widget) => widget.type === 'detail-sections')
				.flatMap((panel) => panel.content.sections.map((section) => section.label)),
		]
		for (const text of texts) {
			expect(dutch[text], text).toBeTruthy()
		}
	})

	it('builds into a manifest the library accepts', () => {
		const result = validateManifest(JSON.parse(JSON.stringify(simple)))
		expect(result.errors).toEqual([])
	})
})
