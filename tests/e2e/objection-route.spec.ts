// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: an objection runs as a decision through its configured
 * route (objection-advisory-committee, REQ-OAC-008).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * `DecisionTermCalculatorTest` and `DecisionTermListenerTest` pin the term
 * arithmetic against doubles, and `ObjectionRouteConfigurationTest` the seeded
 * configuration as JSON. Only a real instance shows that the municipality set
 * really imported the `bezwaar-decision` type and its `bezwaar-standaard`
 * route, that OpenRegister runs the listener so `termEndsAt` is written on the
 * saved decision, and that the decision page draws the route's steps with the
 * current one marked.
 *
 * WHAT IT CREATES AND REMOVES. Two decisions (the refused permit and the
 * objection against it) and the objection's route stages, all tracked in the
 * ledger and removed in afterAll. The seeded type, route and committee are only
 * read. Without the municipality set the type is not there and the suite
 * skips, saying so.
 *
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	BASE,
	cleanupAll,
	createObject,
	deleteObject,
	getObject,
	listObjects,
	newLedger,
	objId,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()

/** The contested decision's publication day and its objection term. */
const PUBLISHED = '2026-06-22'
const OBJECTION_DAYS = 42

/**
 * A YYYY-MM-DD day plus a number of days.
 *
 * @param day  The day.
 * @param days The days to add.
 * @return The resulting day.
 */
function plusDays(day: string, days: number): string {
	const date = new Date(`${day}T00:00:00Z`)
	date.setUTCDate(date.getUTCDate() + days)
	return date.toISOString().slice(0, 10)
}

/**
 * The seeded objection type and route, or null when the municipality set is not loaded.
 *
 * @param page The page.
 * @return The type and route objects.
 */
async function objectionConfiguration(page: Page): Promise<{ type: any; route: any } | null> {
	const type = (await listObjects(page, 'decision-template', 500)).find((row) => row.slug === 'bezwaar-decision')
	const route = (await listObjects(page, 'approval-route', 500)).find((row) => row.slug === 'bezwaar-standaard')
	if (type === undefined || route === undefined) {
		return null
	}

	return { type, route }
}

test.describe('An objection runs as a decision through its configured route', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	let config: { type: any; route: any } | null = null
	let objectionId = ''
	let expectedTermEnd = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		config = await objectionConfiguration(page)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		// The route stages are not in the shared teardown order; they go first.
		for (const id of ledger.created['decision-stage'] ?? []) {
			await deleteObject(page, 'decision-stage', id)
		}
		delete ledger.created['decision-stage']
		await cleanupAll(page, ledger)
		await page.close()
	})

	test('the bezwaar-decision type carries a twelve-week term and its route the five steps', async () => {
		test.skip(config === null, 'the municipality example set (bezwaar-decision, bezwaar-standaard) is not loaded')
		expect(config!.type.termPeriod).toBe('P12W')
		expect(config!.type.termStartsAt).toBe('objection-period-end')

		const steps = (config!.route.steps ?? []).map((step: any) => step.label)
		expect(steps).toEqual([
			'Ontvangst en ontvankelijkheid',
			'Hoorzitting',
			'Advies bezwaaradviescommissie',
			expect.any(String),
			expect.any(String),
		])
		const adviser = (config!.route.steps ?? []).find((step: any) => step.label === 'Advies bezwaaradviescommissie')
		expect(adviser.actorType, 'the advice is asked of a body, the advisory committee').toBe('body')
	})

	test('a clerk records an objection, and its term is counted from the end of the objection period', async ({ page }) => {
		test.skip(config === null, 'the municipality example set is not loaded')

		const contested = await createObject(page, ledger, 'decision', {
			title: `${ledger.runId} Weigering omgevingsvergunning Kerkstraat 12`,
			text: 'De aanvraag om een omgevingsvergunning voor Kerkstraat 12 wordt geweigerd.',
			decisionType: 'permit',
			decisionDate: `${PUBLISHED}T09:00:00+00:00`,
			publishedAt: `${PUBLISHED}T09:00:00+00:00`,
			legalRemedyClause: { kind: 'bezwaar', termDays: OBJECTION_DAYS, text: 'Binnen zes weken bezwaar.' },
		})
		const stored = await getObject(page, 'decision', objId(contested))
		const termDays = Number(stored?.legalRemedyClause?.termDays ?? OBJECTION_DAYS)
		const published = String(stored?.publishedAt ?? stored?.decisionDate ?? PUBLISHED).slice(0, 10)
		// Twelve weeks after the objection period ends.
		expectedTermEnd = plusDays(plusDays(published, termDays), 84)

		const objection = await createObject(page, ledger, 'decision', {
			title: `${ledger.runId} Bezwaar J. Vos tegen weigering Kerkstraat 12`,
			text: 'J. Vos maakt bezwaar tegen de weigering.',
			decisionType: 'bezwaar-decision',
			type: objId(config!.type),
			contests: objId(contested),
			receivedAt: '2026-08-04',
		})
		objectionId = objId(objection)

		const saved = await getObject(page, 'decision', objectionId)
		expect(String(saved?.termEndsAt ?? '').slice(0, 10), 'the server wrote the term').toBe(expectedTermEnd)

		// The receipt day does not move a term counted from the objection period.
		const moved = await page.request.patch(`${BASE}/index.php/apps/openregister/api/objects/decidiq/decision/${objectionId}`, {
			headers: await writeHeaders(page),
			data: { receivedAt: '2026-08-11' },
		})
		expect(moved.ok(), await moved.text()).toBe(true)
		const again = await getObject(page, 'decision', objectionId)
		expect(String(again?.termEndsAt ?? '').slice(0, 10)).toBe(expectedTermEnd)
	})

	test('the objection page shows the route with receipt as the current step', async ({ page }) => {
		test.skip(config === null || objectionId === '', 'needs the objection from the previous test')

		const instantiated = await page.request.post(`${BASE}/index.php/apps/decidiq/api/approval-routes/instantiate`, {
			headers: await writeHeaders(page),
			data: { route: config!.route, subject: objectionId, subjectSchema: 'decision' },
		})
		expect(instantiated.status(), await instantiated.text()).toBeLessThan(300)
		const stages = await instantiated.json()
		for (const stage of (Array.isArray(stages) ? stages : (stages.stages ?? []))) {
			;(ledger.created['decision-stage'] ??= []).push(objId(stage))
		}

		await page.goto(`${BASE}/index.php/apps/decidiq/decisions/${objectionId}`)
		const route = page.getByTestId('decision-route-tab')
		await expect(route).toBeVisible({ timeout: 45_000 })
		const timeline = route.getByTestId('route-timeline')
		await expect(timeline).toBeVisible({ timeout: 45_000 })
		for (const label of ['Ontvangst en ontvankelijkheid', 'Hoorzitting', 'Advies bezwaaradviescommissie']) {
			await expect(timeline).toContainText(label)
		}
		const first = timeline.locator('[data-testid^="route-stage-"]').first()
		await expect(first).toContainText('Ontvangst en ontvankelijkheid')
		await expect(first).toContainText(/Current|Huidig/)
	})
})
