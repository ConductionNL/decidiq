/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decision page and the motion page in the simple structure.
 *
 * Both pages get their simple shape from an overlay in
 * `src/menu-layout.simple.json`. An overlay is names: a stage, an action id, a
 * field, a widget id, a registry component. A name that names nothing changes
 * nothing and says nothing, so every name is checked here against the thing it
 * names: the schema, the server's transition map, the routes, the manifest's
 * widgets, the registry and the icon set.
 *
 * The library's own resolvers (`stageOf`, `resolveNextStep`, `resolvePill`,
 * `evaluateVisibleWhenLocal`) answer the questions about stages and
 * visibility, so this file does not hold a second opinion on how they work.
 *
 * @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import {
	resolveNextStep,
	resolvePill,
	stageEntry,
	stageOf,
} from '@conduction/nextcloud-vue/src/utils/detailActionModel.js'
import { LIBRARY_WIDGET_KEYS } from '@conduction/nextcloud-vue/src/utils/libraryWidgetKeys.js'
import { validateManifest } from '@conduction/nextcloud-vue/src/utils/validateManifest.js'
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	buildTimeline,
	STATE_LABELS,
	STATES,
} from '../../src/components/tabs/decisionLifecycle.js'
import { unfittedWidget } from '../../src/components/widgets/unfittedWidget.js'
import {
	buildProfiledManifest,
	inlineSectionWidgets,
} from '../../src/utils/structureProfile.js'

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

const simple = build('menu-layout.simple.json')
const full = build('menu-layout.json')
const pageOf = (built, id) => built.pages.find((page) => page.id === id)
const iconsSource = read('src', 'icons.js')
const registrySource = read('src', 'registry.js')
const routesSource = read('appinfo', 'routes.php')
const dutch = readJson('l10n', 'nl.json').translations

/**
 * The decision schema as an instance gets it: the base register plus every
 * fragment that adds to it.
 *
 * @return {{properties: object}} The merged properties.
 */
function decisionSchema() {
	const base = readJson('lib', 'Settings', 'decidesk_register.json')
	const properties = { ...base.components.schemas.Decision.properties }
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const name of fs.readdirSync(dir).sort()) {
		if (!name.endsWith('.json')) continue
		const schemas =
			JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')).components
				?.schemas ?? {}
		for (const [key, schema] of Object.entries(schemas)) {
			if (key.toLowerCase() === 'decision' || schema?.slug === 'decision') {
				// A fragment adds to a property it names; it does not replace it.
				for (const [name, added] of Object.entries(
					schema.properties ?? {},
				)) {
					properties[name] = { ...(properties[name] ?? {}), ...added }
				}
			}
		}
	}
	return { properties }
}
const schema = decisionSchema()
const lifecycleStates = readJson('lib', 'Settings', 'decidesk_register.json')
	.components.schemas.Decision.properties.lifecycle.enum

/**
 * The server's transition map, read from the guard that enforces it.
 *
 * @return {Record<string, {from: string[], to: string}>} action -> edge.
 */
function serverTransitions() {
	const source = read('lib', 'Lifecycle', 'DecisionTransitionGuard.php')
	const block = source.slice(
		source.indexOf('private const TRANSITIONS = ['),
		source.indexOf('];', source.indexOf('private const TRANSITIONS = [')),
	)
	const out = {}
	const row =
		/'(\w+)'\s*=>\s*\['from'\s*=>\s*\[([^\]]*)\],\s*'to'\s*=>\s*'(\w+)'\]/g
	let match
	while ((match = row.exec(block)) !== null) {
		out[match[1]] = {
			from: match[2].split(',').map((part) => part.trim().replace(/'/g, '')),
			to: match[3],
		}
	}
	return out
}

/**
 * Every text an overlay shows a reader.
 *
 * @param {unknown} node A piece of the overlay.
 * @param {Set<string>} into Collected texts.
 * @return {Set<string>} The texts.
 */
function texts(node, into = new Set()) {
	const textKeys = [
		'label',
		'title',
		'after',
		'group',
		'moreLabel',
		'successMessage',
		'ariaLabel',
	]
	if (Array.isArray(node)) {
		node.forEach((item) => texts(item, into))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (key.startsWith('_')) continue
			if (textKeys.includes(key) && typeof value === 'string') into.add(value)
			if (key === 'labels' && value && typeof value === 'object') {
				Object.values(value).forEach((text) => into.add(text))
			}
			texts(value, into)
		}
	}
	return into
}

