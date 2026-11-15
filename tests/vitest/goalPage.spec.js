/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The goal page (board DcDoel, goals-linked-to-decisions Task 4): the progress
 * lines as "x of y" with a zero total hidden, the contributions of four kinds,
 * the parent card's counts and the three steps; and the full structure's page
 * declares the four blocks by their registry components.
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	contributionRows,
	goalSteps,
	linePercent,
	objectIdOf,
	parentGoalIdOf,
	parentSummary,
	progressLines,
	shortDate,
} from '../../src/utils/goalPage.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const commitments = [
	{ id: 'c1', text: 'Map the routes', deadline: '2026-03-01', lifecycle: 'disposed' },
	{ id: 'c2', text: 'Report to the council', deadline: '2026-06-01', lifecycle: 'open' },
	{ id: 'c3', text: 'Ask the cyclists', deadline: '2026-07-01', lifecycle: 'disposed' },
	{ id: 'c4', text: 'Budget the lights', deadline: '2026-09-01', lifecycle: 'open' },
]

describe('progress lines', () => {
	it('reads the target value with its unit as current of target', () => {
		const lines = progressLines({ targetValue: 12, currentValue: 7, unit: ' fietsroutes met verlichting ' })
		expect(lines).toEqual([
			{ key: 'target', label: 'Target value', current: 7, total: 12, unit: 'fietsroutes met verlichting' },
		])
	})

	it('counts settled commitments, done action items and reached sub-goals', () => {
		const lines = progressLines({}, {
			commitments,
			actionItems: [{ taskStatus: 'completed' }, { taskStatus: 'open' }, { taskStatus: 'completed' }],
			subgoals: [{ status: 'achieved' }, { status: 'active' }],
		})
		expect(lines.map((line) => [line.key, line.current, line.total])).toEqual([
			['commitments', 2, 4],
			['action-items', 2, 3],
			['subgoals', 1, 2],
		])
	})

	it('hides a line whose total is zero', () => {
		const lines = progressLines({ targetValue: 0, currentValue: 3 }, { commitments, actionItems: [], subgoals: [] })
		expect(lines.map((line) => line.key)).toEqual(['commitments'])
		expect(progressLines(null)).toEqual([])
	})

	it('skips holes in a list and reads a numeric string as a number', () => {
		const lines = progressLines({ targetValue: '10', currentValue: 'x' }, { commitments: [null, { lifecycle: 'disposed' }] })
		expect(lines).toEqual([
			{ key: 'target', label: 'Target value', current: 0, total: 10, unit: '' },
			{ key: 'commitments', label: 'Commitments settled', current: 1, total: 1, unit: '' },
		])
	})

	it('gives the bar a share capped at 100', () => {
		expect(linePercent({ current: 7, total: 12 })).toBe(58)
		expect(linePercent({ current: 15, total: 12 })).toBe(100)
		expect(linePercent({ current: 1, total: 0 })).toBe(0)
		expect(linePercent(null)).toBe(0)
	})
})

describe('what contributes to the goal', () => {
	it('lists four kinds in order, each linked to its own page', () => {
		const rows = contributionRows({
			commitment: commitments.slice(0, 2),
			'action-item': [{ id: 'a1', title: 'Place the posts', dueDate: '2026-05-01', taskStatus: 'open' }],
			'planned-agenda-item': [{ id: 'p1', subject: 'Lighting plan', plannedPeriod: 'Raad 12 maart', lifecycle: 'planned' }],
			decision: [{ id: 'd1', title: 'Light the routes', decisionDate: '2026-02-01', lifecycle: 'adopted' }],
		})
		expect(rows.map((row) => [row.kindLabel, row.what, row.term, row.status])).toEqual([
			['Commitment', 'Map the routes', '2026-03-01', 'disposed'],
			['Commitment', 'Report to the council', '2026-06-01', 'open'],
			['Action item', 'Place the posts', '2026-05-01', 'open'],
			['Planned agenda', 'Lighting plan', 'Raad 12 maart', 'planned'],
			['Decision', 'Light the routes', '2026-02-01', 'adopted'],
		])
		expect(rows[3].location).toEqual({ name: 'PlannedAgendaDetail', params: { id: 'p1' } })
		expect(rows[4].location).toEqual({ name: 'DecisionDetail', params: { id: 'd1' } })
		expect(new Set(rows.map((row) => row.id)).size).toBe(5)
	})

	it('is empty for a goal nothing points at, and has no link without an id', () => {
		expect(contributionRows({})).toEqual([])
		expect(contributionRows()).toEqual([])
		const [row] = contributionRows({ decision: [{ title: 'No id' }, null] })
		expect(row.location).toBeNull()
	})

	it('reads an id from id, uuid or @self', () => {
		expect(objectIdOf({ id: 'a' })).toBe('a')
		expect(objectIdOf({ uuid: 'b' })).toBe('b')
		expect(objectIdOf({ '@self': { id: 'c' } })).toBe('c')
		expect(objectIdOf(null)).toBe('')
	})
})

