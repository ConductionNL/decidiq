/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The document page (board DcStuk): a paper of a meeting or an agenda item
 * as a page of its own, with tabs Overview (its details and its file),
 * Notes and History, and what it is linked to beside them.
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

const dutch = readJson('l10n', 'nl.json').translations
const detailWidgets = read(
	'src',
	'components',
	'widgets',
	'registerDetailWidgets.js',
)

describe.each(['menu-layout.simple.json', 'menu-layout.json'])(
	'the document page (%s)',
	(file) => {
		const built = buildProfiledManifest(
			buildManifest,
			readJson('src', 'manifest.json'),
			fragments,
			readJson('src', file),
		)
		const page = built.pages.find((item) => item.id === 'DocumentDetail')
		const widgets = new Map(
			(page?.config.widgets ?? []).map((widget) => [widget.id, widget]),
		)

		it('is the detail page of a digital document at /documents/:id', () => {
			expect(page.type).toBe('detail')
			expect(page.route).toBe('/documents/:id')
			expect(page.config.schema).toBe('digital-document')
		})

		it('draws Overview, Notes and History as tabs, with the file in the overview', () => {
			const strip = widgets.get('document-panels')
			expect(page.config.layout.map((item) => item.widgetId)).toEqual([
				'document-panels',
			])
			expect(strip.content.tabs.map((tab) => dutch[tab.label])).toEqual([
				'Overzicht',
				'Notities',
				'Historie',
			])
			const overview = widgets.get(strip.content.tabs[0].widgetId)
			expect(
				overview.content.sections.map(
					(section) => section.widget?.id ?? section.widgetId,
				),
			).toEqual(['document-data', 'document-files'])
			expect(widgets.get('document-notes')).toMatchObject({
				type: 'integration',
				integrationId: 'notes',
			})
			expect(widgets.get('document-history').type).toBe('audit-trail')
		})

		it('shows what the document is linked to beside the tabs', () => {
			expect(page.config.sideColumn).toEqual(['document-related'])
			expect(widgets.get('document-related').type).toBe('related')
		})

		it('uses widget types that resolve, and Dutch texts', () => {
			for (const widget of widgets.values()) {
				const resolves =
					LIBRARY_WIDGET_KEYS.includes(widget.type)
					|| detailWidgets.includes(
						`registerDashboardWidget('${widget.type}', {`,
					)
				expect(resolves, `${widget.id}: ${widget.type}`).toBe(true)
				expect(dutch[widget.title], widget.title).toBeTruthy()
			}
		})
	},
)
