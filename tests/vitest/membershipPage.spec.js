/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The membership page (board DcLidmaatschap): one person's seat on one body,
 * with tabs Overview, Onboarding, Offboarding and History, the onboarding
 * and offboarding being the traject records that name this membership. The
 * person page links each membership to it.
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

describe('the membership page', () => {
	const built = buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', 'menu-layout.simple.json'),
	)
	const page = built.pages.find((item) => item.id === 'MembershipDetail')
	const widgets = new Map(
		(page?.config.widgets ?? []).map((widget) => [widget.id, widget]),
	)

	it('is the detail page of a membership at /memberships/:id', () => {
		expect(page.type).toBe('detail')
		expect(page.route).toBe('/memberships/:id')
		expect(page.config.schema).toBe('membership')
	})

	it('draws Overview, Onboarding, Offboarding and History as tabs', () => {
		const strip = widgets.get('membership-panels')
		expect(page.config.layout.map((item) => item.widgetId)).toEqual([
			'membership-panels',
		])
		expect(strip.content.tabs.map((tab) => dutch[tab.label])).toEqual([
			'Overzicht',
			'Instroom',
			'Uitstroom',
			'Historie',
		])
		expect(widgets.get('membership-history').type).toBe('audit-trail')
	})

	it('lists the onboarding and offboarding that name this membership', () => {
		for (const [id, slug] of [
			['membership-onboarding', 'member-onboarding'],
			['membership-offboarding', 'member-offboarding'],
		]) {
			const list = widgets.get(id).content
			expect(list.schema, id).toBe(slug)
			expect(list.filter, id).toEqual({ membership: '@objectId' })
			expect(propertiesOf(slug).membership.format, id).toBe('uuid')
		}
	})

	it('uses widget types that resolve, and Dutch texts', () => {
		for (const widget of widgets.values()) {
			expect(
				LIBRARY_WIDGET_KEYS.includes(widget.type)
					|| widget.type === 'detail-sections',
				widget.id,
			).toBe(true)
			expect(dutch[widget.title], widget.title).toBeTruthy()
		}
	})

	it('is linked from each membership on the person page', () => {
		const tab = read('src', 'components', 'tabs', 'PersonMembershipsTab.vue')
		expect(
			tab.match(/name: 'MembershipDetail',\s*params: \{ id: row\.id \}/g),
		).toHaveLength(2)
	})
})
