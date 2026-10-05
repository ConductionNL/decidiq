/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The meeting page in the simple structure.
 *
 * The page gets its simple shape from an overlay in
 * `src/menu-layout.simple.json`. An overlay is names: a stage, an action id, a
 * field, a widget id, a registry component. A name that names nothing changes
 * nothing and says nothing, so every name is checked here against the thing it
 * names: the schema, the server's transition table, the routes, the manifest's
 * widgets, the registry and the icon set.
 *
 * The library's own resolvers (`stageOf`, `resolveNextStep`, `resolvePill`,
 * `evaluateVisibleWhenLocal`) answer the questions about stages and
 * visibility, so this file does not hold a second opinion on how they work.
 *
 * @spec openspec/changes/simple-meeting-page/specs/meeting-detail-view/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import {
	MAX_QUICK_ACTIONS,
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
import { lifecyclePath, STAGE_ACTIONS } from '../../src/utils/meetingStages.js'
import {
	buildMeetingSteps,
	MEETING_BREAKS,
	MEETING_NEXT_ACTION,
	MEETING_STATE_LABELS,
	MEETING_STEPS,
	nextStepIsOffered,
	stepOf,
} from '../../src/utils/meetingSteps.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

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
 * The meeting schema as an instance gets it: the base register plus every
 * fragment that adds to it.
 *
 * @return {{properties: object, required: string[]}} The merged schema.
 */
function meetingSchema() {
	const base = readJson('lib', 'Settings', 'decidesk_register.json').components
		.schemas.Meeting
	const properties = { ...base.properties }
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const name of fs.readdirSync(dir).sort()) {
		if (!name.endsWith('.json')) continue
		const schemas =
			JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')).components
				?.schemas ?? {}
		for (const [key, schema] of Object.entries(schemas)) {
			if (key.toLowerCase() === 'meeting' || schema?.slug === 'meeting') {
				// A fragment adds to a property it names; it does not replace it.
				for (const [field, added] of Object.entries(
					schema.properties ?? {},
				)) {
					properties[field] = { ...(properties[field] ?? {}), ...added }
				}
			}
		}
	}
	return { properties, required: base.required }
}
const schema = meetingSchema()
const lifecycleStates = schema.properties.lifecycle.enum

/**
 * The server's transition table, read from the service that enforces it.
 *
 * @return {Record<string, {from: string[], to: string}>} action -> edge.
 */
function serverTransitions() {
	const source = read('lib', 'Service', 'MeetingService.php')
	const start = source.indexOf('private const TRANSITIONS = [')
	const block = source.slice(start, source.indexOf('];', start))
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
	it('shows the meeting page exactly as the manifest declares it', () => {
		const declared = readJson('src', 'manifest.json').pages
		expect(pageOf(full, 'MeetingDetail')).toEqual(
			declared.find((page) => page.id === 'MeetingDetail'),
		)
		expect(pageOf(full, 'MeetingDetail').config.headerActions).toBeUndefined()
	})
})

