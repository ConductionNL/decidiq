/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The amendment page and My actions in the simple structure.
 *
 * Both get their simple shape from an overlay in
 * `src/menu-layout.simple.json`. Every name in the overlays is checked here
 * against the thing it names: the schema, the server's transition table, the
 * routes, the manifest's widgets, the registry and the icon set. The
 * library's own resolvers answer the questions about stages, visibility and
 * filters, so this file holds no second opinion on how they work.
 *
 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md
 * @spec openspec/changes/simple-amendment-page/specs/app-navigation/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import {
	MAX_QUICK_ACTIONS,
	resolveNextStep,
	resolvePill,
	stageEntry,
	stageOf,
} from '@conduction/nextcloud-vue/src/utils/detailActionModel.js'
import { fetchFilterCounts } from '@conduction/nextcloud-vue/src/utils/fetchFilterCounts.js'
import { buildQueryString } from '@conduction/nextcloud-vue/src/utils/headers.js'
import { LIBRARY_WIDGET_KEYS } from '@conduction/nextcloud-vue/src/utils/libraryWidgetKeys.js'
import { resolveFilterMap } from '@conduction/nextcloud-vue/src/utils/routeFilters.js'
import { validateManifest } from '@conduction/nextcloud-vue/src/utils/validateManifest.js'
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { STATE_LABELS } from '../../src/components/tabs/decisionLifecycle.js'
import {
	AMENDMENT_HEADER_STEPS,
	AMENDMENT_STEPS,
	AMENDMENT_VOTE_STEPS,
	amendmentNote,
	buildAmendmentSteps,
} from '../../src/utils/amendmentSteps.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import { getCurrentUser } from './stubs/nextcloud-auth.js'

/** Every request the count of a view makes, as the library hands it to axios. */
const asked = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url, options) => {
			asked.push({ url, params: options?.params ?? {} })
			return { data: { value: 7 } }
		},
	},
}))

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
 * A schema as an instance gets it: the base register plus every fragment
 * that adds to it.
 *
 * @param {string} name The schema's key in the base register.
 * @param {string} slug Its slug.
 * @return {{properties: object}} The merged properties.
 */
function schemaOf(name, slug) {
	const base = readJson('lib', 'Settings', 'decidesk_register.json')
	// A schema a fragment adds (Commitment) has no entry in the base register.
	const properties = { ...(base.components.schemas[name]?.properties ?? {}) }
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const file of fs.readdirSync(dir).sort()) {
		if (!file.endsWith('.json')) continue
		const schemas =
			JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8')).components
				?.schemas ?? {}
		for (const [key, schema] of Object.entries(schemas)) {
			if (key.toLowerCase() === name.toLowerCase() || schema?.slug === slug) {
				for (const [field, added] of Object.entries(
					schema.properties ?? {},
				)) {
					properties[field] = { ...(properties[field] ?? {}), ...added }
				}
			}
		}
	}
	return { properties }
}
const decision = schemaOf('Decision', 'decision')
const actionItem = schemaOf('ActionItem', 'action-item')
const lifecycleStates = decision.properties.lifecycle.enum

/**
 * The road of an amendment, read from the class that enforces it.
 *
 * @return {Record<string, string[]>} state -> the states it may move to.
 */
