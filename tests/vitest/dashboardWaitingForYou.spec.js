/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboard's "Waiting for you" card (board DcDashboard, Wacht op u): the
 * approval stages that wait on the reader, each with the step it asks for
 * (sign off, give advice, or look) and a link to the decision. The stage
 * names who it waits on in `waitingOn` (register fragment 125), which the
 * approval engine keeps.
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
const config = simple.pages.find((page) => page.id === 'Dashboard').config
const card = config.widgets.find((widget) => widget.id === 'simple-waiting-for-you')
const dutch = readJson('l10n', 'nl.json').translations

/**
 * The decision-stage properties: the base register plus every fragment.
 *
 * @return {object} The merged properties.
 */
function stageProperties() {
	const properties = {
		...readJson('lib', 'Settings', 'decidesk_register.json').components.schemas
			.DecisionStage.properties,
	}
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const name of fs.readdirSync(dir).sort()) {
		if (!name.endsWith('.json')) continue
		const schemas =
			readJson('lib', 'Settings', 'register.d', name).components?.schemas ?? {}
		for (const schema of Object.values(schemas)) {
			if (schema?.slug === 'decision-stage') {
				Object.assign(properties, schema.properties ?? {})
			}
		}
	}
	return properties
}
const properties = stageProperties()

describe('the Waiting for you card', () => {
	it('lists the active stages that wait on the reader', () => {
		expect(card.type).toBe('record-list')
		expect(card.content.schema).toBe('decision-stage')
		expect(card.content.filter).toEqual({ waitingOn: '@me', status: 'active' })
		expect(properties.waitingOn.type).toBe('string')
		expect(properties.status.enum).toContain('active')
	})

	it('names the step it asks for by the stage type', () => {
		const action = card.content.rowAction
		expect(action.labels.field).toBe('stageType')
		for (const type of Object.keys(action.labels.map)) {
			expect(properties.stageType.enum, type).toContain(type)
		}
		expect(dutch[action.labels.map.endorsement]).toBe('Paraferen')
		expect(dutch[action.labels.map.advisory]).toBe('Advies geven')
		expect(dutch[action.label]).toBe('Bekijken')
	})

	it('opens the decision the stage belongs to', () => {
		expect(card.content.rowAction.type).toBe('navigate')
		expect(card.content.rowAction.target).toBe('/decisions/@object.decision')
		expect(properties.decision.format).toBe('uuid')
		expect(read('src', 'manifest.json')).toContain('"route": "/decisions/:id"')
	})

	it('reads in Dutch', () => {
		for (const text of [card.title, card.content.emptyText]) {
			expect(dutch[text], text).toBeTruthy()
		}
		expect(dutch[card.title]).toBe('Wacht op u')
	})

	it('sits in the main column under the proposals per step', () => {
		const placed = config.layout.find(
			(item) => item.widgetId === 'simple-waiting-for-you',
		)
		const proposals = config.layout.find(
			(item) => item.widgetId === 'simple-proposals-per-step',
		)
		expect(placed.gridX).toBe(0)
		expect(placed.gridWidth).toBe(8)
		expect(placed.gridY).toBe(proposals.gridY + proposals.gridHeight)
	})
})
