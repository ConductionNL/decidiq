/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the register-detail widgets' configuration merge (`cfg`),
 * their object-id accessor and the shared label resolver's fallback order,
 * pinned before they moved onto shared helpers.
 *
 * @spec exclude golden test of the widget config merge, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { resolveObjectLabel } from '../../src/components/widgets/registerDetailWidgets.js'
import * as registerDetailWidgets from '../../src/components/widgets/registerDetailWidgets.js'
import { loadSfcScript } from './helpers/sfcScript.js'

// The widgets translate at module level through the global t().
globalThis.t = (_app, text) => text

const WIDGETS = path.resolve(__dirname, '../../src/components/widgets')

const MODULES = {
	'@nextcloud/vue': { NcButton: {} },
	'@conduction/nextcloud-vue': {
		CnIcon: {},
		CnNoteCard: {},
		CnStatusBadge: {},
		CnWidgetWrapper: {},
		useObjectStore: () => null,
	},
	'vue-material-design-icons/AlertOutline.vue': { default: {} },
	'vue-material-design-icons/CheckCircleOutline.vue': { default: {} },
	'vue-material-design-icons/ClockOutline.vue': { default: {} },
	'vue-material-design-icons/ChevronRight.vue': { default: {} },
	'vue-material-design-icons/SourceBranch.vue': { default: {} },
	'./registerDetailWidgets.js': registerDetailWidgets,
}

/**
 * Evaluate one computed property of a widget for the given props.
 *
 * @param {string} file The widget file.
 * @param {object} props The prop values.
 *
 * @return {object} The computed values by name.
 */
function computedOf(file, props) {
	const component = loadSfcScript(path.join(WIDGETS, file), MODULES)
	const vm = { ...props }
	for (const [name, fn] of Object.entries(component.computed || {})) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	return vm
}

const FULL_VERSION_CONTENT = {
	versionRegister: 'vr',
	versionSchema: 'vs',
	parentRefField: 'parent',
	effectiveDateField: 'eff',
	lapseDateField: 'lapse',
	versionNumberField: 'num',
	statusField: 'st',
	decisionRefField: 'dec',
	decisionRoute: 'Route',
	extraFields: ['a'],
}

const FULL_DELEGATION_CONTENT = {
	register: 'r',
	schema: 's',
	parentRefField: 'p',
	decisionRefField: 'd',
	subjectField: 'sub',
	labelField: 'lab',
	detailRoute: 'Detail',
	decisionRoute: 'Dec',
}

describe('register widget cfg', () => {
	it('RegisterVersionTimelineWidget takes the content, then its defaults', () => {
		const file = 'RegisterVersionTimelineWidget.vue'
		expect(computedOf(file, { content: FULL_VERSION_CONTENT }).cfg).toEqual(
			FULL_VERSION_CONTENT,
		)
		expect(
			computedOf(file, { content: null, register: 'own' }).cfg,
		).toMatchSnapshot()
		expect(
			computedOf(file, { content: { extraFields: 'x', versionRegister: '' } })
				.cfg,
		).toMatchSnapshot()
	})

	it('DelegationChainWidget takes the content, then its props, then its defaults', () => {
		const file = 'DelegationChainWidget.vue'
		expect(computedOf(file, { content: FULL_DELEGATION_CONTENT }).cfg).toEqual(
			FULL_DELEGATION_CONTENT,
		)
		expect(
			computedOf(file, { content: undefined, register: 'pr', schema: 'ps' })
				.cfg,
		).toMatchSnapshot()
		expect(computedOf(file, { content: {} }).cfg).toMatchSnapshot()
	})

	it('ConfidentialityStatusTimelineWidget takes the content, then its defaults', () => {
		const file = 'ConfidentialityStatusTimelineWidget.vue'
		expect(
			computedOf(file, { content: { register: 'r', groundSchema: 'g' } }).cfg,
		).toEqual({ register: 'r', groundSchema: 'g' })
		expect(computedOf(file, { content: null, register: 'own' }).cfg).toEqual({
			register: 'own',
			groundSchema: 'geheimhouding-grond',
		})
		expect(computedOf(file, {}).cfg).toEqual({
			register: 'decidiq',
			groundSchema: 'geheimhouding-grond',
		})
	})
})

