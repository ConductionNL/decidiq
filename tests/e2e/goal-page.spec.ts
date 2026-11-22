/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the goal page on the municipality example goal "Verlichte en
 * veilige fietsroutes in 2027" (change goals-linked-to-decisions, task 4:
 * REQ-011, REQ-012 and REQ-014).
 *
 * WHAT THIS ASSERTS THAT VITEST CANNOT
 * ------------------------------------
 * tests/vitest/goalPage.spec.js and goalSubgoalsAndContributions.spec.js pin
 * the rules that turn lists into lines and rows. Only a real instance shows
 * that the page fetches the four schemas that name the goal, that the counts
 * on the page are the counts of the seeded objects, and that a sub-goal saved
 * with this goal as its parent appears in the Sub-goals table.
 *
 * The example goal is only read. The one sub-goal the last test creates goes
 * through the OpenRegister object API, is tracked in the ledger and removed
 * in afterAll. Without the municipality set the goal is not there and the
 * suite skips, saying so.
 *
 * Written, not run: it needs a live instance with the municipality example set.
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md
 * @e2e organisation-goals::four-kinds-of-contribution
 * @e2e organisation-goals::commitments-on-the-renamed-schema-count
 * @e2e organisation-goals::the-target-value
 * @e2e organisation-goals::adding-a-subgoal
 * @e2e organisation-goals::parent-card
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	BASE,
	cleanupAll,
	createObject,
	listObjects,
	newLedger,
	objId,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-goal-${ledger.runId}`
const APP = `${BASE}/index.php/apps/decidiq`

const EXAMPLE_GOAL = 'Verlichte en veilige fietsroutes in 2027'
const PARENT_GOAL = 'Mobiliteitsvisie 2030'
const ACHIEVED_SUBGOAL = 'Schoolroutes veilig ingericht'

test.describe.configure({ mode: 'serial' })

/**
 * The seeded goal with this title, or null when the example set is not loaded.
 *
 * @param page  The page whose session reads the register.
 * @param title The goal's title.
 * @return The goal object, or null.
 */
async function goalTitled(page: Page, title: string): Promise<Record<string, any> | null> {
	const goals = await listObjects(page, 'goal', 500)
	return goals.find((goal) => goal.title === title) ?? null
}

/**
 * The number of objects of a schema whose `goal` is this goal.
 *
 * @param page   The page whose session reads the register.
 * @param schema The schema slug.
 * @param goalId The goal's id.
 * @param where  An extra condition on each object.
 * @return The count.
 */
async function countFor(page: Page, schema: string, goalId: string, where: (o: Record<string, any>) => boolean = () => true): Promise<number> {
	const objects = await listObjects(page, schema, 500)
	return objects.filter((o) => o.goal === goalId && where(o)).length
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

test.describe('the goal page on the example goal (REQ-011, REQ-012, REQ-014)', () => {
	// @e2e organisation-goals::four-kinds-of-contribution
	test('lists what contributes to the goal, all four kinds, with the total in the heading', async ({ page }) => {
		const goal = await goalTitled(page, EXAMPLE_GOAL)
		test.skip(goal === null, 'the municipality example set is not loaded')
		const id = objId(goal)

		const expected = (await countFor(page, 'governance-commitment', id))
			+ (await countFor(page, 'action-item', id))
			+ (await countFor(page, 'planned-agenda-item', id))
			+ (await countFor(page, 'decision', id))
		expect(expected).toBeGreaterThan(0)

		await page.goto(`${APP}/goals/${id}`)
		const list = page.getByTestId('goal-contributions')
		await expect(list).toBeVisible()
		await expect(page.getByTestId('goal-contributions-total')).toHaveText(String(expected))
		for (const kind of ['commitment', 'action-item', 'planned-agenda-item', 'decision']) {
			await expect(list.getByTestId(`goal-contribution-${kind}`).first(), kind).toBeVisible()
		}
		// The planned agenda item's term is its planned period, never empty.
		const planned = list.getByTestId('goal-contribution-planned-agenda-item').first()
		await expect(planned.locator('td').nth(2)).not.toHaveText('')
		// Every row opens the object's own page.
		await expect(list.getByRole('link').first()).toHaveAttribute('href', /\/apps\/decidiq\//)
	})

	// @e2e organisation-goals::commitments-on-the-renamed-schema-count
	// @e2e organisation-goals::the-target-value
	test('the progress card reads as counts of the live schemas and the target with its unit', async ({ page }) => {
		const goal = await goalTitled(page, EXAMPLE_GOAL)
		test.skip(goal === null, 'the municipality example set is not loaded')
		const id = objId(goal)

		const commitments = await countFor(page, 'governance-commitment', id)
		const settled = await countFor(page, 'governance-commitment', id, (c) => c.lifecycle === 'disposed')
		const actions = await countFor(page, 'action-item', id)
		const done = await countFor(page, 'action-item', id, (a) => a.taskStatus === 'completed')

		await page.goto(`${APP}/goals/${id}`)
		await expect(page.getByTestId('goal-progress')).toBeVisible()
		await expect(page.getByTestId('goal-progress-target')).toContainText(`${goal.currentValue} of ${goal.targetValue}`)
		await expect(page.getByTestId('goal-progress-target')).toContainText(String(goal.unit))
		await expect(page.getByTestId('goal-progress-commitments')).toContainText(`${settled} of ${commitments}`)
		await expect(page.getByTestId('goal-progress-action-items')).toContainText(`${done} of ${actions}`)
	})

	// @e2e organisation-goals::parent-card
	test('a sub-goal\'s parent card says how many of the parent\'s sub-goals are reached', async ({ page }) => {
		const goal = await goalTitled(page, EXAMPLE_GOAL)
		const child = await goalTitled(page, ACHIEVED_SUBGOAL)
		test.skip(goal === null || child === null, 'the municipality example set is not loaded')

		const siblings = (await listObjects(page, 'goal', 500)).filter((g) => g.parentGoal === objId(goal))
		const reached = siblings.filter((g) => g.status === 'achieved').length

		await page.goto(`${APP}/goals/${objId(child)}`)
		await expect(page.getByTestId('goal-parent-link')).toContainText(EXAMPLE_GOAL)
		await expect(page.getByTestId('goal-parent-summary')).toContainText(`${siblings.length} sub-goals, of which ${reached} reached`)

		// The top of the tree has no parent.
		const top = await goalTitled(page, PARENT_GOAL)
		test.skip(top === null, 'the municipality example set has no parent goal')
		await page.goto(`${APP}/goals/${objId(top)}`)
		await expect(page.getByTestId('goal-parent')).toContainText('This goal has no parent goal.')
	})

	// @e2e organisation-goals::adding-a-subgoal
	test('a goal saved with this goal as its parent appears in the Sub-goals table', async ({ page }) => {
		const goal = await goalTitled(page, EXAMPLE_GOAL)
		test.skip(goal === null, 'the municipality example set is not loaded')

		await page.goto(`${APP}/goals/${objId(goal)}`)
		await expect(page.getByRole('button', { name: 'Add sub-goal' })).toBeVisible()

		// The form is the shared object form; what this change owns is that the
		// list seeds parentGoal and shows the result, so the save goes through
		// the API with the parent the form would fill in.
		const title = `${tag}-Schoolroutes veilig ingericht`
		await createObject(page, ledger, 'goal', {
			title,
			description: 'Created by the goal page e2e test.',
			horizon: goal.horizon,
			body: goal.body,
			deadline: goal.deadline,
			status: 'active',
			parentGoal: objId(goal),
		})

		await page.reload()
		await expect(page.getByRole('row').filter({ hasText: title })).toBeVisible()
	})
})
