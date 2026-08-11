/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The three per-object "integrations" surfaces (ADR-019 pluggable integration
 * registry), driven through the real SPA against real seeded objects.
 *
 * WHY THIS FILE LIVES IN workflows/ AND NOT IN visual/
 * ---------------------------------------------------
 * CI loads `tests/e2e/playwright.config.ts`, whose only chromium project
 * carries `testIgnore: ['**\/docs-screenshots.spec.ts', '**\/visual\/**']`.
 * Nothing under `tests/e2e/visual/` is ever executed by CI, so a screenshot
 * baseline dropped there would satisfy the visual-coverage gate while never
 * running. `tests/e2e/workflows/` is executed, so that is where a load-bearing
 * page proof belongs.
 *
 * WHAT EACH TEST HAS TO ASSERT TO BE WORTH ANYTHING
 * -------------------------------------------------
 * Nextcloud paints its header, navigation and app shell on every route,
 * including routes that redirected or rendered an empty page host. So
 * "the app mounted" is worth nothing as a page proof. Every test below asserts
 * a handle that ONLY its own surface renders, and that the sibling surfaces'
 * handles are absent — the three routes share one renderer, and a resolution
 * fall-through is a failure mode this app has actually shipped
 * (src/registry.js records the motion page being named by the manifest but
 * never registered, so "resolution fell through and the page rendered
 * NOTHING", which no shell-level assertion would have caught).
 *
 * THE TWO KINDS OF PAGE ARE NOT INTERCHANGEABLE, AND THAT IS THE POINT
 * -------------------------------------------------------------------
 * `/motions/:id/integrations` is a manifest page of `type: "custom"`: the
 * renderer resolves `component` through src/registry.js and mounts
 * MotionIntegrations.vue, so the assertion is that component's own root and
 * copy. `/decisions/:id/integrations` and `/agenda-items/:id/integrations` are
 * `type: "detail"` pages with a declarative body — the renderer builds them
 * from `config.widgets` + `config.layout` and never resolves `component` at
 * all. Both of those used to name a `.vue` that could not render and did not;
 * the files are gone and these tests assert the widgets the manifest declares,
 * which is the surface a user actually gets.
 *
 * The motion page's back control is exercised rather than merely located: a
 * button that is visible but wired to a route name that does not resolve is a
 * defect a visibility assertion cannot see.
 *
 * NO SPEC ANCHORS HERE, DELIBERATELY. These three routes have no Scenario in
 * openspec/specs — I wrote two anchors for slugs that sounded right, and they
 * resolved to nothing. A traceability anchor that resolves to nothing is never
 * reported by the coverage gate, so it survives indefinitely while reading like
 * proof; there are twelve more of them in this suite, filed rather than
 * repointed. Repointing one at a scenario whose steps the body does not
 * actually perform would be worse than leaving it off, and writing the missing
 * Scenario here would be authoring the spec this suite is checked against.
 * Tracked as an issue instead.
 */
import { test, expect, type Page } from '@playwright/test'

import {
	BASE,
	newLedger,
	createObject,
	cleanupAll,
	objId,
	type SeedLedger,
} from './governance-fixture'

let ledger: SeedLedger

test.beforeAll(() => {
	ledger = newLedger()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

/**
 * Locate the handle unique to each integrations surface, so a test can prove
 * it landed on ITS page and not on a sibling.
 *
 * The motion page is `type: "custom"` and its handle is the component's own
 * root testid. The other two are `type: "detail"`: CnDetailPage lays their
 * body out as a grid of widgets, each rendered as a `role="group"` named by
 * the widget id the manifest declares — `di-*` for the decision page, `ai-*`
 * for the agenda item, and never shared between them.
 *
 * ⚠️ `exact: true` is load-bearing, not tidiness. `getByRole`'s `name` option
 * matches a SUBSTRING by default, so renaming the manifest's `di-deck` widget
 * to `MUTANT-di-deck` still satisfied `{ name: 'di-deck' }` and the mutation
 * control came back green — a null result that reads exactly like a test which
 * cannot fail. The locator, not the test, was the thing that was broken.
 */
function handle(page: Page, surface: 'decision' | 'motion' | 'agendaItem') {
	if (surface === 'motion') return page.getByTestId('motion-integrations')
	const widgetId = surface === 'decision' ? 'di-email' : 'ai-tasks'
	return page.getByRole('group', { name: widgetId, exact: true })
}

const SURFACES = ['decision', 'motion', 'agendaItem'] as const

/**
 * Navigate to an integrations route and wait for the SPA to finish booting.
 *
 * `initializeStores()` is awaited before `createApp().mount()` (src/main.js),
 * so every navigation blocks on a settings round-trip; waiting for `app-root`
 * is waiting for that, not for the view.
 */
async function openIntegrations(page: Page, route: string, id: string): Promise<void> {
	await page.goto(`${BASE}/apps/decidesk/${route}/${id}/integrations`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 20_000 })
}

/** Assert we are on `expected` and on none of its two siblings. */
async function expectOnlyThisSurface(page: Page, expected: typeof SURFACES[number]): Promise<void> {
	await expect(handle(page, expected)).toBeVisible({ timeout: 20_000 })
	for (const other of SURFACES) {
		if (other === expected) continue
		await expect(handle(page, other)).toHaveCount(0)
	}
}

test.describe('per-object integration surfaces', () => {
	test('decision integrations surface renders its own body and returns to the decision', async ({ page }) => {
		const decision = await createObject(page, ledger, 'decision', {
			title: 'E2E integrations decision',
			text: 'Decision used to prove the integrations surface renders.',
			decisionType: 'meeting-outcome',
			decisionDate: '2026-04-10T20:00:00Z',
			outcome: 'adopted',
			lifecycle: 'enacted',
		})
		const id = objId(decision)

		await openIntegrations(page, 'decisions', id)
		await expectOnlyThisSurface(page, 'decision')
		// The seeded object is resolved, not just the page shell.
		await expect(page.getByRole('heading', { name: 'E2E integrations decision' }))
			.toBeVisible({ timeout: 20_000 })
		// Body copy the DECISION page's manifest entry declares and no other
		// page does — "the Action items board (Deck)" appears only here.
		await expect(page.getByRole('main'))
			.toContainText(/linked Emails, the Action items board \(Deck\) and files/i)
		// All three declared widgets are laid out, not just the first.
		for (const widgetId of ['di-email', 'di-deck', 'di-files']) {
			await expect(page.getByRole('group', { name: widgetId, exact: true })).toBeVisible()
		}
	})

	test('motion integrations surface renders its own body and returns to the motion', async ({ page }) => {
		// ADR-005: a motion is a Decision carrying decisionType 'motion'; there
		// is no `motion` schema (addressing one 404s "Schema not found").
		const motion = await createObject(page, ledger, 'decision', {
			title: 'E2E integrations motion',
			text: 'Motion used to prove the integrations surface renders.',
			decisionType: 'motion',
			decisionDate: '2026-04-10T20:00:00Z',
			outcome: 'adopted',
			lifecycle: 'deliberating',
		})
		const id = objId(motion)

		await openIntegrations(page, 'motions', id)
		await expectOnlyThisSurface(page, 'motion')
		// Copy only MotionIntegrations.vue renders. This is the page
		// src/registry.js records as having once rendered NOTHING because the
		// manifest named a component the registry never registered, so a
		// distinguishing assertion is the whole point of the test.
		await expect(page.getByTestId('motion-integrations'))
			.toContainText(/Discussion tab is provided by the Talk integration leaf/i)

		// Exercise the control, do not merely find it: it pushes a NAMED route,
		// and a name that no longer resolves is invisible to a visibility check.
		await page.getByTestId('motion-integrations-back').click()
		await expect(page).toHaveURL(new RegExp(`/motions/${id}$`), { timeout: 20_000 })
		await expect(page.getByTestId('motion-integrations')).toHaveCount(0)
	})

	test('agenda-item integrations surface renders its declared widget body', async ({ page }) => {
		const item = await createObject(page, ledger, 'agenda-item', {
			title: 'E2E integrations agenda item',
			itemType: 'discussion',
			orderNumber: 1,
		})
		const id = objId(item)

		await openIntegrations(page, 'agenda-items', id)
		await expectOnlyThisSurface(page, 'agendaItem')
		await expect(page.getByRole('heading', { name: 'E2E integrations agenda item' }))
			.toBeVisible({ timeout: 20_000 })
		// Copy the AGENDA-ITEM page's manifest entry declares and no other does.
		await expect(page.getByRole('main'))
			.toContainText(/linked Emails, files and tasks surface on the body/i)
		for (const widgetId of ['ai-email', 'ai-files', 'ai-tasks']) {
			await expect(page.getByRole('group', { name: widgetId, exact: true })).toBeVisible()
		}
	})
})