describe('the meeting page in the simple structure', () => {
	const page = pageOf(simple, 'MeetingDetail')
	const config = page.config
	const actions = new Map(config.headerActions.map((item) => [item.id, item]))
	const widgets = new Map(config.widgets.map((item) => [item.id, item]))
	const transitions = serverTransitions()
	const moves = config.headerActions.filter((item) =>
		item.url?.endsWith('/lifecycle'),
	)

	it('reads the server table it is compared with', () => {
		// The control for every comparison below: a regex that matched nothing
		// would make them all pass.
		expect(Object.keys(transitions)).toEqual(STAGE_ACTIONS)
		expect(transitions.close.from).toEqual([
			'scheduled',
			'opened',
			'paused',
			'adjourned',
		])
		expect(lifecycleStates).toEqual([
			'draft',
			'scheduled',
			'opened',
			'paused',
			'adjourned',
			'closed',
		])
	})

	it('reads the stage from the lifecycle field, which the schema has', () => {
		expect(config.stageField).toBe('lifecycle')
		expect(schema.properties.lifecycle).toBeDefined()
		expect(stageOf({ lifecycle: 'opened' }, config.stageField)).toBe('opened')
		// A record without a state has no stage, so no button and no card.
		expect(stageOf({}, config.stageField)).toBe('')
		expect(resolveNextStep(config.nextStep, {}, config.stageField)).toBeNull()
	})

	it('has one primary button for every state but closed, each a declared action', () => {
		expect(Object.keys(config.primaryActionByStage)).toEqual([
			'draft',
			'scheduled',
			'opened',
			'paused',
			'adjourned',
		])
		for (const [stage, id] of Object.entries(config.primaryActionByStage)) {
			expect(lifecycleStates, stage).toContain(stage)
			expect(actions.has(id), `${stage} -> ${id}`).toBe(true)
			expect(stageEntry(config.primaryActionByStage, stage)).toBe(id)
		}
		// A closed meeting is where a meeting ends: no next step.
		expect(config.primaryActionByStage.closed).toBeUndefined()
	})

	it('shows the primary button in its own state', () => {
		for (const [stage, id] of Object.entries(config.primaryActionByStage)) {
			expect(
				evaluateVisibleWhenLocal(actions.get(id).visibleWhen, {
					lifecycle: stage,
				}),
				`${id} on a ${stage} meeting`,
			).toBe(true)
		}
		// And on a closed meeting no step shows at all.
		for (const move of moves) {
			expect(
				evaluateVisibleWhenLocal(move.visibleWhen, { lifecycle: 'closed' }),
				move.id,
			).toBe(false)
		}
	})

	it('takes the step the step bar expects of each state', () => {
		const byStage = Object.fromEntries(
			Object.entries(config.primaryActionByStage).map(([stage, id]) => [
				stage,
				actions.get(id).payload.action,
			]),
		)
		expect(byStage).toEqual(MEETING_NEXT_ACTION)
	})

	it('moves a meeting through the guarded endpoint, with a step the server knows, from exactly the states the server allows', () => {
		expect(moves.map((item) => item.payload.action)).toEqual([
			'schedule',
			'open',
			'resume',
			'close',
		])
		for (const move of moves) {
			expect(move.type).toBe('api-call')
			expect(move.method).toBe('POST')
			expect(move.url).toBe('/apps/decidiq/api/meetings/@objectId/lifecycle')
			const edge = transitions[move.payload.action]
			expect(edge, move.payload.action).toBeDefined()
			// Offered in every state the server allows the step from, and in no
			// other: a mismatch in either direction fails.
			const offeredIn = lifecycleStates.filter((state) =>
				evaluateVisibleWhenLocal(move.visibleWhen, { lifecycle: state }),
			)
			expect(offeredIn, move.id).toEqual(
				lifecycleStates.filter((state) => edge.from.includes(state)),
			)
		}
	})

	it('posts to the route the Stage block posts to, with the body that block sends', () => {
		expect(routesSource).toContain(
			"['name' => 'meeting#lifecycle', 'url' => '/api/meetings/{id}/lifecycle', 'verb' => 'POST']",
		)
		expect(`/apps/decidiq/api/meetings/@objectId/lifecycle`).toBe(
			lifecyclePath('@objectId'),
		)
		const stageTab = read('src', 'components', 'tabs', 'MeetingStageTab.vue')
		expect(stageTab).toContain('generateUrl(lifecyclePath(this.objectId))')
		expect(stageTab).toContain('body: JSON.stringify({ action })')
		for (const move of moves) {
			expect(Object.keys(move.payload), move.id).toEqual(['action'])
			// And it carries the label the Stage block gives that step.
			expect(stageTab, move.label).toContain(
				`${move.payload.action}: this.t('decidiq', '${move.label}')`,
			)
		}
	})

	it('leaves pause and adjourn to the Stage block, the only two steps a governance domain can switch off', () => {
		const offered = moves.map((item) => item.payload.action)
		expect(STAGE_ACTIONS.filter((step) => !offered.includes(step))).toEqual([
			'pause',
			'adjourn',
		])
		// The server's domain rules name exactly these two targets. A third
		// one would be a step this page offers and a domain can refuse.
		const workflow = read('lib', 'Service', 'WorkflowService.php')
		const allowed = workflow.slice(
			workflow.indexOf('public function isTransitionAllowed('),
			workflow.indexOf('}//end isTransitionAllowed()'),
		)
		const targets = [...allowed.matchAll(/\$toState === '(\w+)'/g)].map(
			(match) => match[1],
		)
		expect(targets).toEqual(['paused', 'adjourned'])
		expect(targets).toEqual([transitions.pause.to, transitions.adjourn.to])
		for (const step of offered) {
			expect(targets, step).not.toContain(transitions[step].to)
		}
	})

	it('sends no field that would let the page decide who may move a meeting', () => {
		for (const item of config.headerActions) {
			expect(item.adminOnly, item.id).toBeUndefined()
			expect(item.permission, item.id).toBeUndefined()
		}
		// The check is the server's, on the route every button posts to.
		const controller = read('lib', 'Controller', 'MeetingController.php')
		const lifecycle = controller.slice(
			controller.indexOf('public function lifecycle('),
			controller.indexOf('}//end lifecycle()'),
		)
		expect(lifecycle).toContain('$this->roleGate->isChairOrSecretary(')
		expect(lifecycle).toContain('Http::STATUS_FORBIDDEN')
	})

	it('says who takes the next step from the server answer, and nothing without one', () => {
		// A member is offered no step; the chair is offered the step of the state.
		for (const state of Object.keys(MEETING_NEXT_ACTION)) {
			expect(nextStepIsOffered(state, []), state).toBe(false)
			expect(
				nextStepIsOffered(state, [MEETING_NEXT_ACTION[state]]),
				state,
			).toBe(true)
		}
		// Offered a different step only (a chair-only rule on this one).
		expect(nextStepIsOffered('opened', ['pause'])).toBe(false)
		// A closed meeting has no next step, so nobody is refused one.
		expect(nextStepIsOffered('closed', [])).toBe(true)
		expect(nextStepIsOffered('draft', null)).toBe(false)
		const bar = read('src', 'components', 'widgets', 'MeetingStepBar.vue')
		expect(bar).toContain('generateUrl(transitionsPath(this.objectId))')
		expect(bar).toContain('this.offered !== null')
		expect(routesSource).toContain(
			"['name' => 'meeting#transitions', 'url' => '/api/meetings/{id}/transitions', 'verb' => 'GET']",
		)
	})

	it('asks before the steps that cannot be taken back', () => {
		for (const id of ['meeting-open', 'meeting-close']) {
			expect(actions.get(id).confirm, id).toBe(true)
		}
	})

	it('never shows a what-now card without a button that moves the meeting', () => {
		const stages = Object.keys(config.nextStep.stages)
		expect(stages).toEqual(['draft', 'scheduled', 'opened'])
		for (const stage of stages) {
			const primary = actions.get(config.primaryActionByStage[stage])
			expect(primary, stage).toBeDefined()
			expect(primary.type).toBe('api-call')
			expect(
				evaluateVisibleWhenLocal(primary.visibleWhen, { lifecycle: stage }),
				stage,
			).toBe(true)
		}
		// A closed meeting has neither.
		expect(
			resolveNextStep(config.nextStep, { lifecycle: 'closed' }, 'lifecycle'),
		).toBeNull()
	})

	it('gives each card a checklist that reads real fields, unticked on an empty meeting', () => {
		for (const [stage, declared] of Object.entries(config.nextStep.stages)) {
			expect(lifecycleStates, stage).toContain(stage)
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
					schema.properties[item.doneField],
					`${stage}: ${item.doneField}`,
				).toBeDefined()
			}
		}
		const ready = resolveNextStep(
			config.nextStep,
			{
				lifecycle: 'scheduled',
				agendaPublishedAt: '2026-10-05T09:00:00+00:00',
				convocationSentAt: '2026-10-05T09:00:00+00:00',
				quorumWith: true,
			},
			config.stageField,
		)
		expect(ready.items.every((item) => item.done)).toBe(true)
	})

	it('ticks the quorum item from the field the server refuses on, and says so', () => {
		const item = config.nextStep.stages.scheduled.checklist.find(
			(entry) => entry.doneField === 'quorumWith',
		)
		expect(item.hint).toBeTruthy()
		expect(read('lib', 'Lifecycle', 'MeetingTransitionGuard.php')).toContain(
			"($meeting['quorumWith'] ?? false) === true",
		)
	})

	it('names the state in the pill with the words the step bar and the Stage block use', () => {
		expect(config.statusPill.field).toBe('lifecycle')
		expect(Object.keys(config.statusPill.labels)).toEqual(lifecycleStates)
		expect(Object.keys(config.statusPill.colorMap)).toEqual(lifecycleStates)
		expect(config.statusPill.labels).toEqual(MEETING_STATE_LABELS)
		const stageTab = read('src', 'components', 'tabs', 'MeetingStageTab.vue')
		for (const [state, label] of Object.entries(MEETING_STATE_LABELS)) {
			expect(stageTab, state).toContain(
				`${state}: this.t('decidiq', '${label}')`,
			)
			expect(dutch[label], label).toBeTruthy()
		}
		expect(
			resolvePill(config.statusPill, { lifecycle: 'opened' }),
		).toMatchObject({ label: 'In session', colorKey: 'opened' })
		expect(resolvePill(config.statusPill, {})).toBeNull()
	})

	it('draws four steps and keeps a meeting on a break on the third', () => {
		expect(MEETING_STEPS).toEqual(['draft', 'scheduled', 'opened', 'closed'])
		expect([...MEETING_STEPS, ...MEETING_BREAKS].sort()).toEqual(
			[...lifecycleStates].sort(),
		)
		expect(buildMeetingSteps('scheduled')).toEqual([
			{ state: 'draft', status: 'done' },
			{ state: 'scheduled', status: 'current' },
			{ state: 'opened', status: 'upcoming' },
			{ state: 'closed', status: 'upcoming' },
		])
		for (const state of MEETING_BREAKS) {
			expect(stepOf(state)).toBe('opened')
			expect(buildMeetingSteps(state)).toEqual(buildMeetingSteps('opened'))
		}
		expect(buildMeetingSteps('closed').map((step) => step.status)).toEqual([
			'done',
			'done',
			'done',
			'current',
		])
		// No state, or one the bar does not know: on no step.
		for (const state of ['', 'cancelled']) {
			expect(
				buildMeetingSteps(state).every((step) => step.status === 'upcoming'),
				state,
			).toBe(true)
		}
		expect(widgets.get('meeting-steps').type).toBe('MeetingStepBar')
		expect(registrySource).toContain('MeetingStepBar: page(MeetingStepBar)')
	})

	it('keeps the four steps on one row, with a word for each, and lets the card follow its content', () => {
		const bar = read('src', 'components', 'widgets', 'MeetingStepBar.vue')
		expect(bar).toContain('grid-template-columns: repeat(4, minmax(0, 1fr));')
		// The card is at least two grid rows high: the tiles fill it.
		expect(bar).toContain('min-height: 124px;')
		for (const word of ['Done', 'Now', 'Next']) {
			expect(bar).toContain(`this.t('decidiq', '${word}')`)
			expect(dutch[word], word).toBeTruthy()
		}
		const entry = config.layout.find((item) => item.widgetId === 'meeting-steps')
		expect(entry.sizeToContent).toBe(true)
		expect(entry.gridHeight).toBe(2)
		for (const state of MEETING_STEPS) {
			expect(
				dutch[MEETING_STATE_LABELS[state]].length,
				state,
			).toBeLessThanOrEqual(20)
		}
	})

	it('pins quick actions that are declared, at most three, and none of them a way around a server gate', () => {
		expect(config.quickActions.length).toBeLessThanOrEqual(MAX_QUICK_ACTIONS)
		for (const id of config.quickActions) {
			expect(actions.has(id), id).toBe(true)
		}
		const integrations = actions.get('meeting-open-integrations')
		expect(integrations.type).toBe('navigate')
		expect(integrations.target).toBe('/meetings/@objectId/integrations')
		expect(pageOf(simple, 'MeetingIntegrations').route).toBe(
			'/meetings/:id/integrations',
		)
		// The live screen is for the chair and the secretary (REQ-AMP-004): its
		// button stays in the Agenda block, which asks the server.
		for (const item of config.headerActions) {
			expect(String(item.target ?? ''), item.id).not.toContain('/live')
		}
	})

	it('groups every action under More, in one named group', () => {
		expect(config.actionsMenu).toEqual({ showRefresh: false, label: 'More' })
		for (const item of config.headerActions) {
			expect(item.group, item.id).toBe('Meeting')
		}
		expect(dutch.Meeting).toBeTruthy()
	})

	it('shows one card of facts in the side column, never empty, with a title short enough to read', () => {
		expect(config.sideColumn).toHaveLength(1)
		const card = config.sideColumn[0]
		expect(card.type).toBe('data')
		expect(card.content.editable).toBe(false)
		expect(card.title.length).toBeLessThanOrEqual(12)
		expect(dutch[card.title].length).toBeLessThanOrEqual(12)
		for (const field of card.content.include) {
			expect(schema.properties[field], field).toBeDefined()
		}
		// It leads with a field every meeting must have, so it is never an
		// empty box: the library has no way to hide an empty side card.
		expect(card.content.include[0]).toBe('scheduledDate')
		expect(schema.required).toContain('scheduledDate')
	})

	it('puts the blocks behind five tabs and More, the agenda first', () => {
		const strip = widgets.get('meeting-panels')
		expect(strip.type).toBe('tabs')
		const tabs = strip.content.tabs
		expect(strip.content.maxVisibleTabs).toBe(5)
		expect(strip.content.moreLabel).toBe('More')
		expect(tabs.filter((tab) => !tab.overflow).map((tab) => tab.label)).toEqual([
			'Agenda',
			'Participants',
			'Documents',
			'Decisions',
			'Minutes',
		])
		expect(tabs.filter((tab) => tab.overflow).map((tab) => tab.label)).toEqual([
			'Planning',
			'Broadcast',
			'Case system',
			'Audit statements',
			'Stage',
		])
		// The tabs that show come first, so the strip never has to reorder.
		expect(tabs.findIndex((tab) => tab.overflow)).toBe(5)
		expect(blocksOf(widgets, tabs[0])[0].id).toBe('meeting-agenda')
		for (const tab of tabs) {
			expect(widgets.has(tab.widgetId), tab.widgetId).toBe(true)
		}
	})

	it('keeps every tab label short, in English and in Dutch', () => {
		for (const tab of widgets.get('meeting-panels').content.tabs) {
			expect(tab.label.length, tab.label).toBeLessThanOrEqual(18)
			expect(dutch[tab.label], tab.label).toBeTruthy()
			// "Kascommissie verklaringen" is the one long Dutch label, and it
			// sits in the More list, which is a menu and not a strip.
			if (!tab.overflow) {
				expect(dutch[tab.label].length, tab.label).toBeLessThanOrEqual(18)
			}
		}
	})

	it('loses no block: every widget the manifest declares is in exactly one tab', () => {
		const declared = pageOf(full, 'MeetingDetail').config.widgets.map(
			(item) => item.id,
		)
		expect(declared).toHaveLength(25)
		const tabs = widgets.get('meeting-panels').content.tabs
		const shown = tabs.flatMap((tab) =>
			blocksOf(widgets, tab).map((block) => block.id),
		)
		expect(declared.filter((id) => !shown.includes(id))).toEqual([])
		expect(shown.filter((id) => !declared.includes(id))).toEqual([])
		// No block is shown twice either.
		expect(new Set(shown).size).toBe(shown.length)
		expect(shown).toHaveLength(25)
	})

	it('writes the definition into every section, and it is the one the page declares', () => {
		const panels = config.widgets.filter(
			(item) => item.type === 'detail-sections',
		)
		expect(panels).toHaveLength(6)
		for (const panel of panels) {
			for (const section of panel.content.sections) {
				expect(section.widgetId, panel.id).toBeUndefined()
				expect(section.widget, `${panel.id}: ${section.label}`).toBe(
					widgets.get(section.widget.id),
				)
				// A section is headed by the title its block has as a card.
				expect(section.label, section.widget.id).toBe(section.widget.title)
				expect(dutch[section.label], section.label).toBeTruthy()
			}
		}
	})

	it('gives every block in a tab a type a tab can resolve', () => {
		// A tab resolves a widget by its type: the app registry first, then
		// the library's catalog. A `custom` widget resolves through the page's
		// slot map only, which a tab does not read, so it would render nothing.
		const tabs = widgets.get('meeting-panels').content.tabs
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
		// The custom blocks are named by the component the full structure
		// renders them with, so both structures show the same one.
		const before = pageOf(full, 'MeetingDetail')
		const custom = before.config.widgets.filter(
			(widget) => widget.type === 'custom',
		)
		expect(custom).toHaveLength(19)
		for (const widget of custom) {
			expect(widget.component, widget.id).toBeTruthy()
			expect(widgets.get(widget.id).type, widget.id).toBe(widget.component)
			const slot = before.slots[`widget-${widget.id}`]
			if (slot !== undefined) {
				expect(slot, widget.id).toBe(widget.component)
			}
		}
		// And nothing else about a block changes.
		for (const widget of before.config.widgets) {
			const { type: was, ...rest } = widget
			const { type: now, ...kept } = widgets.get(widget.id)
			expect(kept, widget.id).toEqual(rest)
			if (was !== 'custom') {
				expect(now, widget.id).toBe(was)
			}
		}
	})

	it('lays out the step bar and the tabs, and nothing a tab already shows', () => {
		expect(config.layout.map((item) => item.widgetId)).toEqual([
			'meeting-steps',
			'meeting-panels',
		])
		for (const item of config.layout) {
			expect(widgets.has(item.widgetId), item.widgetId).toBe(true)
			expect(item.gridX + item.gridWidth).toBeLessThanOrEqual(12)
		}
	})

	it('keeps the Stage block on the page, under More', () => {
		// The block shows every step the server offers this person, pause and
		// adjourn included, and the cost of a closed meeting.
		const tabs = widgets.get('meeting-panels').content.tabs
		const stage = tabs.find((tab) => tab.widgetId === 'meeting-stage')
		expect(stage.overflow).toBe(true)
		expect(widgets.get('meeting-stage').type).toBe('MeetingStageTab')
		expect(config.nextStep.stages.opened.checklist[0].hint).toContain('Stage')
	})

	it('keeps the History sidebar as it is', () => {
		expect(config.sidebar).toEqual(pageOf(full, 'MeetingDetail').config.sidebar)
	})
})