describe('register widget resolvedObjectId', () => {
	for (const file of [
		'RegisterVersionTimelineWidget.vue',
		'DelegationChainWidget.vue',
	]) {
		it(`${file} prefers the prop, then the data id, then the @self id`, () => {
			const id = (props) => computedOf(file, props).resolvedObjectId
			expect(id({ objectId: 'p', objectData: { id: 'd' } })).toBe('p')
			expect(
				id({ objectId: '', objectData: { id: 'd', '@self': { id: 's' } } }),
			).toBe('d')
			expect(id({ objectData: { '@self': { id: 's' } } })).toBe('s')
			expect(id({ objectData: 'not an object' })).toBe('')
			expect(id({ objectData: null })).toBe('')
			expect(id({ objectData: { '@self': null } })).toBe('')
		})
	}

	it('RegisterVersionTimelineWidget reads the slug from @self, then the data', () => {
		const slug = (objectData) =>
			computedOf('RegisterVersionTimelineWidget.vue', { objectData })
				.resolvedObjectSlug
		expect(slug({ slug: 'd', '@self': { slug: 's' } })).toBe('s')
		expect(slug({ slug: 'd' })).toBe('d')
		expect(slug(null)).toBe('')
	})
})

describe('resolveObjectLabel fallback order', () => {
	const storeAnswering = (obj, registry = { t: {} }) => ({
		objectTypeRegistry: registry,
		registered: [],
		registerObjectType(...args) {
			this.registered.push(args)
		},
		async fetchObject() {
			return obj
		},
	})

	it('takes the label field, then title, then name, then @self.name, skipping non-strings and blanks', async () => {
		const label = (obj, field) =>
			resolveObjectLabel(storeAnswering(obj), {
				typeSlug: 't',
				schemaSlug: 's',
				registerSlug: 'r',
				id: 'id-1',
				labelField: field,
			})
		expect(await label({ lab: 'L', title: 'T' }, 'lab')).toBe('L')
		expect(await label({ lab: '', title: 'T', name: 'N' }, 'lab')).toBe('T')
		expect(await label({ title: 5, name: 'N' }, 'lab')).toBe('N')
		expect(await label({ '@self': { name: 'S' } })).toBe('S')
		expect(await label({ name: '', '@self': {} })).toBe('id-1')
		expect(await label({ title: 'T' })).toBe('T')
	})

	it('registers the type only when the store has no registry entry for it', async () => {
		const known = storeAnswering({ name: 'N' })
		await resolveObjectLabel(known, {
			typeSlug: 't',
			schemaSlug: 's',
			registerSlug: 'r',
			id: 'x',
		})
		expect(known.registered).toEqual([])
		const noRegistry = storeAnswering({ name: 'N' }, null)
		await resolveObjectLabel(noRegistry, {
			typeSlug: 't',
			schemaSlug: 's',
			registerSlug: 'r',
			id: 'x',
		})
		expect(noRegistry.registered).toEqual([['t', 's', 'r']])
	})

	it('stringifies a numeric id on every fallback', async () => {
		expect(
			await resolveObjectLabel(null, {
				typeSlug: 't',
				schemaSlug: 's',
				registerSlug: 'r',
				id: 7,
			}),
		).toBe('7')
		expect(
			await resolveObjectLabel(storeAnswering(null), {
				typeSlug: 't',
				schemaSlug: 's',
				registerSlug: 'r',
				id: 7,
			}),
		).toBe('7')
		expect(
			await resolveObjectLabel(null, {
				typeSlug: 't',
				schemaSlug: 's',
				registerSlug: 'r',
				id: 0,
			}),
		).toBe('')
	})
})
