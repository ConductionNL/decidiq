/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The archive page's tables (board DcArchief): under the counters, the
 * dossiers ready for destruction, the dossiers already destroyed or
 * transferred, and the log of what was done to the register's records.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { LIBRARY_WIDGET_KEYS } from '@conduction/nextcloud-vue/src/utils/libraryWidgetKeys.js'
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
const config = simple.pages.find((page) => page.id === 'ArchiveDashboard').config
const widgets = new Map(config.widgets.map((widget) => [widget.id, widget]))
const placed = (id) => config.layout.find((item) => item.widgetId === id)
const dutch = readJson('l10n', 'nl.json').translations

/**
 * The archival-dossier properties, base register plus fragments.
 *
 * @return {object} The merged properties.
 */
function dossierProperties() {
	const properties = {}
	for (const name of fs
		.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
		.sort()) {
		if (!name.endsWith('.json')) continue
		for (const schema of Object.values(
			readJson('lib', 'Settings', 'register.d', name).components?.schemas
				?? {},
		)) {
			if (schema?.slug === 'archival-dossier')
				Object.assign(properties, schema.properties ?? {})
		}
	}
	return properties
}
const properties = dossierProperties()

describe('the archive tables', () => {
	it('lists the closed dossiers routed for destruction', () => {
		const list = widgets.get('archive-ready-for-destruction').content
		expect(list.schema).toBe('archival-dossier')
		expect(list.filter).toEqual({
			lifecycle: 'closed',
			disposition: 'destruction',
		})
		expect(list.rowRoute).toBe('ArchivalDossierDetail')
	})

	it('lists the dossiers destroyed or transferred', () => {
		const list = widgets.get('archive-disposed').content
		expect(list.filter).toEqual({ lifecycle: ['destroyed', 'transferred'] })
		expect(properties.lifecycle.enum).toEqual(
			expect.arrayContaining(['destroyed', 'transferred']),
		)
	})

	it('reads columns the dossier has, in Dutch', () => {
		for (const id of ['archive-ready-for-destruction', 'archive-disposed']) {
			for (const column of widgets.get(id).content.columns) {
				expect(properties[column.key], `${id}: ${column.key}`).toBeDefined()
				expect(dutch[column.label], column.label).toBeTruthy()
			}
			expect(dutch[widgets.get(id).title], id).toBeTruthy()
		}
	})

	it('shows the register log as the app-wide audit feed', () => {
		const log = widgets.get('archive-log')
		expect(log.type).toBe('audit-trail')
		expect(log.content).toMatchObject({ scope: 'app', register: 'decidiq' })
		expect(LIBRARY_WIDGET_KEYS).toContain('audit-trail')
		expect(dutch[log.title]).toBe('Logboek')
	})

	it('lays the three out full width under the counters', () => {
		const bottom = Math.max(
			...config.layout
				.filter((item) => item.widgetId.startsWith('dossiers-'))
				.map((item) => item.gridY + item.gridHeight),
		)
		let previous = bottom - 1
		for (const id of [
			'archive-ready-for-destruction',
			'archive-disposed',
			'archive-log',
		]) {
			expect(placed(id).gridWidth, id).toBe(12)
			expect(placed(id).gridY, id).toBeGreaterThan(previous)
			previous = placed(id).gridY
		}
	})
})