/**
 * Whether a route with this url and verb is declared.
 *
 * @param {string} url The action's url, with `@objectId` for the id.
 * @return {boolean} True when appinfo/routes.php declares a POST on it.
 */
function postRouteExists(url) {
	// The id placeholder has its own name per route (`{id}`, `{decisionId}`).
	const appPath = url
		.replace('/apps/decidiq', '')
		.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
		.replace('@objectId', '\\{\\w+\\}')
	return new RegExp(`'url' => '${appPath}',\\s*'verb' => 'POST'`).test(
		routesSource,
	)
}

describe('the full structure', () => {
	it('shows the decision page and the motion page exactly as the manifest declares them', () => {
		const declared = readJson('src', 'manifest.json').pages
		for (const id of ['DecisionDetail', 'MotionDetail']) {
			expect(pageOf(full, id), id).toEqual(
				declared.find((page) => page.id === id),
			)
		}
	})
})

describe('the decision page in the simple structure', () => {
	const page = pageOf(simple, 'DecisionDetail')
	const config = page.config
	const actions = new Map(config.headerActions.map((item) => [item.id, item]))
	const widgets = new Map(config.widgets.map((item) => [item.id, item]))
	const transitions = serverTransitions()

	it('reads the stage from the lifecycle field, which the schema has', () => {
		expect(config.stageField).toBe('lifecycle')
		expect(schema.properties.lifecycle).toBeDefined()
		expect(stageOf({ lifecycle: 'voting' }, config.stageField)).toBe('voting')
		// A record without a state has no stage, so no button and no card.
		expect(stageOf({}, config.stageField)).toBe('')
		expect(resolveNextStep(config.nextStep, {}, config.stageField)).toBeNull()
	})

	it('has one primary button per state, each a real state and a declared action', () => {
		expect(Object.keys(config.primaryActionByStage)).toEqual([
			'draft',
			'proposed',
			'deliberating',
			'voting',
			'decided',
			'enacted',
		])
		for (const [stage, id] of Object.entries(config.primaryActionByStage)) {
			expect(lifecycleStates, stage).toContain(stage)
			expect(actions.has(id), `${stage} -> ${id}`).toBe(true)
			expect(stageEntry(config.primaryActionByStage, stage)).toBe(id)
		}
		// Archived and withdrawn are where a decision ends: no next step.
		expect(config.primaryActionByStage.archived).toBeUndefined()
		expect(config.primaryActionByStage.withdrawn).toBeUndefined()
	})

	it('shows the primary button in its own state and in no other', () => {
		for (const [stage, id] of Object.entries(config.primaryActionByStage)) {
			const action = actions.get(id)
			for (const state of lifecycleStates) {
				const record = { lifecycle: state, isPublished: 'internal' }
				expect(
					evaluateVisibleWhenLocal(action.visibleWhen, record),
					`${id} on a ${state} decision`,
				).toBe(state === stage)
			}
		}
	})

	it('moves a decision through the guarded endpoint, with an action the server knows, from a state the server allows', () => {
		const moves = config.headerActions.filter((item) =>
			item.url?.endsWith('/transition'),
		)
		expect(moves.map((item) => item.payload.action)).toEqual([
			'propose',
			'deliberate',
			'openVoting',
			'decide',
			'enact',
			'archive',
		])
		// Every transition the server has is offered, so the header and the
		// Lifecycle block cannot disagree on what exists.
		expect(moves.map((item) => item.payload.action).sort()).toEqual(
			Object.keys(transitions).sort(),
		)
		for (const move of moves) {
			expect(move.type).toBe('api-call')
			expect(move.method).toBe('POST')
			expect(move.url).toBe('/apps/decidiq/api/decisions/@objectId/transition')
			expect(postRouteExists(move.url), move.url).toBe(true)
			const edge = transitions[move.payload.action]
			for (const state of lifecycleStates) {
				if (
					evaluateVisibleWhenLocal(move.visibleWhen, { lifecycle: state })
				) {
					expect(edge.from, `${move.id} offered on ${state}`).toContain(
						state,
					)
				}
			}
		}
	})

	it('sends no field that would let the page decide who may move a decision', () => {
		// The server reads the action and, optionally, a comment. The chair
		// check and the quorum check are its own: nothing here names a role.
		for (const item of config.headerActions) {
			expect(item.adminOnly, item.id).toBeUndefined()
			expect(item.permission, item.id).toBeUndefined()
			if (item.url?.endsWith('/transition')) {
				expect(Object.keys(item.payload)).toEqual(['action'])
			}
		}
	})

	it('offers Publish on an enacted decision that is not public yet, through the publication endpoint', () => {
		const publish = actions.get('decision-publish')
		expect(publish.url).toBe('/apps/decidiq/api/publications')
		expect(postRouteExists(publish.url)).toBe(true)
		expect(publish.payload).toEqual({
			sourceType: 'decision',
			sourceId: '@objectId',
		})
		expect(publish.confirm).toBe(true)
		const visible = (record) =>
			evaluateVisibleWhenLocal(publish.visibleWhen, record)
		expect(visible({ lifecycle: 'enacted', isPublished: 'internal' })).toBe(true)
		expect(visible({ lifecycle: 'enacted', isPublished: 'public' })).toBe(false)
		expect(visible({ lifecycle: 'decided', isPublished: 'internal' })).toBe(
			false,
		)
		expect(schema.properties.isPublished.enum).toContain('public')
	})

	it('asks before the steps that cannot be taken back', () => {
		for (const id of [
			'decision-open-voting',
			'decision-decide',
			'decision-publish',
			'decision-archive',
		]) {
			expect(actions.get(id).confirm, id).toBe(true)
		}
	})

	it('never shows a what-now card without a button that moves the decision', () => {
		// Lesson of the dossiq live check: a finished checklist with nothing
		// to press. Publish hides once a decision is public, so Archive is
		// pinned beside it. For every state with a card, and every
		// publication state, the primary button or a pinned transition shows.
		const pinned = [
			...new Set([
				...Object.values(config.primaryActionByStage),
				...config.quickActions,
			]),
		]
			.map((id) => actions.get(id))
			.filter((item) => item.type === 'api-call')
		for (const stage of Object.keys(config.nextStep.stages)) {
			for (const isPublished of schema.properties.isPublished.enum) {
				const record = { lifecycle: stage, isPublished }
				expect(
					pinned.some((item) =>
						evaluateVisibleWhenLocal(item.visibleWhen, record),
					),
					`${stage}, ${isPublished}`,
				).toBe(true)
			}
		}
		// The control: without Archive, a public enacted decision has none.
		expect(
			pinned
				.filter((item) => item.id !== 'decision-archive')
				.some((item) =>
					evaluateVisibleWhenLocal(item.visibleWhen, {
						lifecycle: 'enacted',
						isPublished: 'public',
					}),
				),
		).toBe(false)
	})

	it('pins quick actions that are declared, at most three', () => {
		expect(config.quickActions.length).toBeLessThanOrEqual(3)
		for (const id of config.quickActions) {
			expect(actions.has(id), id).toBe(true)
		}
		const integrations = actions.get('decision-open-integrations')
		expect(integrations.type).toBe('navigate')
		expect(integrations.target).toBe('/decisions/@objectId/integrations')
		expect(pageOf(simple, 'DecisionIntegrations').route).toBe(
			'/decisions/:id/integrations',
		)
	})

	it('gives every state with a button a what-now card whose checklist reads real fields', () => {
		for (const stage of Object.keys(config.primaryActionByStage)) {
			const card = resolveNextStep(
				config.nextStep,
				{ lifecycle: stage },
				config.stageField,
			)
			expect(card, stage).not.toBeNull()
			expect(card.title, stage).toMatch(/^What now\? /)
			expect(card.items.length, stage).toBeGreaterThan(0)
			// Nothing is done on an empty record: no item is ticked by default.
			expect(
				card.items.every((item) => item.done === false),
				stage,
			).toBe(true)
		}
		for (const [stage, declared] of Object.entries(config.nextStep.stages)) {
			expect(lifecycleStates, stage).toContain(stage)
			for (const item of declared.checklist) {
				const field = item.doneField ?? item.doneWhen?.field
				expect(schema.properties[field], `${stage}: ${field}`).toBeDefined()
			}
		}
		// And an item is ticked once its field is filled.
		const filled = resolveNextStep(
			config.nextStep,
			{ lifecycle: 'draft', text: 'x', proposer: 'y', legalBasis: 'z' },
			config.stageField,
		)
		expect(filled.items.every((item) => item.done)).toBe(true)
	})

	it('names the state in the pill with the words the step bar uses', () => {
		expect(config.statusPill.field).toBe('lifecycle')
		expect(Object.keys(config.statusPill.labels).sort()).toEqual(
			[...lifecycleStates].sort(),
		)
		expect(config.statusPill.labels).toEqual(STATE_LABELS)
		expect(
			resolvePill(config.statusPill, { lifecycle: 'voting' }),
		).toMatchObject({ label: 'Voting', colorKey: 'voting' })
		expect(resolvePill(config.statusPill, {})).toBeNull()
	})

	it('draws seven steps and marks the one the decision is on', () => {
		expect(STATES).toHaveLength(7)
		for (const state of STATES) {
			expect(lifecycleStates).toContain(state)
			expect(dutch[STATE_LABELS[state]], state).toBeTruthy()
		}
		const steps = buildTimeline('voting')
		expect(steps.filter((step) => step.status === 'current')).toEqual([
			{ state: 'voting', status: 'current' },
		])
		expect(steps.filter((step) => step.status === 'done')).toHaveLength(3)
		// A withdrawn decision is on none of them.
		expect(
			buildTimeline('withdrawn').every((step) => step.status === 'upcoming'),
		).toBe(true)
		expect(widgets.get('decision-steps').type).toBe('DecisionStepBar')
		expect(registrySource).toContain('DecisionStepBar: page(DecisionStepBar)')
	})

	it('shows facts in the side column, from fields the schema has, and nothing to edit', () => {
		expect(config.sideColumn.map((card) => card.title)).toEqual([
			'Where it is decided',
			'Dates',
		])
		for (const card of config.sideColumn) {
			expect(card.type).toBe('data')
			expect(card.content.editable).toBe(false)
			for (const field of card.content.include) {
				expect(schema.properties[field], field).toBeDefined()
			}
		}
	})

	it('puts the blocks behind five tabs and More', () => {
		const strip = widgets.get('decision-panels')
		expect(strip.type).toBe('tabs')
		const tabs = strip.content.tabs
		expect(strip.content.maxVisibleTabs).toBe(5)
		expect(tabs.filter((tab) => !tab.overflow).map((tab) => tab.label)).toEqual([
			'Content',
			'Route and voting',
			'Documents',
			'Consultation',
			'Publication',
		])
		expect(tabs.filter((tab) => tab.overflow).map((tab) => tab.label)).toEqual([
			'Action items',
			'Commitments',
			'Related decisions',
			'Lifecycle',
		])
		expect(
			tabs.filter((tab) => !tab.overflow).map((tab) => dutch[tab.label]),
		).toEqual([
			'Inhoud',
			'Route en stemming',
			'Documenten',
			'Consultatie',
			'Publicatie',
		])
		for (const tab of tabs) {
			expect(widgets.has(tab.widgetId), tab.widgetId).toBe(true)
		}
	})

	it('loses no block: every widget the manifest declares is in a tab or in a section of one', () => {
		const declared = pageOf(full, 'DecisionDetail').config.widgets.map(
			(item) => item.id,
		)
		expect(declared).toHaveLength(15)
		const tabs = widgets.get('decision-panels').content.tabs
		const shown = new Set()
		for (const tab of tabs) {
			const panel = widgets.get(tab.widgetId)
			if (panel.type === 'detail-sections') {
				for (const section of panel.content.sections) {
					shown.add(section.widget.id)
				}
			} else {
				shown.add(panel.id)
			}
		}
		expect(declared.filter((id) => !shown.has(id))).toEqual([])
		// No block is shown twice either.
		const all = tabs.flatMap((tab) => {
			const panel = widgets.get(tab.widgetId)
			return panel.type === 'detail-sections'
				? panel.content.sections.map((section) => section.widget.id)
				: [panel.id]
		})
		expect(new Set(all).size).toBe(all.length)
	})

	it('writes the definition into every section, and it is the one the page declares', () => {
		for (const panel of config.widgets.filter(
			(item) => item.type === 'detail-sections',
		)) {
			for (const section of panel.content.sections) {
				expect(section.widgetId, panel.id).toBeUndefined()
				expect(section.widget, `${panel.id}: ${section.label}`).toBe(
					widgets.get(section.widget.id),
				)
				expect(dutch[section.label], section.label).toBeTruthy()
			}
		}
	})

	it('gives every block in a tab a type a tab can resolve', () => {
		// A tab resolves a widget by its type: the app registry first, then
		// the library's catalog. A `custom` widget resolves through the page's
		// slot map only, which a tab does not read, so it would render nothing.
		const inTabs = widgets.get('decision-panels').content.tabs.flatMap((tab) => {
			const panel = widgets.get(tab.widgetId)
			return panel.type === 'detail-sections'
				? [panel, ...panel.content.sections.map((section) => section.widget)]
				: [panel]
		})
		for (const widget of inTabs) {
			expect(widget.type, widget.id).not.toBe('custom')
			const resolves =
				widget.type === 'detail-sections'
				|| LIBRARY_WIDGET_KEYS.includes(widget.type)
				|| new RegExp(`\\n\\t${widget.type}: page\\(`).test(registrySource)
			expect(resolves, `${widget.id}: ${widget.type}`).toBe(true)
		}
		// The six custom blocks are named by the component the slot map gives
		// them in the full structure, so both structures render the same one.
		const slots = pageOf(full, 'DecisionDetail').slots
		for (const widget of pageOf(full, 'DecisionDetail').config.widgets) {
			if (widget.type === 'custom') {
				expect(widgets.get(widget.id).type, widget.id).toBe(
					slots[`widget-${widget.id}`],
				)
			}
		}
		expect(
			read('src', 'components', 'widgets', 'registerDetailWidgets.js'),
		).toContain("registerDashboardWidget('detail-sections', {")
	})

	it('lays out the step bar and the tabs, and nothing a tab already shows', () => {
		expect(config.layout.map((item) => item.widgetId)).toEqual([
			'decision-steps',
			'decision-panels',
		])
		for (const item of config.layout) {
			expect(widgets.has(item.widgetId), item.widgetId).toBe(true)
			expect(item.gridX + item.gridWidth).toBeLessThanOrEqual(12)
		}
	})

	it('keeps the Lifecycle block on the page, under More', () => {
		// The block shows every transition the server offers this person, the
		// chair-only mark, and the publish prompt of a body that asks for one.
		const tabs = widgets.get('decision-panels').content.tabs
		const lifecycle = tabs.find((tab) => tab.widgetId === 'decision-lifecycle')
		expect(lifecycle.overflow).toBe(true)
		expect(widgets.get('decision-lifecycle').type).toBe('DecisionLifecycleTab')
	})
})

