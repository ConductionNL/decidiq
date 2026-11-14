/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Theme pages (publication-theme-pages, matrix row pub-20): the griffier adds
 * an agenda item or decision to a theme from its page, and the theme page
 * shows the staff timeline with the unpublished entries marked. This repo's
 * vitest cannot mount a `.vue` file, so the widgets' wiring is asserted
 * against the sources; the rules live in src/utils/themes.js.
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	idsOf,
	isLinked,
	LINK_PROPERTIES,
	themesHolding,
	themeUrl,
	timelineRows,
	timelineUrl,
	withLink,
} from '../../src/utils/themes.js'
import { validatorFor } from './helpers/registerSchema.js'

function source(path) {
	return readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8')
}

const pool = {
	'@self': { id: 'th-pool', register: 'decidiq', schema: 'governance-theme' },
	id: 'th-pool',
	title: 'Nieuw zwembad De Hoge Dijk',
	introduction: 'Wat er speelt rond het nieuwe zwembad.',
	status: 'active',
	isPublic: true,
	meetings: ['00000000-0000-4000-8000-000000000001'],
	agendaItems: ['00000000-0000-4000-8000-000000000002'],
	decisions: [],
}
const library = { id: 'th-lib', title: 'Bibliotheek', introduction: 'x', decisions: [{ id: '00000000-0000-4000-8000-000000000009' }] }

describe('adding to a theme', () => {
	it('appends the object to the link array of its kind and keeps every other field', () => {
		const body = withLink(pool, 'decision', '00000000-0000-4000-8000-000000000003')

		expect(body.decisions).toEqual(['00000000-0000-4000-8000-000000000003'])
		expect(body.agendaItems).toEqual(pool.agendaItems)
		expect(body.title).toBe(pool.title)
		expect(body.introduction).toBe(pool.introduction)
		expect(body).not.toHaveProperty('@self')
		expect(pool.decisions).toEqual([])
	})

	it('does not add the same object twice', () => {
		const body = withLink(pool, 'agendaItem', '00000000-0000-4000-8000-000000000002')

		expect(body.agendaItems).toEqual(['00000000-0000-4000-8000-000000000002'])
	})

	it('reads resolved link objects as their ids', () => {
		expect(idsOf(library.decisions)).toEqual(['00000000-0000-4000-8000-000000000009'])
		expect(withLink(library, 'decision', 'd-2').decisions).toEqual(['00000000-0000-4000-8000-000000000009', 'd-2'])
		expect(idsOf(undefined)).toEqual([])
	})

	it('refuses a kind a theme does not link', () => {
		expect(() => withLink(pool, 'minutes', 'x')).toThrow('A theme does not link minutes')
	})

	it('stores a theme the register schema accepts', () => {
		const validate = validatorFor('governance-theme')
		const body = withLink(pool, 'agendaItem', '00000000-0000-4000-8000-000000000004')
		delete body.id

		expect(validate(body), JSON.stringify(validate.errors)).toBe(true)
	})

	it('names every link array the theme schema declares', () => {
		const validate = validatorFor('governance-theme')
		for (const property of Object.values(LINK_PROPERTIES)) {
			const body = { title: 't', introduction: 'i', [property]: ['00000000-0000-4000-8000-000000000005'] }
			expect(validate(body), `${property}: ${JSON.stringify(validate.errors)}`).toBe(true)
		}
	})

	it('finds the themes holding an object', () => {
		expect(isLinked(pool, 'agendaItem', '00000000-0000-4000-8000-000000000002')).toBe(true)
		expect(isLinked(pool, 'decision', '00000000-0000-4000-8000-000000000002')).toBe(false)
		expect(isLinked(pool, 'nonsense', 'x')).toBe(false)
		expect(themesHolding([pool, library], 'decision', '00000000-0000-4000-8000-000000000009')).toEqual([library])
	})
})

