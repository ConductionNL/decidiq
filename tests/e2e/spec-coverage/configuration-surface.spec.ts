/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The configurable types, reachable and listing their rows.
 *
 * WHY THIS EXISTS. Four schemas carried this app's configuration since
 * configurable-types-domain-model and none of them had a page, so the only way a
 * kind reached an instance was a seeded example set and nobody could edit one
 * afterwards. Nothing reported that: a schema with no surface fails no check.
 *
 * So the assertion that matters is that each route RESOLVES and renders its own
 * page, not that a request answers 200. A manifest page whose schema slug is
 * wrong renders an empty shell with no error, which is the failure mode this
 * whole programme keeps meeting.
 *
 * @e2e openspec/changes/configuration-surface/specs/configuration-surface/spec.md#requirement-every-configurable-type-has-a-surface
 * @e2e openspec/changes/configuration-surface/specs/configuration-surface/spec.md#requirement-the-configuration-surfaces-sit-in-the-settings-foldout
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

/** The four surfaces this change added, and the heading each must render. */
const SURFACES = [
	{ route: 'meeting-types', heading: 'Meeting types' },
	{ route: 'agenda-item-types', heading: 'Agenda item types' },
	{ route: 'position-types', heading: 'Position types' },
	{ route: 'body-compositions', heading: 'Body compositions' },
	// Added by retire-the-unbuilt-rooster, which replaced the rooster van
	// aftreden with the source data it was a projection of.
	{ route: 'position-holds', heading: 'Position holders' },
	// Renamed by integrity-disclosures-in-plain-words. Asserted here because a
	// rename that leaves the manifest pointing at the RETIRED slug renders the
	// heading and an empty list, which is indistinguishable from an instance
	// that simply has no disclosures.
	{ route: 'ancillary-positions', heading: 'Other positions' },
	{ route: 'declared-gifts', heading: 'Gifts' },
]

for (const surface of SURFACES) {
	// @e2e openspec/changes/configuration-surface/specs/configuration-surface/spec.md#requirement-every-configurable-type-has-a-surface
	test(`configuration: /${surface.route} resolves and renders its own page`, async ({
		page,
	}) => {
		// Index pages are lighter than the detail pages elsewhere in this suite,
		// but they still block on initializeStores() before the shell mounts.
		test.setTimeout(60_000)

		await page.goto(`${BASE}/apps/decidiq/${surface.route}`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		// 🔑 THE HEADING IS THE ASSERTION. A route that does not resolve renders
		// the app shell and nothing else, which looks identical to a page whose
		// list happens to be empty. The heading is what tells the two apart.
		// CnIndexPage keeps the heading for assistive technology but clips it
		// from view unless the page opts into showTitle, so it is attached, not
		// visible.
		await expect(
			page
				.getByRole('heading', { name: surface.heading, exact: true })
				.first(),
		).toBeAttached({ timeout: 30_000 })

		// An empty configuration is a valid state — an instance that loaded no
		// example set has no kinds — so this asserts the page RENDERED, not that
		// it has rows. Asserting rows would make the test depend on which example
		// set the run happens to have loaded.
		await expect(page.getByTestId('app-root')).toBeVisible()
	})
}

// @e2e openspec/changes/configuration-surface/specs/configuration-surface/spec.md#requirement-every-configurable-type-has-a-surface
test('configuration: an agenda item type created over the API appears on its page', async ({
	page,
	playwright,
}) => {
	// 🔴 THE ONE THAT PROVES THE SCHEMA BINDING. The four tests above would pass
	// against a page bound to the WRONG slug: it would render its heading and an
	// empty list, and an empty list is a legitimate state. Seeding a row and
	// finding it is what separates "the page exists" from "the page reads the
	// schema it claims to".
	test.setTimeout(90_000)

	const api = await playwright.request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
		},
	})

	let typeId: string | null = null
	try {
		const name = `E2E config kind ${Date.now()}`
		const created = await api.post(
			'/index.php/apps/openregister/api/objects/decidiq/agenda-item-type',
			{ data: { name, isDraft: false, active: true } },
		)
		test.skip(
			!created.ok(),
			`Could not seed agenda-item-type (HTTP ${created.status()})`,
		)
		const body = await created.json()
		typeId = body?.id ?? body?.['@self']?.id ?? null
		test.skip(!typeId, 'Seeded agenda-item-type has no id')

		await page.goto(`${BASE}/apps/decidiq/agenda-item-types`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		await expect(page.getByText(name, { exact: true })).toBeVisible({
			timeout: 45_000,
		})
	} finally {
		if (typeId) {
			await api
				.delete(
					`/index.php/apps/openregister/api/objects/decidiq/agenda-item-type/${typeId}`,
				)
				.catch(() => null)
		}
		await api.dispose()
	}
})

// @e2e openspec/changes/retire-the-unbuilt-rooster/specs
test('configuration: a position holder seeded over the API appears on Position holders, whose term ends first on top', async ({
	page,
	playwright,
}) => {
	// retire-the-unbuilt-rooster 4.2. The heading test above proves the route
	// resolves; this proves the page reads `position-hold` and sorts by whose
	// term ends next. The two holds end in 1901 and 1902, so no real hold on
	// the instance sorts before them and both are on the first page.
	test.setTimeout(120_000)

	const api = await playwright.request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
		},
	})
	const objects = '/index.php/apps/openregister/api/objects/decidiq'
	const created: Array<{ schema: string; id: string }> = []
	const seed = async (schema: string, data: Record<string, unknown>): Promise<string> => {
		const res = await api.post(`${objects}/${schema}`, { data })
		expect(res.ok(), `could not seed ${schema}: HTTP ${res.status()} ${(await res.text()).slice(0, 200)}`).toBe(true)
		const body = await res.json()
		const id = String(body?.id ?? body?.['@self']?.id ?? '')
		expect(id, `seeded ${schema} has an id`).not.toBe('')
		created.push({ schema, id })
		return id
	}

	try {
		const stamp = `e2e-hold-${Date.now()}`
		const body = await seed('governance-body', { name: `E2E body ${stamp}`, bodyType: 'legislative', domain: 'municipal' })
		const position = await seed('position-type', {
			name: `E2E position ${stamp}`,
			governanceBody: body,
			allowedHoldTypes: [stamp],
			termDurationMonths: 12,
		})
		const membership = await seed('membership', { role: 'member', governanceBody: body })
		const later = await seed('position-hold', {
			membership,
			position,
			governanceBody: body,
			holdType: stamp,
			startDate: '1900-01-01',
			endDate: '1902-01-01',
			termNumber: 2,
		})
		const sooner = await seed('position-hold', {
			membership,
			position,
			governanceBody: body,
			holdType: stamp,
			startDate: '1900-01-01',
			endDate: '1901-01-01',
			termNumber: 1,
		})

		await page.goto(`${BASE}/apps/decidiq/position-holds`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })
		const rows = page.getByRole('row').filter({ hasText: stamp })
		await expect(rows).toHaveCount(2, { timeout: 45_000 })
		await expect(rows.nth(0)).toContainText('1901')
		await expect(rows.nth(1)).toContainText('1902')

		await page.goto(`${BASE}/apps/decidiq/position-holds/${sooner}`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })
		await expect(page.getByText(stamp).first()).toBeVisible({ timeout: 45_000 })
		expect(later).not.toBe(sooner)
	} finally {
		for (const row of created.reverse()) {
			await api.delete(`${objects}/${row.schema}/${row.id}`).catch(() => null)
		}
		await api.dispose()
	}
})