describe('the motion page in the simple structure', () => {
	const page = pageOf(simple, 'MotionDetail')
	const config = page.config
	const actions = new Map(config.headerActions.map((item) => [item.id, item]))

	it('keeps every block and its place', () => {
		const before = pageOf(full, 'MotionDetail').config
		expect(config.widgets).toEqual(before.widgets)
		expect(config.layout).toEqual(before.layout)
	})

	it('has one primary button per state, shown in that state only', () => {
		for (const [stage, id] of Object.entries(config.primaryActionByStage)) {
			expect(lifecycleStates, stage).toContain(stage)
			const action = actions.get(id)
			expect(action, id).toBeDefined()
			for (const state of lifecycleStates) {
				expect(
					evaluateVisibleWhenLocal(action.visibleWhen, {
						lifecycle: state,
						outcome: 'adopted',
					}),
					`${id} on a ${state} motion`,
				).toBe(state === stage)
			}
		}
	})

	it('moves a motion through the endpoint the Stage block uses, with the words that block sends', () => {
		const stageTab = read('src', 'components', 'tabs', 'MotionStageTab.vue')
		expect(stageTab).toContain('newState: action.to')
		expect(stageTab).toContain('outcome: action.outcome || null')
		const steps = read('src', 'utils', 'motionStages.js')
		const moves = config.headerActions.filter((item) =>
			item.url?.endsWith('/transition'),
		)
		expect(moves).toHaveLength(6)
		for (const move of moves) {
			expect(move.url).toBe('/apps/decidiq/api/motions/@objectId/transition')
			expect(postRouteExists(move.url)).toBe(true)
			expect(lifecycleStates).toContain(move.payload.newState)
			// The step is one the Stage block knows.
			const key = move.payload.outcome
				? `${move.payload.newState}-${move.payload.outcome}`
				: move.payload.newState
			expect(steps, key).toContain(`'${key}',`)
			// And it carries the label the Stage block gives that step.
			expect(stageTab, move.label).toContain(`'${move.label}'`)
		}
	})

	it('offers both ways out of a vote: adopted as the button, rejected beside it', () => {
		expect(config.primaryActionByStage.voting).toBe('motion-adopt')
		expect(config.quickActions).toContain('motion-reject')
		expect(actions.get('motion-adopt').payload).toEqual({
			newState: 'decided',
			outcome: 'adopted',
		})
		expect(actions.get('motion-reject').payload).toEqual({
			newState: 'decided',
			outcome: 'rejected',
		})
		expect(schema.properties.outcome.enum).toEqual(['adopted', 'rejected'])
		for (const state of lifecycleStates) {
			expect(
				evaluateVisibleWhenLocal(actions.get('motion-reject').visibleWhen, {
					lifecycle: state,
				}),
				state,
			).toBe(state === 'voting')
		}
	})

	it('offers carrying out for an adopted motion only', () => {
		const carryOut = actions.get('motion-carry-out')
		const visible = (record) =>
			evaluateVisibleWhenLocal(carryOut.visibleWhen, record)
		expect(visible({ lifecycle: 'decided', outcome: 'adopted' })).toBe(true)
		expect(visible({ lifecycle: 'decided', outcome: 'rejected' })).toBe(false)
	})

	it('reads real fields in its checklists', () => {
		for (const [stage, declared] of Object.entries(config.nextStep.stages)) {
			expect(lifecycleStates, stage).toContain(stage)
			for (const item of declared.checklist) {
				expect(
					schema.properties[item.doneField],
					item.doneField,
				).toBeDefined()
			}
		}
	})
})