describe('the staff timeline', () => {
	it('keeps the endpoint order and marks what is not published', () => {
		const rows = timelineRows([
			{ date: '2026-10-14', kind: 'meeting', title: 'Raad 14 oktober', objectId: 'm1', published: true, publication: 'p1' },
			{ date: '2026-12-01', kind: 'commitment', title: 'Toezegging energie', objectId: 'c1', published: false, publication: null },
			{ date: '2027-Q1', kind: 'plannedAgendaItem', title: 'Bouwbesluit', objectId: 'pa1', published: true },
		])

		expect(rows.map((row) => row.objectId)).toEqual(['m1', 'c1', 'pa1'])
		expect(rows.map((row) => row.published)).toEqual([true, false, true])
		expect(rows[2].kindLabel).toBe('Long-term agenda item')
		expect(rows[1].key).toBe('commitment:c1')
		expect(timelineRows(undefined)).toEqual([])
	})

	it('reads the timeline from decidiq and the themes from the object API', () => {
		expect(timelineUrl('th 1')).toContain('/apps/decidiq/api/themes/th%201/timeline')
		expect(themeUrl()).toContain('/apps/openregister/api/objects/decidiq/governance-theme?_limit=200')
		expect(themeUrl('th-pool')).toContain('/apps/openregister/api/objects/decidiq/governance-theme/th-pool')
	})
})

describe('the wiring', () => {
	it('registers the four widgets', () => {
		const registry = source('src/registry.js')
		for (const name of ['ThemeTimelineTab', 'ThemePublicationTab', 'AgendaItemThemesTab', 'DecisionThemesTab']) {
			expect(registry).toContain(`${name}: page(${name})`)
		}
	})

	it('declares the theme index and detail pages over the theme schema', () => {
		const fragment = JSON.parse(source('src/manifest.d/theme-pages.json'))
		const detail = fragment.pages.find((page) => page.id === 'TopicThemeDetail')

		expect(fragment.pages.find((page) => page.id === 'TopicThemes').config.schema).toBe('governance-theme')
		expect(detail.config.schema).toBe('governance-theme')
		expect(detail.config.widgets.map((widget) => widget.component).filter(Boolean)).toEqual(['ThemeTimelineTab', 'ThemePublicationTab'])
		for (const item of detail.config.layout) {
			expect(detail.config.widgets.map((widget) => widget.id)).toContain(item.widgetId)
		}
	})

	it('puts Add to theme on the agenda item and decision pages', () => {
		const manifest = JSON.parse(source('src/manifest.json'))
		for (const [page, component] of [['AgendaItemDetail', 'AgendaItemThemesTab'], ['DecisionDetail', 'DecisionThemesTab']]) {
			const config = manifest.pages.find((p) => p.id === page).config
			const widget = config.widgets.find((w) => w.component === component)
			expect(widget, page).toBeTruthy()
			expect(config.layout.map((l) => l.widgetId)).toContain(widget.id)
		}
	})

	it('keeps the dialog in its own file and stores the link with a PUT through the object API', () => {
		const tab = source('src/components/tabs/ThemeLinkTab.vue')

		expect(tab).toContain("import AddToThemeModal from '../../modals/AddToThemeModal.vue'")
		expect(tab).toContain("method: 'PUT'")
		expect(tab).toContain('withLink(theme, this.kind, String(this.objectId))')
		expect(source('src/modals/AddToThemeModal.vue')).toContain('<NcDialog')
		expect(tab).not.toContain('<NcDialog')
		expect(source('src/components/tabs/DecisionThemesTab.vue')).toContain('kind="decision"')
		expect(source('src/components/tabs/AgendaItemThemesTab.vue')).toContain('kind="agendaItem"')
	})

	it('publishes a theme as source type theme and shows unpublished entries marked', () => {
		expect(source('src/components/tabs/ThemePublicationTab.vue')).toContain('sourceType="theme"')
		const timeline = source('src/components/tabs/ThemeTimelineTab.vue')
		expect(timeline).toContain('timelineUrl(String(this.objectId))')
		expect(timeline).toContain('v-if="!row.published"')
	})
})