describe('the parent goal', () => {
	it('counts the sub-goals and how many are reached', () => {
		expect(parentSummary([{ status: 'achieved' }, { status: 'active' }, { status: 'draft' }])).toEqual({ count: 3, achieved: 1 })
		expect(parentSummary(null)).toEqual({ count: 0, achieved: 0 })
	})

	it('reads the parent id, and none for a top-level goal', () => {
		expect(parentGoalIdOf({ parentGoal: ' p ' })).toBe('p')
		expect(parentGoalIdOf({ parentGoal: null })).toBe('')
		expect(parentGoalIdOf(null)).toBe('')
	})
})

describe('the goal steps', () => {
	const goal = { '@self': { created: '2026-01-05T10:00:00+00:00' }, startDate: '2026-02-01', deadline: '2027-12-31' }

	it('dates Draft by creation, Active by start and Reached by the deadline', () => {
		const steps = goalSteps({ ...goal, status: 'active' })
		expect(steps.map((step) => [step.label, shortDate(step.date), step.status])).toEqual([
			['Draft', '2026-01-05', 'done'],
			['Active', '2026-02-01', 'current'],
			['Reached', '2027-12-31', 'upcoming'],
		])
	})

	it('puts an at-risk goal on Active and an achieved goal past every step', () => {
		expect(goalSteps({ status: 'at-risk' }).map((step) => step.status)).toEqual(['done', 'current', 'upcoming'])
		expect(goalSteps({ status: 'achieved' }).map((step) => step.status)).toEqual(['done', 'done', 'done'])
		expect(goalSteps({ status: 'draft' }).map((step) => step.status)).toEqual(['current', 'upcoming', 'upcoming'])
	})

	it('puts an abandoned goal on no step', () => {
		expect(goalSteps({ status: 'abandoned' }).map((step) => step.status)).toEqual(['upcoming', 'upcoming', 'upcoming'])
	})

	it('shows a free-text term unchanged', () => {
		expect(shortDate('Raad 12 maart')).toBe('Raad 12 maart')
		expect(shortDate(null)).toBe('')
	})
})

describe('the goal page in the full structure', () => {
	const page = readJson('src', 'manifest.d', 'organisation-goals.json').pages.find((p) => p.id === 'GoalDetail')
	const registry = read('src', 'registry.js')

	it('declares the four blocks by their registry components and lays each out', () => {
		const widgets = new Map(page.config.widgets.map((widget) => [widget.id, widget]))
		const laid = page.config.layout.map((item) => item.widgetId)
		for (const [id, component] of [
			['goal-steps', 'GoalStepBar'],
			['goal-progress', 'GoalProgressWidget'],
			['goal-parent', 'GoalParentWidget'],
			['goal-contributions', 'GoalContributionsWidget'],
		]) {
			expect(widgets.get(id), id).toMatchObject({ type: 'custom', component })
			expect(page.slots[`widget-${id}`]).toBe(component)
			expect(registry).toMatch(new RegExp(`\\n\\t${component}: page\\(${component}\\)`))
			expect(laid, id).toContain(id)
		}
		expect(new Set(laid).size).toBe(laid.length)
		expect(page.config.widgets.map((widget) => widget.id).sort()).toEqual([...laid].sort())
	})

	it('adds a sub-goal from the list, which seeds the parent from its filter', () => {
		const list = page.config.widgets.find((widget) => widget.id === 'goal-subgoals').content
		expect(list.filter).toEqual({ parentGoal: '@objectId' })
		expect(list.allowCreate).not.toBe(false)
		expect(readJson('l10n', 'nl.json').translations[list.addLabel]).toBe('Subdoel toevoegen')
	})
})