describe('both pages', () => {
	const overlays = readJson('src', 'menu-layout.simple.json').pages.filter(
		(overlay) => ['DecisionDetail', 'MotionDetail'].includes(overlay.id),
	)

	it('patch and append only things that exist', () => {
		for (const overlay of overlays) {
			const declared = pageOf(full, overlay.id).config
			for (const id of Object.keys(overlay.configPatch?.widgets ?? {})) {
				expect(
					declared.widgets.some((widget) => widget.id === id),
					`${overlay.id}: ${id}`,
				).toBe(true)
			}
			const ids = pageOf(simple, overlay.id).config.headerActions.map(
				(item) => item.id,
			)
			expect(new Set(ids).size, overlay.id).toBe(ids.length)
		}
	})

	it('use icons the app registers and texts that read in Dutch', () => {
		for (const overlay of overlays) {
			const icons = new Set()
			JSON.stringify(overlay, (key, value) => {
				if (key === 'icon' && typeof value === 'string') icons.add(value)
				return value
			})
			for (const icon of icons) {
				expect(iconsSource, icon).toContain(`\n\t${icon},\n`)
			}
			for (const text of texts(overlay)) {
				expect(dutch[text], text).toBeTruthy()
			}
		}
	})

	it('build into a manifest the library accepts', () => {
		const result = validateManifest(JSON.parse(JSON.stringify(simple)))
		expect(result.errors).toEqual([])
		expect(result.valid).toBe(true)
	})
})

