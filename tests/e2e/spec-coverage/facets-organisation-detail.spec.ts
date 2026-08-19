/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — GovernanceBodyDetail's `body-factions` facet
 * (src/manifest.json:393, organisation-facet-composition): factions are
 * `governance-body` records with `bodyType: 'faction'` and a `parentBody`
 * reference, not a separate schema — this facet had no e2e coverage.
 *
 * Read-only: the seeded factions "GroenLinks-fractie" and "D66-fractie"
 * (lib/Settings/decidesk_register.json, both `parentBody:
 * gemeenteraad-amsterdam`) already exercise the parent linkage, so this file
 * creates no fixtures and needs no cleanup.
 *
 * @e2e openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#scenario-factions-shown-on-a-bodys-detail-page
 * @e2e openspec/changes/organisation-facet-composition/specs/governance-bodies/spec.md#scenario-a-faction-references-its-parent-council-via-parentbody
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidesk`

/** Find a seeded GovernanceBody's id by its exact `name`, via the authenticated page session (read-only, no CSRF concerns). */
async function findGovernanceBodyId(
	page: Page,
	name: string,
): Promise<string | null> {
	const resp = await page.request.get(`${OR}/governance-body?_limit=200`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) return null
	const body = await resp.json()
	const rows = body.results ?? body.items ?? []
	const match = rows.find((r: any) => r.name === name)
	return match ? (match.id ?? match['@self']?.id ?? null) : null
}

// @e2e openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#scenario-factions-shown-on-a-bodys-detail-page
// @e2e openspec/changes/organisation-facet-composition/specs/governance-bodies/spec.md#scenario-a-faction-references-its-parent-council-via-parentbody
test('GovernanceBodyDetail: factions facet lists the seeded factions under their parent council', async ({
	page,
}) => {
	// Detail pages are widget-heavy (16 widgets on GovernanceBodyDetail); 35s
	// matches the established budget from agenda-management.spec.ts.
	test.setTimeout(35_000)

	const bodyId = await findGovernanceBodyId(page, 'Gemeenteraad Amsterdam')
	test.skip(!bodyId, 'Seed governance body "Gemeenteraad Amsterdam" not found')

	await page.goto(`${BASE}/apps/decidesk/governance-bodies/${bodyId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// The body's own data widget renders its name — proves the page loaded
	// real content, not an empty shell.
	await expect(
		page.getByText('Gemeenteraad Amsterdam', { exact: true }).first(),
	).toBeVisible()

	await expect(
		page.getByRole('heading', { name: 'Factions', exact: true }),
	).toBeVisible()
	await expect(page.getByText('GroenLinks-fractie', { exact: true })).toBeVisible()
	await expect(page.getByText('D66-fractie', { exact: true })).toBeVisible()
})
