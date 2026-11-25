/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: every counter on the archive dashboard reads what
 * OpenRegister holds (records-management-archiving task 6). For each stat card
 * the spec asks OpenRegister's object list, with the filter the card links to,
 * for its total, and expects the card to show that number and to link to that
 * filtered list. decidiq computes nothing here: dates and nominations are the
 * ones OpenRegister writes under `@self.retention`.
 *
 * Reads whatever the instance holds (the municipality example set on a seeded
 * instance); it creates nothing.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import * as path from 'node:path'
import { BASE_URL as BASE } from './base-url.ts'

const APP = `${BASE}/index.php/apps/decidiq`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects`

interface StatWidget {
	id: string
	type: string
	content: {
		label: string
		route: { name: string, query: Record<string, string> }
		source: { register: string, schema: string }
	}
}

const fragment = JSON.parse(readFileSync(path.resolve(__dirname, '../../src/manifest.d/records-management.json'), 'utf8'))
const dashboard = fragment.pages.find((page: any) => page.id === 'ArchiveDashboard')
const stats: StatWidget[] = dashboard.config.widgets.filter((widget: StatWidget) => widget.type === 'stat')

/**
 * The route query with `@today` as today's date, as the card resolves it.
 *
 * @param query The card's route query.
 * @return The query OpenRegister is asked.
 */
function resolved(query: Record<string, string>): Record<string, string> {
	const today = new Date().toISOString().slice(0, 10)
	return Object.fromEntries(Object.entries(query).map(([key, value]) => [key, value === '@today' ? today : String(value)]))
}

test('the archive dashboard shows every counter', async ({ page }) => {
	await page.goto(`${APP}/archive`)
	for (const stat of stats) {
		await expect(page.locator(`[widgetid="${stat.id}"]`), stat.content.label).toBeVisible({ timeout: 20_000 })
	}
})

for (const stat of stats) {
	// @e2e records-management-archiving::archive-completeness-and-compliance-dashboard
	test(`"${stat.content.label}" counts what OpenRegister holds and links to that list`, async ({ page }) => {
		const query = resolved(stat.content.route.query)
		const params = new URLSearchParams({ ...query, _limit: '1' })
		const list = await page.request.get(`${OBJECTS}/${stat.content.source.register}/${stat.content.source.schema}?${params}`)
		expect(list.ok(), `${stat.id}: list ${list.status()}`).toBeTruthy()
		const total = Number((await list.json()).total)

		await page.goto(`${APP}/archive`)
		const card = page.locator(`[widgetid="${stat.id}"]`)
		await expect(card.locator('.cn-kpi-card__value').first()).toHaveText(String(total), { timeout: 20_000 })

		const href = new URL(String(await card.getAttribute('href')), BASE)
		for (const [key, value] of Object.entries(stat.content.route.query)) {
			const expected = value === '@today' ? query[key] : String(value)
			expect([value, expected], `${stat.id} links with ${key}`).toContain(href.searchParams.get(key))
		}
	})
}

// A meeting whose dossier exists is not counted as a meeting without one,
// also when the dossier came with an example set (seeded dossiers name their
// meeting; the import points the meeting back, as forming a dossier does).
test('no meeting that has a dossier counts as a meeting without one', async ({ page }) => {
	const dossiers = await page.request.get(`${OBJECTS}/decidiq/archival-dossier?_limit=200`)
	expect(dossiers.ok()).toBeTruthy()
	const withDossier = new Set(((await dossiers.json()).results ?? []).map((row: any) => String(row.meeting ?? '')).filter(Boolean))
	const gaps = await page.request.get(`${OBJECTS}/decidiq/meeting?${new URLSearchParams({ 'archivalDossier[isnull]': 'true', _limit: '200' })}`)
	expect(gaps.ok()).toBeTruthy()
	const counted = ((await gaps.json()).results ?? []).map((row: any) => String(row.id ?? row['@self']?.id))
	expect(counted.filter((id: string) => withDossier.has(id))).toEqual([])
})
