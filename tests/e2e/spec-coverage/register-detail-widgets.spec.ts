/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — the three register-detail-optimisation catalog
 * widgets: `version-timeline` (RegisterVersionTimelineWidget, on
 * RegelingDetail), `delegation-chain` (DelegationChainWidget, on
 * BevoegdheidstoedelingDetail) and `confidentiality-status-timeline`
 * (ConfidentialityStatusTimelineWidget, on GeheimhoudingDetail). None of the
 * three had e2e coverage — a green suite proved nothing about whether they
 * render.
 *
 * Read-only throughout: every case uses an existing seed object (found via
 * the OpenRegister object API, read through the authenticated page session)
 * rather than a created fixture, per the "prefer existing seed data" rule —
 * lib/Settings/register.d/53-verordeningenregister.json,
 * 54-delegatie-mandaatregister.json and 65-embargo-geheimhouding.json all
 * ship `x-openregister.seedData` that already demonstrates each widget's
 * populated state. No fixtures are created, so no cleanup is needed.
 *
 * @e2e openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
 * @e2e openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
 * @e2e openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
 * @e2e openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidesk`

/** GET a collection through the authenticated page session (read-only). */
async function listObjects(page: Page, schema: string): Promise<any[]> {
	const resp = await page.request.get(`${OR}/${schema}?_limit=200`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) return []
	const body = await resp.json()
	return body.results ?? body.items ?? []
}

/** Extract the OR object id (covers both id shapes). */
function objId(o: any): string {
	return o?.id ?? o?.['@self']?.id ?? ''
}

// @e2e openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
test('RegelingDetail: version-timeline widget renders both seeded versions of Afvalstoffenverordening Amsterdam', async ({
	page,
}) => {
	test.setTimeout(35_000)

	const regelingen = await listObjects(page, 'regeling')
	const afvalstoffen = regelingen.find(
		(r) => r.citationTitle === 'Afvalstoffenverordening Amsterdam',
	)
	test.skip(
		!afvalstoffen,
		'Seed regeling "Afvalstoffenverordening Amsterdam" not found',
	)

	await page.goto(`${BASE}/apps/decidesk/regelingen/${objId(afvalstoffen)}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await expect(
		page.getByRole('heading', { name: 'Version timeline', exact: true }),
	).toBeVisible()
	await expect(page.getByTestId('version-timeline-list')).toBeVisible()
	// afvalstoffen-v1 (vervangen/replaced, 2024-01-01) → afvalstoffen-v2
	// (in-werking/in force, 2025-06-01) — both seeded versions render.
	await expect(page.getByText('Version 1', { exact: false })).toBeVisible()
	await expect(page.getByText('Version 2', { exact: false })).toBeVisible()
	await expect(page.getByText('replaced', { exact: true })).toBeVisible()
	await expect(page.getByText('in force', { exact: true })).toBeVisible()
})

// @e2e openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
test('BevoegdheidstoedelingDetail: delegation-chain widget shows the seeded ondermandaat under mandaat-subsidies-secretaris', async ({
	page,
}) => {
	test.setTimeout(35_000)

	const toedelingen = await listObjects(page, 'bevoegdheidstoedeling')
	const parent = toedelingen.find(
		(t) =>
			t.subject === 'Beslissen op subsidieaanvragen tot het genoemde plafond',
	)
	test.skip(
		!parent,
		'Seed bevoegdheidstoedeling "mandaat-subsidies-secretaris" not found',
	)

	await page.goto(`${BASE}/apps/decidesk/bevoegdheidstoedelingen/${objId(parent)}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await expect(
		page.getByRole('heading', { name: 'Ondermandaat chain', exact: true }),
	).toBeVisible()
	await expect(page.getByTestId('delegation-chain-children')).toBeVisible()
	await expect(
		page.getByText(
			'Beslissen op subsidieaanvragen binnen het programma Samenleving',
			{ exact: true },
		),
	).toBeVisible()
})

// @e2e openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
// @e2e openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail
test('GeheimhoudingDetail: confidentiality-status-timeline widget renders the imposed stage and resolves its ground', async ({
	page,
}) => {
	test.setTimeout(35_000)

	const geheimhoudingen = await listObjects(page, 'geheimhouding')
	const raadsnota = geheimhoudingen.find(
		(g) => g.imposedAt === '2026-06-08T10:00:00Z',
	)
	test.skip(
		!raadsnota,
		'Seed geheimhouding "geheimhouding-raadsnota-grondexploitatie" not found',
	)

	await page.goto(`${BASE}/apps/decidesk/geheimhoudingen/${objId(raadsnota)}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await expect(
		page.getByRole('heading', {
			name: 'Confidentiality status timeline',
			exact: true,
		}),
	).toBeVisible()
	await expect(page.getByTestId('confidentiality-timeline-list')).toBeVisible()
	await expect(page.getByTestId('confidentiality-stage-imposed')).toBeVisible()
	await expect(page.getByTestId('confidentiality-ground')).toBeVisible()
	await expect(
		page.getByText('Geheimhouding raadsstukken', { exact: false }),
	).toBeVisible()
})