describe('the meeting overlay', () => {
	const overlay = readJson('src', 'menu-layout.simple.json').pages.find(
		(item) => item.id === 'MeetingDetail',
	)

	it('patches and appends only things that exist, and no id twice', () => {
		const declared = pageOf(full, 'MeetingDetail').config
		for (const id of Object.keys(overlay.configPatch.widgets)) {
			expect(
				declared.widgets.some((widget) => widget.id === id),
				id,
			).toBe(true)
		}
		const built = pageOf(simple, 'MeetingDetail').config
		for (const list of [built.headerActions, built.widgets]) {
			const ids = list.map((item) => item.id)
			expect(new Set(ids).size).toBe(ids.length)
		}
	})

	it('adds no page of type custom and no custom block', () => {
		expect(pageOf(simple, 'MeetingDetail').type).toBe('detail')
		for (const widget of overlay.configAppend.widgets) {
			expect(widget.type, widget.id).not.toBe('custom')
		}
		expect(simple.pages.map((item) => item.id)).toEqual(
			full.pages.map((item) => item.id),
		)
	})

	it('uses icons the app registers and texts that read in Dutch', () => {
		const icons = new Set()
		JSON.stringify(overlay, (key, value) => {
			if (key === 'icon' && typeof value === 'string') icons.add(value)
			return value
		})
		expect(icons.size).toBeGreaterThan(5)
		for (const icon of icons) {
			expect(iconsSource, icon).toContain(`\n\t${icon},\n`)
		}
		const shown = texts(overlay)
		expect(shown.size).toBeGreaterThan(30)
		for (const text of shown) {
			expect(dutch[text], text).toBeTruthy()
			// The house style: no em-dash in anything a reader sees.
			expect(text, text).not.toContain('—')
			expect(dutch[text], text).not.toContain('—')
		}
	})

	it('builds into a manifest the library accepts', () => {
		const result = validateManifest(JSON.parse(JSON.stringify(simple)))
		expect(result.errors).toEqual([])
		expect(result.valid).toBe(true)
	})
})
