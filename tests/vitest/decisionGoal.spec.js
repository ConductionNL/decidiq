/**
 * goals-linked-to-decisions REQ-010: a decision names the goal it serves, the
 * picker offers only unfinished goals and the decision page links the goal.
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { goalIdOf, goalLabel, goalLocation, OFFERED_GOAL_STATES } from '../../src/utils/decisionGoal.js'

const root = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'))

describe('decision goal helpers', () => {
	it('reads the goal id, trimmed, and nothing for a decision without one', () => {
		expect(goalIdOf({ goal: ' goal-a ' })).toBe('goal-a')
		expect(goalIdOf({ goal: null })).toBe('')
		expect(goalIdOf({})).toBe('')
		expect(goalIdOf(null)).toBe('')
		expect(goalIdOf({ goal: 42 })).toBe('')
	})

	it('links to the goal page and has no link without a goal', () => {
		expect(goalLocation('goal-a')).toEqual({ name: 'GoalDetail', params: { id: 'goal-a' } })
		expect(goalLocation('')).toBeNull()
	})

	it('labels the goal by its title, else its id', () => {
		expect(goalLabel({ title: ' Verlichte en veilige fietsroutes in 2027 ' }, 'goal-a')).toBe('Verlichte en veilige fietsroutes in 2027')
		expect(goalLabel({ title: '' }, 'goal-a')).toBe('goal-a')
		expect(goalLabel(null, 'goal-a')).toBe('goal-a')
	})
})

describe('decision goal wiring', () => {
	it('scopes the goal picker to draft, active and at-risk goals', () => {
		const fragment = read('lib/Settings/register.d/132-goals-linked-to-decisions.json')
		const goal = fragment.components.schemas.Decision.properties.goal
		expect(goal.$ref).toBe('Goal')
		expect(goal.nullable).toBe(true)
		expect(goal['x-relation-filter']).toEqual({ status: OFFERED_GOAL_STATES })
		expect(OFFERED_GOAL_STATES).not.toContain('achieved')
		expect(OFFERED_GOAL_STATES).not.toContain('abandoned')
	})

	it('shows the goal on the decision page: editable in Governance, linked in its own widget', () => {
		const page = read('src/manifest.json').pages.find((p) => p.id === 'DecisionDetail')
		const governance = page.config.widgets.find((w) => w.id === 'decision-governance')
		expect(governance.content.include).toContain('goal')
		const widget = page.config.widgets.find((w) => w.id === 'decision-goal')
		expect(widget).toMatchObject({ type: 'custom', component: 'DecisionGoalWidget' })
		expect(page.slots['widget-decision-goal']).toBe('DecisionGoalWidget')
		expect(page.config.layout.some((l) => l.widgetId === 'decision-goal')).toBe(true)
		const goalPage = read('src/manifest.d/organisation-goals.json').pages.find((p) => p.id === 'GoalDetail')
		expect(goalPage.route).toBe('/goals/:id')
	})

	it('registers the widget', () => {
		const registry = fs.readFileSync(path.join(root, 'src/registry.js'), 'utf8')
		expect(registry).toMatch(/DecisionGoalWidget: page\(DecisionGoalWidget\)/)
	})
})
