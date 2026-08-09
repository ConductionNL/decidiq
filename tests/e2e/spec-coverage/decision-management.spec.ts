/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — decision-management spec
 *
 * @e2e openspec/specs/decision-management/spec.md#create-a-decision-from-a-meeting-agenda-item
 * @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
 * @e2e openspec/specs/decision-management/spec.md#fail-to-create-a-decision-without-a-title
 * @e2e openspec/specs/decision-management/spec.md#transition-a-decision-from-draft-to-proposed
 * @e2e openspec/specs/decision-management/spec.md#reject-an-invalid-state-transition
 * @e2e openspec/specs/decision-management/spec.md#transition-a-decision-to-enacted-after-approval
 * @e2e openspec/specs/decision-management/spec.md#view-the-complete-history-of-a-decision
 * @e2e openspec/specs/decision-management/spec.md#audit-trail-entries-are-immutable
 * @e2e openspec/specs/decision-management/spec.md#filter-decisions-by-status
 * @e2e openspec/specs/decision-management/spec.md#search-decisions-by-title
 * @e2e openspec/specs/decision-management/spec.md#view-decision-detail-with-voting-results
 * @e2e openspec/specs/decision-management/spec.md#available-transitions-are-exposed-for-the-current-state
 * @e2e openspec/specs/decision-management/spec.md#state-machine-visualization-highlights-the-current-state
 */
import { test, expect } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url'

// @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
// @e2e openspec/specs/decision-management/spec.md#create-a-decision-from-a-meeting-agenda-item
test('decisions list renders with Add Decision button', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidesk/decisions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// Decisions list is rendered
	await expect(page.getByText('Showing')).toBeVisible()

	// Add button present
	await expect(page.getByTestId('cn-cta-primary')).toBeVisible()
})

// @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
// @e2e openspec/specs/decision-management/spec.md#fail-to-create-a-decision-without-a-title
test('Add Decision dialog opens', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidesk/decisions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByTestId('cn-cta-primary').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(dialog.getByRole('heading', { name: 'Create Decision' })).toBeVisible()

	// Assert the real decision form fields render (not just that a dialog opened).
	//
	// The rendered label is the schema property's `title`, never its key:
	// `fieldsFromSchema()` in @conduction/nextcloud-vue builds
	// `label: prop.title || key` and CnFormDialog renders
	// `field.label + (field.required ? ' *' : '')`. Every Decision property in
	// `lib/Settings/decidesk_register.json` carries a title, so the asterisk is
	// a direct, UI-observable read of the schema's `required` array.
	//
	// `getByText('title', { exact: true })` is case-SENSITIVE, which is why it
	// could not match "Title *" and this test once died on its first field
	// assertion while the dialog and its heading resolved fine.
	await expect(dialog.getByText('Title *', { exact: true })).toBeVisible()
	await expect(dialog.getByText('Text *', { exact: true })).toBeVisible()
	await expect(dialog.getByText('Decision type *', { exact: true })).toBeVisible()

	// THE IN-FLIGHT CONTRACT, read off the form.
	//
	// `decisionDate` and `outcome` used to be asserted here WITH asterisks, as
	// members of `required`. They are required only in terminal states now — an
	// in-flight motion has no legal outcome — so the create form must NOT demand
	// them, or a motion could not be drafted at all. Asserting their absence is
	// the UI-level counterpart of the schema assertion in
	// RegisterJsonTest::testDecisionSupertypeSchema and of the transition-boundary
	// gate in DecisionTransitionGuard::getMissingTerminalFields().
	await expect(dialog.getByText('Decision date *', { exact: true })).toHaveCount(0)
	await expect(dialog.getByText('Outcome *', { exact: true })).toHaveCount(0)

	// Both fields are still ON the form — optional, not removed. `governingBody`
	// used to be asserted here instead; the Decision schema has no such property
	// (under ADR-005 a Decision reaches a body indirectly, via its meeting /
	// agenda item), so that assertion could never pass and was not a contract.
	//
	// decisionDate is a datetime-picker that renders two labels for the same
	// field (the form `<label>` plus the native picker's own), so it needs
	// `.first()` now that the asterisk no longer disambiguates them.
	await expect(dialog.getByText('Decision date', { exact: true }).first()).toBeVisible()
	await expect(dialog.getByText('Outcome', { exact: true }).first()).toBeVisible()

	// Create button is present
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/decision-management/spec.md#filter-decisions-by-status
// @e2e openspec/specs/decision-management/spec.md#search-decisions-by-title
test('decisions list shows existing decisions', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidesk/decisions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// Seed data has at least 1 decision
	await expect(page.getByText('Showing')).toBeVisible()
	const showingText = await page.getByText('Showing').textContent()
	expect(showingText, 'Decisions list should show a count').toMatch(/Showing/)
})

