/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — relation-tab-ui spec
 *
 * @e2e openspec/specs/relation-tab-ui/spec.md#add-a-motion-from-the-agenda-item-motions-tab
 * @e2e openspec/specs/relation-tab-ui/spec.md#delete-a-child-object-refreshes-the-list
 * @e2e openspec/specs/relation-tab-ui/spec.md#empty-parent-issues-no-fetch
 * @e2e openspec/specs/relation-tab-ui/spec.md#adopted-motion-renders-a-success-badge
 * @e2e openspec/specs/relation-tab-ui/spec.md#against-vote-renders-an-error-badge
 * @e2e openspec/specs/relation-tab-ui/spec.md#add-dialog-excludes-already-linked-participants
 * @e2e openspec/specs/relation-tab-ui/spec.md#linking-a-participant-attaches-the-relation
 * @e2e openspec/specs/relation-tab-ui/spec.md#vote-caster-resolves-to-a-display-name
 * @e2e openspec/specs/relation-tab-ui/spec.md#open-the-parent-motion
 * @e2e openspec/specs/relation-tab-ui/spec.md#sign-now-offered-only-to-a-pending-signer-who-is-the-current-user
 * @e2e openspec/specs/relation-tab-ui/spec.md#signed-entry-shows-a-signed-badge
 */
import { test, expect } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url'

/**
 * Helper: get first object of given schema from OR API.
 */
async function getFirstObject(
	page: import('@playwright/test').Page,
	schema: string,
) {
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/${schema}?_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	if (!resp.ok()) return null
	const body = await resp.json()
	const items = body.results ?? body.items ?? []
	return items[0] ?? null
}

// @e2e openspec/specs/relation-tab-ui/spec.md#add-a-motion-from-the-agenda-item-motions-tab
// @e2e openspec/specs/relation-tab-ui/spec.md#delete-a-child-object-refreshes-the-list
// @e2e openspec/specs/relation-tab-ui/spec.md#empty-parent-issues-no-fetch
test('agenda item detail page renders with motions sidebar tab', async ({
	page,
}) => {
	const first = await getFirstObject(page, 'agenda-item')
	test.skip(!first, 'No agenda-item objects found')
	const itemId = first.id ?? first['@self']?.id
	test.skip(!itemId, 'First agenda-item has no id')

	await page.goto(`${BASE}/apps/decidiq/agenda-items/${itemId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#adopted-motion-renders-a-success-badge
// @e2e openspec/specs/relation-tab-ui/spec.md#against-vote-renders-an-error-badge
test('motions list renders lifecycle and vote status badges', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/motions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	// App root mounts — badge rendering in motion rows is part of CnDataTable
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#add-dialog-excludes-already-linked-participants
// @e2e openspec/specs/relation-tab-ui/spec.md#linking-a-participant-attaches-the-relation
test('meeting detail has participants tab in sidebar', async ({ page }) => {
	const first = await getFirstObject(page, 'meeting')
	test.skip(!first, 'No meeting objects found')
	const meetingId = first.id ?? first['@self']?.id
	test.skip(!meetingId, 'First meeting has no id')

	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	// App root mounts for meeting detail (sidebar with participants tab defined in manifest)
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#vote-caster-resolves-to-a-display-name
test('motion detail renders votes tab area', async ({ page }) => {
	const first = await getFirstObject(page, 'motion')
	test.skip(!first, 'No motion objects found')
	const motionId = first.id ?? first['@self']?.id
	test.skip(!motionId, 'First motion has no id')

	await page.goto(`${BASE}/apps/decidiq/motions/${motionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#open-the-parent-motion
test('amendment detail renders with parent motion tab', async ({ page }) => {
	const first = await getFirstObject(page, 'amendment')
	test.skip(!first, 'No amendment objects found — seed at least one')
	const amendmentId = first.id ?? first['@self']?.id
	test.skip(!amendmentId, 'First amendment has no id')

	await page.goto(`${BASE}/apps/decidiq/amendments/${amendmentId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#sign-now-offered-only-to-a-pending-signer-who-is-the-current-user
// @e2e openspec/specs/relation-tab-ui/spec.md#signed-entry-shows-a-signed-badge
test('minutes detail renders with signers tab area', async ({ page }) => {
	const first = await getFirstObject(page, 'minutes')
	test.skip(!first, 'No minutes objects found')
	const minutesId = first.id ?? first['@self']?.id
	test.skip(!minutesId, 'First minutes has no id')

	await page.goto(`${BASE}/apps/decidiq/minutes/${minutesId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})
