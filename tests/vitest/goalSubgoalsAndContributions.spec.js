/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The goal page's overview (board DcDoel): the goal, its sub-goals (with
 * "add sub-goal", which fills in the parent) and what contributes to it, the
 * action items and commitments that name the goal.
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
const config = pageOf('GoalDetail').config
const widgets = new Map(config.widgets.map((widget) => [widget.id, widget]))
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
		for (const schema of Object.values(
			readJson(file).components?.schemas ?? {},
		)) {
			if (schema?.slug === slug)
				Object.assign(properties, schema.properties ?? {})
		}
	}
	return properties
}

describe('the goal overview', () => {
	const strip = widgets.get('goal-panels')
	const overview = widgets.get(strip.content.tabs[0].widgetId)

	it('shows the goal, its sub-goals and what contributes to it', () => {
		expect(overview.type).toBe('detail-sections')
		expect(
			overview.content.sections.map((section) => section.widget.id),
		).toEqual([
			'goal-data',
			'goal-subgoals',
			'goal-contributing-actions',
			'goal-contributing-commitments',
		])
		for (const section of overview.content.sections) {
			expect(dutch[section.label], section.label).toBeTruthy()
		}
	})

	it('lists the sub-goals by their parent, and adds one with the parent filled in', () => {
		const list = widgets.get('goal-subgoals').content
		expect(list.schema).toBe('goal')
		expect(list.filter).toEqual({ parentGoal: '@objectId' })
		expect(list.allowCreate).not.toBe(false)
		expect(propertiesOf('goal').parentGoal).toBeDefined()
		expect(dutch['Sub-goals']).toBe('Subdoelen')
	})

	it('lists the action items and commitments that name the goal', () => {
		for (const [id, slug] of [
			['goal-contributing-actions', 'action-item'],
			['goal-contributing-commitments', 'governance-commitment'],
		]) {
			const list = widgets.get(id).content
			expect(list.schema, id).toBe(slug)
			expect(list.filter, id).toEqual({ goal: '@objectId' })
			const properties = propertiesOf(slug)
			expect(properties.goal, id).toBeDefined()
			for (const column of list.columns) {
				expect(properties[column.key], `${id}: ${column.key}`).toBeDefined()
				expect(dutch[column.label], column.label).toBeTruthy()
			}
		}
	})
})