function serverTransitions() {
	const source = read('lib', 'Lifecycle', 'MotionLifecycleTransitioner.php')
	const start = source.indexOf('public const AMENDMENT_TRANSITIONS = [')
	const block = source.slice(start, source.indexOf('];', start))
	const out = {}
	const row = /'(\w+)'\s*=>\s*\[([^\]]*)\]/g
	let match
	while ((match = row.exec(block)) !== null) {
		out[match[1]] = match[2]
			.split(',')
			.map((part) => part.trim().replace(/'/g, ''))
			.filter(Boolean)
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
		'hint',
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
 * The blocks a tab shows: its own widget, or the widgets of its sections.
 *
 * @param {Map<string, object>} widgets The page's widgets by id.
 * @param {object} tab One tab of the strip.
 * @return {Array<object>} The widgets.
 */
function blocksOf(widgets, tab) {
	const panel = widgets.get(tab.widgetId)
	return panel.type === 'detail-sections'
		? panel.content.sections.map((section) => section.widget)
		: [panel]
}

describe('the full structure', () => {
	it('shows the amendment page and the action items list exactly as the manifest declares them', () => {
		const declared = readJson('src', 'manifest.json').pages
		for (const id of ['AmendmentDetail', 'ActionItems']) {
			expect(pageOf(full, id), id).toEqual(
				declared.find((page) => page.id === id),
			)
		}
		expect(pageOf(full, 'ActionItems').config.quickFilters).toBeUndefined()
		expect(pageOf(full, 'AmendmentDetail').config.headerActions).toBeUndefined()
	})
})

describe('the amendment page in the simple structure', () => {
	const page = pageOf(simple, 'AmendmentDetail')
	const config = page.config
	const actions = new Map(config.headerActions.map((item) => [item.id, item]))
	const widgets = new Map(config.widgets.map((item) => [item.id, item]))
	const transitions = serverTransitions()
	const moves = config.headerActions.filter((item) =>
		item.url?.endsWith('/transition'),
	)

	it('reads the server table it is compared with', () => {
		// The control for every comparison below: a regex that matched nothing
		// would make them all pass.
		expect(transitions).toEqual({
			draft: ['proposed'],
			proposed: ['deliberating'],
			deliberating: ['voting'],
			voting: ['decided'],
			decided: [],
		})
	})

	it('reads the stage from the lifecycle field, which the schema has', () => {
		expect(config.stageField).toBe('lifecycle')
		expect(stageOf({ lifecycle: 'proposed' }, config.stageField)).toBe(
			'proposed',
		)
		expect(stageOf({}, config.stageField)).toBe('')
		expect(resolveNextStep(config.nextStep, {}, config.stageField)).toBeNull()
	})

	it('has a primary button for the two steps before the vote, shown in its own state and no other', () => {
		expect(Object.keys(config.primaryActionByStage)).toEqual(
			AMENDMENT_HEADER_STEPS,
		)
		for (const [stage, id] of Object.entries(config.primaryActionByStage)) {
			expect(actions.has(id), `${stage} -> ${id}`).toBe(true)
			expect(stageEntry(config.primaryActionByStage, stage)).toBe(id)
			for (const state of lifecycleStates) {
				expect(
					evaluateVisibleWhenLocal(actions.get(id).visibleWhen, {
						lifecycle: state,
					}),
					`${id} on a ${state} amendment`,
				).toBe(state === stage)
			}
		}
	})

	it('moves an amendment through the guarded endpoint, to the state the server allows from that state', () => {
		expect(moves).toHaveLength(2)
		expect(routesSource).toContain(
			"['name' => 'motion#amendmentTransition', 'url' => '/api/amendments/{id}/transition', 'verb' => 'POST']",
		)
		for (const move of moves) {
			expect(move.type).toBe('api-call')
			expect(move.method).toBe('POST')
			expect(move.url).toBe(
				'/apps/decidiq/api/amendments/@objectId/transition',
			)
			// The body the controller reads: `newState`, and `outcome` only on
			// a vote, which this page does not record.
			expect(Object.keys(move.payload), move.id).toEqual(['newState'])
			const offeredIn = lifecycleStates.filter((state) =>
				evaluateVisibleWhenLocal(move.visibleWhen, { lifecycle: state }),
			)
			expect(offeredIn, move.id).toHaveLength(1)
			expect(transitions[offeredIn[0]], move.id).toEqual([
				move.payload.newState,
			])
		}
		const controller = read('lib', 'Controller', 'MotionController.php')
		const method = controller.slice(
			controller.indexOf('public function amendmentTransition('),
			controller.indexOf('}//end amendmentTransition()'),
		)
		expect(method).toContain("$newState = ($params['newState'] ?? '');")
	})

	it('names no role and leaves the check to the server', () => {
		for (const item of config.headerActions) {
			expect(item.adminOnly, item.id).toBeUndefined()
			expect(item.permission, item.id).toBeUndefined()
		}
		const controller = read('lib', 'Controller', 'MotionController.php')
		const method = controller.slice(
			controller.indexOf('public function amendmentTransition('),
			controller.indexOf('}//end amendmentTransition()'),
		)
		expect(method).toContain('$guard = $this->requireChairOrSecretary();')
	})

	it('leaves the vote to the Voting round block, where the server holds the order and writes the text', () => {
		const offered = moves.map((item) => item.payload.newState)
		expect(offered).toEqual(['proposed', 'deliberating'])
		for (const state of ['voting', 'decided']) {
			expect(offered).not.toContain(state)
		}
		for (const item of config.headerActions) {
			expect(JSON.stringify(item.payload ?? {}), item.id).not.toContain(
				'outcome',
			)
		}
		// What a header button on the plain endpoint would skip.
		const opener = read('lib', 'Service', 'VotingRoundOpener.php')
		expect(opener).toContain('$this->amendmentOrder->assertOrdering(')
		const closer = read('lib', 'Service', 'VotingRoundCloser.php')
		expect(closer).toContain('$this->incorporateAdoptedAmendment(')
		// And the block that takes those steps is a tab of its own.
		const tab = widgets
			.get('amend-panels')
			.content.tabs.find((item) => item.widgetId === 'amend-voting-round')
		expect(tab.overflow).toBeUndefined()
		expect(widgets.get('amend-voting-round').type).toBe(
			'AmendmentVotingRoundTab',
		)
	})

	it('never shows a what-now card without a button that moves the amendment', () => {
		const stages = Object.keys(config.nextStep.stages)
		expect(stages).toEqual(AMENDMENT_HEADER_STEPS)
		for (const stage of stages) {
			const primary = actions.get(config.primaryActionByStage[stage])
			expect(primary.type).toBe('api-call')
			expect(
				evaluateVisibleWhenLocal(primary.visibleWhen, { lifecycle: stage }),
				stage,
			).toBe(true)
		}
		for (const stage of [...AMENDMENT_VOTE_STEPS, 'decided']) {
			expect(
				resolveNextStep(config.nextStep, { lifecycle: stage }, 'lifecycle'),
				stage,
			).toBeNull()
		}
	})

	it('gives each card a checklist that reads real fields, unticked on an empty amendment', () => {
		for (const [stage, declared] of Object.entries(config.nextStep.stages)) {
			const card = resolveNextStep(
				config.nextStep,
				{ lifecycle: stage },
				config.stageField,
			)
			expect(card.title, stage).toMatch(/^What now\? /)
			expect(card.items.length, stage).toBeGreaterThan(0)
			expect(
				card.items.every((item) => item.done === false),
				stage,
			).toBe(true)
			for (const item of declared.checklist) {
				expect(
					decision.properties[item.doneField],
					`${stage}: ${item.doneField}`,
				).toBeDefined()
			}
		}
		const ready = resolveNextStep(
			config.nextStep,
			{ lifecycle: 'draft', proposedText: 'x', proposer: 'y', amends: 'z' },
			config.stageField,
		)
		expect(ready.items.every((item) => item.done)).toBe(true)
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

	it('draws the five steps of the server road, in its order', () => {
		expect(AMENDMENT_STEPS).toEqual(Object.keys(transitions))
		for (let index = 0; index < AMENDMENT_STEPS.length - 1; index++) {
			expect(transitions[AMENDMENT_STEPS[index]]).toEqual([
				AMENDMENT_STEPS[index + 1],
			])
		}
		expect([
			...AMENDMENT_HEADER_STEPS,
			...AMENDMENT_VOTE_STEPS,
			'decided',
		]).toEqual(AMENDMENT_STEPS)
		expect(
			buildAmendmentSteps('deliberating').map((step) => step.status),
		).toEqual(['done', 'done', 'current', 'upcoming', 'upcoming'])
		for (const state of ['', 'withdrawn']) {
			expect(
				buildAmendmentSteps(state).every(
					(step) => step.status === 'upcoming',
				),
				state,
			).toBe(true)
		}
		for (const state of AMENDMENT_STEPS) {
			expect(dutch[STATE_LABELS[state]].length, state).toBeLessThanOrEqual(20)
		}
		expect(widgets.get('amend-steps').type).toBe('AmendmentStepBar')
		expect(registrySource).toContain('AmendmentStepBar: page(AmendmentStepBar)')
	})

	it('says who takes the next step, where the vote is, and how it ended', () => {
		expect(amendmentNote('draft')).toBe('who')
		expect(amendmentNote('proposed')).toBe('who')
		expect(amendmentNote('deliberating')).toBe('vote')
		expect(amendmentNote('voting')).toBe('vote')
		expect(amendmentNote('decided', 'adopted')).toBe('adopted')
		expect(amendmentNote('decided', 'rejected')).toBe('rejected')
		expect(amendmentNote('decided')).toBe('')
		expect(amendmentNote('')).toBe('')
		expect(decision.properties.outcome.enum).toEqual(['adopted', 'rejected'])
		const bar = read('src', 'components', 'widgets', 'AmendmentStepBar.vue')
		for (const sentence of [
			'Only the chair or the secretary can take the next step.',
			'The vote on this amendment opens and closes under Voting round.',
			'This amendment was adopted.',
			'This amendment was rejected.',
		]) {
			expect(bar).toContain(`'${sentence}'`)
			expect(dutch[sentence], sentence).toBeTruthy()
		}
		// The sentence names the tab by the label the tab has.
		expect(
			widgets.get('amend-panels').content.tabs.map((tab) => tab.label),
		).toContain('Voting round')
	})

	it('keeps the five steps on one row, with a word for each, and lets the card follow its content', () => {
		const bar = read('src', 'components', 'widgets', 'AmendmentStepBar.vue')
		expect(bar).toContain('grid-template-columns: repeat(5, minmax(0, 1fr));')
		expect(bar).toContain('min-height: 124px;')
		for (const word of ['Done', 'Now', 'Next']) {
			expect(bar).toContain(`this.t('decidiq', '${word}')`)
		}
		const entry = config.layout.find((item) => item.widgetId === 'amend-steps')
		expect(entry.sizeToContent).toBe(true)
		expect(entry.gridHeight).toBe(2)
	})

	it('pins at most three quick actions, and groups the rest under More', () => {
		expect(config.quickActions.length).toBeLessThanOrEqual(MAX_QUICK_ACTIONS)
		for (const id of config.quickActions) {
			expect(actions.has(id), id).toBe(true)
		}
		expect(config.actionsMenu).toEqual({ showRefresh: false, label: 'More' })
		for (const item of config.headerActions) {
			expect(item.group, item.id).toBe('Amendment')
		}
	})

	it('shows one card of facts in the side column, never empty, with a title short enough to read', () => {
		expect(config.sideColumn).toHaveLength(1)
		const card = config.sideColumn[0]
		expect(card.type).toBe('data')
		expect(card.content.editable).toBe(false)
		expect(card.title.length).toBeLessThanOrEqual(12)
		expect(dutch[card.title].length).toBeLessThanOrEqual(12)
		for (const field of card.content.include) {
			expect(decision.properties[field], field).toBeDefined()
		}
		expect(card.content.include[0]).toBe('decisionType')
		expect(decision.properties.decisionType.default).toBeTruthy()
	})

	it('puts the blocks behind three tabs, the text changes first, each label short', () => {
		const strip = widgets.get('amend-panels')
		expect(strip.type).toBe('tabs')
		expect(strip.content.maxVisibleTabs).toBe(5)
		const tabs = strip.content.tabs
		expect(tabs.map((tab) => tab.label)).toEqual([
			'Text changes',
			'Amendment',
			'Voting round',
		])
		expect(tabs.length).toBeLessThanOrEqual(strip.content.maxVisibleTabs)
		for (const tab of tabs) {
			expect(tab.overflow).toBeUndefined()
			expect(widgets.has(tab.widgetId), tab.widgetId).toBe(true)
			expect(tab.label.length, tab.label).toBeLessThanOrEqual(18)
			expect(dutch[tab.label].length, tab.label).toBeLessThanOrEqual(18)
		}
	})

	it('loses no block: every widget the manifest declares is in exactly one tab', () => {
		const declared = pageOf(full, 'AmendmentDetail').config.widgets.map(
			(item) => item.id,
		)
		expect(declared).toHaveLength(4)
		const shown = widgets
			.get('amend-panels')
			.content.tabs.flatMap((tab) =>
				blocksOf(widgets, tab).map((block) => block.id),
			)
		expect([...shown].sort()).toEqual([...declared].sort())
		expect(new Set(shown).size).toBe(shown.length)
	})

	it('gives every block in a tab a type a tab can resolve, and changes nothing else about it', () => {
		const tabs = widgets.get('amend-panels').content.tabs
		const inTabs = tabs.flatMap((tab) => {
			const panel = widgets.get(tab.widgetId)
			return panel.type === 'detail-sections'
				? [panel, ...blocksOf(widgets, tab)]
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
		const before = pageOf(full, 'AmendmentDetail')
		const custom = before.config.widgets.filter(
			(widget) => widget.type === 'custom',
		)
		expect(custom).toHaveLength(3)
		for (const widget of before.config.widgets) {
			const { type: was, ...rest } = widget
			const { type: now, ...kept } = widgets.get(widget.id)
			expect(kept, widget.id).toEqual(rest)
			if (was === 'custom') {
				expect(now, widget.id).toBe(widget.component)
				expect(before.slots[`widget-${widget.id}`], widget.id).toBe(now)
			} else {
				expect(now, widget.id).toBe(was)
			}
		}
		for (const panel of config.widgets.filter(
			(item) => item.type === 'detail-sections',
		)) {
			for (const section of panel.content.sections) {
				expect(section.widgetId).toBeUndefined()
				expect(section.widget).toBe(widgets.get(section.widget.id))
				expect(section.label).toBe(section.widget.title)
			}
		}
	})

	it('lays out the step bar and the tabs, and keeps the edit dialog and the History sidebar', () => {
		expect(config.layout.map((item) => item.widgetId)).toEqual([
			'amend-steps',
			'amend-panels',
		])
		expect(page.slots['form-dialog']).toBe('DecisionFormDialog')
		expect(config.sidebar).toEqual(
			pageOf(full, 'AmendmentDetail').config.sidebar,
		)
	})
})

describe('My actions in the simple structure', () => {
	const page = pageOf(simple, 'ActionItems')
	const config = page.config
	const [mine, everyone] = config.quickFilters

	beforeEach(() => {
		asked.length = 0
	})

	it('is what the menu entry My actions opens', () => {
		const entry = simple.menu.find((item) => item.id === 'ActionItems')
		expect(entry.label).toBe('My actions')
		expect(entry.route).toBe('ActionItems')
		expect(page.route).toBe('/action-items')
	})

	it('opens on the reader own action items, by the field that holds a user id', () => {
		expect(config.quickFilters.map((view) => view.label)).toEqual([
			'Mine',
			'Everyone',
		])
		expect(mine.default).toBe(true)
		expect(everyone.default).toBeUndefined()
		expect(mine.filter).toEqual({ assignee: '@me' })
		expect(actionItem.properties.assignee.type).toBe('string')
		// The dashboard's My action items widget reads the same field the
		// same way, so the two cannot disagree on whose item it is.
		const widget = read(
			'src',
			'views',
			'dashboard',
			'widgets',
			'MyActionItemsWidget.vue',
		)
		expect(widget).toContain('const uid = getCurrentUser()?.uid')
		expect(widget).toContain('assignee: uid,')
	})

	it('fills in the reader at fetch time, flat, with no token left in the address', () => {
		const resolved = resolveFilterMap(mine.filter, {}, {})
		expect(resolved).toEqual({ assignee: getCurrentUser().uid })
		const query = decodeURIComponent(buildQueryString(resolved))
		expect(query).toBe(`?assignee=${getCurrentUser().uid}`)
		expect(query).not.toContain('@')
		expect(query).not.toContain('{')
	})

	it('counts each view with the filter the view lists with', async () => {
		// One view per call. In this node environment two count requests
		// that start together trip over a `window` the library reaches for
		// on first use; a browser has one. Each view takes the same path
		// through the library either way.
		const counts = {}
		for (const [index, view] of config.quickFilters.entries()) {
			Object.assign(
				counts,
				await fetchFilterCounts({
					register: config.register,
					schema: config.schema,
					entries: [
						{
							key: String(index),
							filter: resolveFilterMap(view.filter, {}, {}),
						},
					],
					baseFilter: resolveFilterMap(config.filter, {}, {}),
					ctx: {},
				}),
			)
		}
		// Both counts were answered, so both requests left.
		expect(counts).toEqual({ 0: 7, 1: 7 })
		expect(asked).toHaveLength(2)
		for (const { url } of asked) {
			// The router stub of this suite leaves the placeholders in.
			expect(url).toContain('/apps/openregister/api/objects/aggregations/')
			expect(url.endsWith('/value')).toBe(true)
		}
		// A count is asked with `filter[<field>]`, a list with `<field>`. The
		// field and the value have to be the same in both.
		const countOf = (index) =>
			Object.fromEntries(
				Object.entries(asked[index].params)
					.filter(([key]) => key.startsWith('filter['))
					.map(([key, value]) => [key.slice(7, -1), value]),
			)
		const listOf = (view) => resolveFilterMap(view.filter, {}, {})
		expect(countOf(0)).toEqual(listOf(mine))
		expect(countOf(1)).toEqual(listOf(everyone))
		expect(countOf(0)).toEqual({ assignee: getCurrentUser().uid })
		expect(countOf(1)).toEqual({})
		for (const { params } of asked) {
			expect(params.metric).toBe('count')
			expect(JSON.stringify(params)).not.toContain('@')
			// Plain equality only: the one form the count endpoint answers.
			for (const value of Object.values(params)) {
				expect(typeof value).not.toBe('object')
			}
		}
	})

	it('keeps the list as it was one view away, and nothing else changes', () => {
		expect(everyone.filter).toEqual({})
		expect(resolveFilterMap(everyone.filter, {}, {})).toEqual({})
		const { quickFilters, ...rest } = config
		expect(quickFilters).toHaveLength(2)
		expect(rest).toEqual(pageOf(full, 'ActionItems').config)
		for (const view of config.quickFilters) {
			expect(view.showCount).toBe(true)
			expect(view.label.length).toBeLessThanOrEqual(18)
			expect(dutch[view.label].length).toBeLessThanOrEqual(18)
		}
	})

	it('leaves pending votes and commitments alone, because neither names a user', () => {
		// A vote names a participant, and a participant names a user: two
		// steps. A commitment names the member who made it by a record id.
		const register = readJson('lib', 'Settings', 'decidesk_register.json')
			.components.schemas
		expect(register.Vote.properties.participant.format).toBe('uuid')
		expect(register.Vote.properties.nextcloudUserId).toBeUndefined()
		expect(register.Participant.properties.nextcloudUserId.type).toBe('string')
		const commitment = schemaOf('Commitment', 'governance-commitment')
		expect(commitment.properties.madeBy.format).toBe('uuid')
		expect(commitment.properties.assignee).toBeUndefined()
		for (const id of ['Commitments', 'CommitmentDetail']) {
			expect(pageOf(simple, id), id).toEqual(pageOf(full, id))
		}
	})
})

describe('the two overlays', () => {
	const overlays = readJson('src', 'menu-layout.simple.json').pages.filter(
		(item) => ['AmendmentDetail', 'ActionItems'].includes(item.id),
	)

	it('are both there, and patch only things that exist', () => {
		expect(overlays.map((item) => item.id)).toEqual([
			'AmendmentDetail',
			'ActionItems',
		])
		const declared = pageOf(full, 'AmendmentDetail').config
		for (const id of Object.keys(overlays[0].configPatch.widgets)) {
			expect(
				declared.widgets.some((widget) => widget.id === id),
				id,
			).toBe(true)
		}
		const built = pageOf(simple, 'AmendmentDetail').config
		for (const list of [built.headerActions, built.widgets]) {
			const ids = list.map((item) => item.id)
			expect(new Set(ids).size).toBe(ids.length)
		}
	})

	it('add no page, no page of type custom and no custom block', () => {
		expect(pageOf(simple, 'AmendmentDetail').type).toBe('detail')
		expect(pageOf(simple, 'ActionItems').type).toBe('index')
		for (const widget of overlays[0].configAppend.widgets) {
			expect(widget.type, widget.id).not.toBe('custom')
		}
		expect(simple.pages.map((item) => item.id)).toEqual(
			full.pages.map((item) => item.id),
		)
	})

	it('use icons the app registers and texts that read in Dutch', () => {
		const icons = new Set()
		const shown = new Set()
		for (const overlay of overlays) {
			JSON.stringify(overlay, (key, value) => {
				if (key === 'icon' && typeof value === 'string') icons.add(value)
				return value
			})
			texts(overlay, shown)
		}
		expect(icons.size).toBeGreaterThan(2)
		for (const icon of icons) {
			expect(iconsSource, icon).toContain(`\n\t${icon},\n`)
		}
		expect(shown.size).toBeGreaterThan(15)
		for (const text of shown) {
			expect(dutch[text], text).toBeTruthy()
			expect(text, text).not.toContain('—')
			expect(dutch[text], text).not.toContain('—')
		}
	})

	it('build into a manifest the library accepts', () => {
		const result = validateManifest(JSON.parse(JSON.stringify(simple)))
		expect(result.errors).toEqual([])
		expect(result.valid).toBe(true)
	})
})
