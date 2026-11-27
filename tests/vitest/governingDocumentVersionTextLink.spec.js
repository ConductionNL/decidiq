/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-GDR-006: each row of the governing-document version timeline links to
 * that version's own page, which carries its consolidated text, and the
 * register's list can be filtered on the governing body.
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-006-governing-documents-list-and-detail-pages
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import {
	contentWithDefaults,
	detailObjectId,
	plainObject,
	sortVersionsByEffectiveDate,
} from '../../src/components/widgets/registerDetailWidgets.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const ROOT = path.resolve(__dirname, '../..')
const FILE = path.join(
	ROOT,
	'src/components/widgets/RegisterVersionTimelineWidget.vue',
)
const FRAGMENT = path.join(ROOT, 'src/manifest.d/governing-documents-register.json')

globalThis.t = (_app, text) => text

const MODULES = {
	'@conduction/nextcloud-vue': {
		CnIcon: {},
		CnNoteCard: {},
		CnStatusBadge: {},
		CnWidgetWrapper: {},
		useObjectStore: () => null,
	},
	'@nextcloud/axios': { default: { get: async () => ({ data: {} }) } },
	'@nextcloud/router': { generateUrl: (p) => p },
	'@nextcloud/vue': { NcButton: {} },
	'../../utils/governingDocumentCitations.js': {
		inForcePath: () => '',
		isCalendarDate: () => false,
	},
	'./registerDetailWidgets.js': {
		contentWithDefaults,
		detailObjectId,
		plainObject,
		resolveObjectLabel: async () => '',
		sortVersionsByEffectiveDate,
	},
}

/**
 * A widget instance with `content`, a recording router and its computed
 * properties live.
 *
 * @param {object} content The widget content.
 *
 * @return {{vm: object, pushed: Array<object>}} The instance and the router log.
 */
function widget(content) {
	const component = loadSfcScript(FILE, MODULES)
	const pushed = []
	const vm = instanceOf(component, {
		content,
		objectId: 'doc-1',
		register: '',
		schema: '',
		objectData: {},
		store: null,
	})
	vm.$router = { push: (to) => pushed.push(to) }
	for (const [name, fn] of Object.entries(component.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	return { vm, pushed }
}

describe('version timeline link to the consolidated text (REQ-GDR-006)', () => {
	it('opens the version page the content names', () => {
		const { vm, pushed } = widget({
			versionRoute: 'GoverningDocumentVersieDetail',
		})
		vm.openVersion('v-2')
		expect(pushed).toEqual([
			{ name: 'GoverningDocumentVersieDetail', params: { id: 'v-2' } },
		])
	})

	it('offers no link when no version page is configured', () => {
		const { vm, pushed } = widget({})
		expect(vm.cfg.versionRoute).toBe('')
		vm.openVersion('v-2')
		expect(pushed).toEqual([])
	})

	it('ignores a row without an id', () => {
		const { vm, pushed } = widget({ versionRoute: 'X' })
		vm.openVersion('')
		expect(pushed).toEqual([])
	})

	it('renders the link per row only when a version page is configured', () => {
		const sfc = fs.readFileSync(FILE, 'utf8')
		expect(sfc).toMatch(
			/v-if="cfg\.versionRoute && row\.id"[\s\S]*?data-testid="version-timeline-text-link"[\s\S]*?openVersion\(row\.id\)[\s\S]*?t\('decidiq', 'Consolidated text'\)/,
		)
	})

	it('points the governing-document timeline at the version page', () => {
		const fragment = JSON.parse(fs.readFileSync(FRAGMENT, 'utf8'))
		const detail = fragment.pages.find((p) => p.id === 'GoverningDocumentDetail')
		const timeline = detail.config.widgets.find(
			(w) => w.type === 'version-timeline',
		)
		const route = timeline.content.versionRoute
		expect(fragment.pages.map((p) => p.id)).toContain(route)
		const versionPage = fragment.pages.find((p) => p.id === route)
		expect(versionPage.config.schema).toBe(timeline.content.versionSchema)
		expect(
			versionPage.config.widgets.some((w) => w.integrationId === 'files'),
		).toBe(true)
	})
})

describe('governing body filter on the register list (REQ-GDR-006)', () => {
	it('keeps the governing body facetable and the filter sidebar on', () => {
		const dir = path.join(ROOT, 'lib/Settings/register.d')
		let property = null
		for (const file of fs
			.readdirSync(dir)
			.filter((name) => name.endsWith('.json'))
			.sort()) {
			const schemas =
				JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8')).components
					?.schemas || {}
			const own = schemas.GoverningDocument?.properties?.governingBody
			if (own) property = { ...property, ...own }
		}
		expect(property.facetable).toBe(true)

		const fragment = JSON.parse(fs.readFileSync(FRAGMENT, 'utf8'))
		const index = fragment.pages.find((p) => p.id === 'GoverningDocuments')
		expect(index.config.sidebar.enabled).toBe(true)
		expect(index.config.sidebar.fields).toBeUndefined()
		expect(index.config.columns.map((c) => c.key)).toContain('governingBody')
	})
})
