/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — GovernanceBodyDetail's `body-factions` facet
 * (src/manifest.json:393, organisation-facet-composition): factions are
 * `governance-body` records with `bodyType: 'faction'` and a `parentBody`
 * reference, not a separate schema — this facet had no e2e coverage.
 *
 * NOT read-only, despite there being seeded factions. The `body-factions`
 * widget filters `parentBody: "@objectId"`, which resolves to the parent
 * council's UUID at render time. The seeded factions "GroenLinks-fractie"
 * and "D66-fractie" (lib/Settings/decidesk_register.json) carry
 * `parentBody: "gemeenteraad-amsterdam"` — OpenRegister's seed importer
 * stores `$ref` values as the raw slug string, not the resolved UUID — so
 * they NEVER match the UUID filter and can never appear in this facet
 * (verified live: `?parentBody=<uuid>&bodyType=faction` returns total:0,
 * `?parentBody=gemeenteraad-amsterdam&bodyType=faction` returns the two
 * seeded rows; confirmed again in the rendered UI, where the Factions
 * facet table is empty of the seeded pair). That is a real product gap
 * (seed data can never surface in this UUID-filtered facet), not a test
 * bug — but this spec's job is to prove the FACET mechanism works, so it
 * creates its own faction with `parentBody` set to the parent's real UUID,
 * asserts it renders, and deletes it in a `finally` block.
 *
 * @e2e openspec/specs/governance-body-crud/spec.md#factions-shown-on-a-bodys-detail-page
 * @e2e openspec/specs/governance-bodies/spec.md#a-faction-references-its-parent-council-via-parentbody
 */
import type { APIRequestContext, Page, PlaywrightWorkerArgs } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidesk`
const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'

/**
 * Basic-auth API context for seeding fixtures (session cookies would trip
 * the CSRF check on writes) — same shape as facets-meeting-detail.spec.ts
 * and agenda-management.spec.ts's `newApiContext`.
 */
async function newApiContext(
	playwright: PlaywrightWorkerArgs['playwright'],
): Promise<APIRequestContext> {
	return playwright.request.newContext({
		extraHTTPHeaders: {
			Authorization:
				'Basic '
				+ Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
	})
}

/** Extract the OR object id from a create response body. */
function objectId(body: any): string | null {
	return body?.id ?? body?.['@self']?.id ?? null
}

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

// @e2e openspec/specs/governance-body-crud/spec.md#factions-shown-on-a-bodys-detail-page
// @e2e openspec/specs/governance-bodies/spec.md#a-faction-references-its-parent-council-via-parentbody
test("GovernanceBodyDetail: factions facet lists a faction created with the parent's real UUID", async ({
	page,
	playwright,
}) => {
	// Detail pages are widget-heavy: GovernanceBodyDetail declares 16 widgets,
	// each an object-list query costing ~1s on this instance, on top of the
	// pre-mount initializeStores() settings round trip every navigation
	// blocks on — the 30s global test timeout is nowhere near enough.
	test.setTimeout(120_000)

	const bodyId = await findGovernanceBodyId(page, 'Gemeenteraad Amsterdam')
	test.skip(!bodyId, 'Seed governance body "Gemeenteraad Amsterdam" not found')

	const api = await newApiContext(playwright)
	let factionId: string | null = null
	try {
		const factionName = `E2E facet faction ${Date.now()}`
		const factionResp = await api.post(`${OR}/governance-body`, {
			data: {
				name: factionName,
				bodyType: 'faction',
				domain: 'municipality',
				// The facet widget filters `parentBody: "@objectId"`, which
				// resolves to the parent's UUID — so the fixture must use the
				// UUID too, not the slug the seed data (wrongly) carries.
				parentBody: bodyId,
			},
		})
		test.skip(
			!factionResp.ok(),
			`Could not seed faction governance-body (HTTP ${factionResp.status()})`,
		)
		factionId = objectId(await factionResp.json())
		test.skip(!factionId, 'Seeded faction has no id')

		await page.goto(`${BASE}/apps/decidesk/governance-bodies/${bodyId}`)
		// app-root appearing only proves the shell mounted, not that data has
		// arrived — mount itself blocks on initializeStores()'s settings round
		// trip, so 30s (double the old budget) before even the shell shows up.
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		// GovernanceBodyDetail issues ~16 widget queries at ~1s each after mount;
		// give content assertions real headroom instead of the 10s expect default.
		// The body's own data widget renders its name — proves the page loaded
		// real content, not an empty shell.
		await expect(
			page.getByText('Gemeenteraad Amsterdam', { exact: true }).first(),
		).toBeVisible({ timeout: 45_000 })

		await expect(
			page.getByRole('heading', { name: 'Factions', exact: true }),
		).toBeVisible({ timeout: 45_000 })
		await expect(page.getByText(factionName, { exact: true })).toBeVisible({
			timeout: 45_000,
		})
	} finally {
		if (factionId) {
			await api.delete(`${OR}/governance-body/${factionId}`).catch(() => null)
		}
		await api.dispose()
	}
})