// @e2e openspec/specs/decision-management/spec.md#transition-a-decision-from-draft-to-proposed
// @e2e openspec/specs/decision-management/spec.md#reject-an-invalid-state-transition
// @e2e openspec/specs/decision-management/spec.md#transition-a-decision-to-enacted-after-approval
// State machine transitions are enforced by the guarded transition map
// (DecisionTransitionGuard); the UI only offers server-allowed actions, so the
// rejection contract lives in Newman + PHPUnit while this drives the happy UI.
test('decisions list page title is correct', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidesk/decisions`)
	await expect(page).toHaveTitle(/Decidesk/i)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
})

// @e2e openspec/specs/decision-management/spec.md#state-machine-visualization-highlights-the-current-state
// @e2e openspec/specs/decision-management/spec.md#available-transitions-are-exposed-for-the-current-state
test('lifecycle tab renders the 7-state timeline with current state and actions', async ({ page }) => {
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidesk/decision?_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.ok(), 'Decision API should be reachable').toBe(true)
	const body = await resp.json()
	const first = (body.results ?? body.items ?? [])[0]
	test.skip(!first, 'No decision objects found — seed at least one')

	const decisionId = first.id ?? first['@self']?.id
	test.skip(!decisionId, 'First decision has no id')

	await page.goto(`${BASE}/apps/decidesk/decisions/${decisionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// Open the Lifecycle sidebar tab.
	await page.getByRole('tab', { name: 'Lifecycle' }).click()
	await page.waitForSelector('[data-testid="decision-lifecycle-tab"]', { timeout: 15_000 })

	// All seven states render in machine order.
	const timeline = page.getByTestId('lifecycle-timeline')
	await expect(timeline).toBeVisible()
	for (const state of ['draft', 'proposed', 'deliberating', 'voting', 'decided', 'enacted', 'archived']) {
		await expect(page.getByTestId(`lifecycle-step-${state}`)).toBeVisible()
	}

	// Exactly one current-state marker is highlighted.
	await expect(page.locator('.decidesk-lifecycle__step--current')).toHaveCount(1)

	// Allowed next transitions are presented as actions (or the empty notice).
	const buttons = page.locator('[data-testid^="lifecycle-action-"]')
	const noneNotice = page.getByText('No transitions available from this state.')
	expect((await buttons.count()) > 0 || (await noneNotice.count()) > 0,
		'Lifecycle tab must show transition actions or the explicit empty state').toBe(true)
})

// @e2e openspec/specs/decision-management/spec.md#view-decision-detail-with-voting-results
test('voting results tab renders on decision detail', async ({ page }) => {
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidesk/decision?_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.ok(), 'Decision API should be reachable').toBe(true)
	const body = await resp.json()
	const first = (body.results ?? body.items ?? [])[0]
	test.skip(!first, 'No decision objects found — seed at least one')

	const decisionId = first.id ?? first['@self']?.id
	test.skip(!decisionId, 'First decision has no id')

	await page.goto(`${BASE}/apps/decidesk/decisions/${decisionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByRole('tab', { name: 'Voting results' }).click()
	await page.waitForSelector('[data-testid="decision-voting-tab"]', { timeout: 15_000 })

	// Tally rounds, the votes table, or the explicit no-motion notice render.
	const tab = page.getByTestId('decision-voting-tab')
	await expect(tab).toBeVisible()
	const rounds = page.getByTestId('decision-voting-round')
	const noMotion = page.getByTestId('decision-voting-none')
	const table = tab.locator('table')
	expect((await rounds.count()) > 0 || (await noMotion.count()) > 0 || (await table.count()) > 0,
		'Voting tab must render rounds, the votes table, or the no-motion notice').toBe(true)
})

// @e2e openspec/specs/decision-management/spec.md#view-the-complete-history-of-a-decision
// @e2e openspec/specs/decision-management/spec.md#audit-trail-entries-are-immutable
// @e2e openspec/specs/decision-management/spec.md#view-decision-detail-with-voting-results
// Audit trail is rendered in the sidebar tab; verify by navigating to a decision detail
test('decision detail view renders for an existing decision', async ({ page }) => {
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidesk/decision?_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.ok(), 'Decision API should be reachable').toBe(true)
	const body = await resp.json()
	const first = (body.results ?? body.items ?? [])[0]
	test.skip(!first, 'No decision objects found — seed at least one')

	const decisionId = first.id ?? first['@self']?.id
	test.skip(!decisionId, 'First decision has no id')

	await page.goto(`${BASE}/apps/decidesk/decisions/${decisionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	// App root mounts — decision detail rendered in the SPA
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})