describe('a sections panel', () => {
	it('gets the named widget written in, and leaves an unknown name alone', () => {
		const route = { id: 'route', type: 'Route' }
		const panel = {
			id: 'panel',
			type: 'detail-sections',
			content: {
				sections: [
					{ label: 'Route', widgetId: 'route' },
					{ label: 'Gone', widgetId: 'gone' },
					{ label: 'Inline', widget: { id: 'x', type: 'data' } },
				],
			},
		}
		const [first, second] = inlineSectionWidgets([route, panel])
		expect(first).toBe(route)
		expect(second.content.sections).toEqual([
			{ label: 'Route', widget: route },
			{ label: 'Gone', widgetId: 'gone' },
			{ label: 'Inline', widget: { id: 'x', type: 'data' } },
		])
		// The original is not changed.
		expect(panel.content.sections[0]).toEqual({
			label: 'Route',
			widgetId: 'route',
		})
	})

	it('lets a stacked list render all its rows, and keeps a fit that was chosen', () => {
		const list = { id: 'l', type: 'object-list', content: { limit: 10 } }
		expect(unfittedWidget(list).content).toEqual({ limit: 10, fit: false })
		expect(list.content).toEqual({ limit: 10 })
		const chosen = { id: 'l', type: 'object-list', content: { fit: true } }
		expect(unfittedWidget(chosen)).toBe(chosen)
	})
})
