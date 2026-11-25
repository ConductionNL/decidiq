/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decisions report cut by period and by body, and opened as a list
 * (insight-decisions-report-by-period-and-body).
 *
 * The run seeds two governance bodies of its own and adopted decisions for
 * each: body X has two dated this year and three dated last year, body Y has
 * one dated this year. Picking a body therefore isolates the run's own data
 * from whatever else the instance holds, so the Adopted tile can be read as
 * exact numbers: X this year 2, X all periods 5, Y this year 1. Show these
 * decisions then opens the Decisions list with the same body and dates.
 *
 * Base URL from the environment (tests/e2e/base-url.ts via the config); the
 * run's bodies and decisions are deleted afterwards.
 *
 * Covers, from openspec/changes/archive/2026-11-24-insight-decisions-report-by-period-and-body:
 *   task 3 "Playwright compares the Adopted tile before and after the pick"
 *   task 4 "Body picker and By body chart", end to end
 *   task 5 "Open the same decisions as a list"
 *
 * @spec openspec/changes/archive/2026-11-24-insight-decisions-report-by-period-and-body/specs/dashboard/spec.md
 */
import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const OBJECTS = '/index.php/apps/openregister/api/objects/decidiq'
const REPORT = '/index.php/apps/decidiq/reports/decisions'

const STAMP = `E2E-REPORT-${Date.now()}`
const YEAR = new Date().getFullYear()

/**
 * Call a route from inside the page, carrying the session and its token.
 *
 * @param page   The page.
 * @param method The verb.
 * @param url    The url.
 * @param body   The body, for a write.
 * @return The status and parsed body.
 */
async function api(
	page: Page,
	method: string,
	url: string,
	body?: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ method, url, body }) => {
			const res = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let parsed: Record<string, unknown>
			try {
				parsed = JSON.parse(text)
			} catch {
				parsed = { raw: text.slice(0, 300) }
			}
			return { status: res.status, body: parsed }
		},
		{ method, url, body },
	)
}

/**
 * Seed one object.
 *
 * @param page   The page.
 * @param schema The schema slug.
 * @param object The object.
 * @return The id.
 */
async function seed(page: Page, schema: string, object: Record<string, unknown>): Promise<string> {
	const res = await api(page, 'POST', `${OBJECTS}/${schema}`, object)
	expect(res.status, `could not seed a ${schema}: ${JSON.stringify(res.body).slice(0, 300)}`).toBeLessThan(400)
	const self = (res.body['@self'] ?? {}) as Record<string, unknown>
	return String(res.body.id ?? self.id ?? '')
}

/**
 * The Adopted tile's number.
 *
 * @param page The page.
 * @return The tile's value locator.
 */
function adoptedTile(page: Page): Locator {
	return page
		.locator('.cn-stats-block, .cn-kpi-card')
		.filter({ has: page.getByText('Adopted', { exact: true }) })
		.first()
		.locator('.cn-stats-block__count-value')
}

/**
 * Pick an option in a library NcSelect by its test id.
 *
 * @param page   The page.
 * @param testId The select's data-testid.
 * @param option The option's text.
 */
async function pick(page: Page, testId: string, option: string): Promise<void> {
	await page.getByTestId(testId).click()
	await page.getByRole('option', { name: option, exact: true }).click()
}

test.describe('the decisions report cut by period and body', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	const created: Array<{ schema: string; id: string }> = []
	const bodyX = `Raad ${STAMP}`
	const bodyY = `College ${STAMP}`

	test.afterAll(async ({ browser }) => {
		if (created.length === 0) return
		const page = await browser.newPage()
		await page.goto('/index.php/apps/decidiq/', { waitUntil: 'domcontentloaded' })
		for (const row of created.reverse()) {
			await api(page, 'DELETE', `${OBJECTS}/${row.schema}/${row.id}`)
		}
		await page.close()
	})

	test('the Adopted tile follows the body and the period picked', async ({ page }) => {
		await page.goto('/index.php/apps/decidiq/', { waitUntil: 'domcontentloaded', timeout: 45_000 })
		const x = await seed(page, 'governance-body', { name: bodyX, bodyType: 'legislative', domain: 'municipal' })
		const y = await seed(page, 'governance-body', { name: bodyY, bodyType: 'operational', domain: 'municipal' })
		created.push({ schema: 'governance-body', id: x }, { schema: 'governance-body', id: y })

		const decisions: Array<[string, string]> = [
			[x, `${YEAR}-01-15`],
			[x, `${YEAR}-01-20`],
			[x, `${YEAR - 1}-03-01`],
			[x, `${YEAR - 1}-06-01`],
			[x, `${YEAR - 1}-09-01`],
			[y, `${YEAR}-01-25`],
		]
		for (const [body, date] of decisions) {
			const id = await seed(page, 'decision', {
				title: `Besluit ${STAMP} ${date}`,
				text: 'Seeded by the decisions report e2e.',
				decisionType: 'resolution',
				outcome: 'adopted',
				decisionDate: date,
				decidingBody: body,
			})
			created.push({ schema: 'decision', id })
		}

		await page.goto(REPORT, { waitUntil: 'domcontentloaded', timeout: 45_000 })
		await expect(page.getByTestId('cn-date-range-picker')).toBeVisible({ timeout: 30_000 })
		await pick(page, 'cn-date-range-picker-preset', 'This year')

		// No body: the run's decisions are among everything counted.
		await expect(adoptedTile(page)).not.toHaveText('—', { timeout: 20_000 })
		const before = Number((await adoptedTile(page).textContent())?.replace(/\D/g, '') ?? 'NaN')
		expect(before).toBeGreaterThanOrEqual(3)

		await pick(page, 'cn-page-filter-decidingBody', bodyX)
		await expect(adoptedTile(page)).toHaveText('2', { timeout: 20_000 })

		await pick(page, 'cn-date-range-picker-preset', 'All')
		await expect(adoptedTile(page)).toHaveText('5', { timeout: 20_000 })

		await pick(page, 'cn-date-range-picker-preset', 'This year')
		await pick(page, 'cn-page-filter-decidingBody', bodyY)
		await expect(adoptedTile(page)).toHaveText('1', { timeout: 20_000 })

		// The By body chart counts all periods and names both bodies.
		const byBody = page.locator('section, article, div').filter({ has: page.getByText('By body, all periods', { exact: true }) }).last()
		await expect(byBody).toBeVisible()
	})

	test('Show these decisions opens the list with the same body and dates', async ({ page }) => {
		await page.goto(REPORT, { waitUntil: 'domcontentloaded', timeout: 45_000 })
		await expect(page.getByTestId('cn-date-range-picker')).toBeVisible({ timeout: 30_000 })
		await pick(page, 'cn-date-range-picker-preset', 'This year')
		await pick(page, 'cn-page-filter-decidingBody', bodyX)
		await expect(adoptedTile(page)).toHaveText('2', { timeout: 20_000 })

		await page.getByTestId('cn-dashboard-page-header-actions').getByRole('button', { name: 'Show these decisions' })
			.or(page.getByRole('link', { name: 'Show these decisions' }))
			.first()
			.click()

		await expect.poll(() => decodeURIComponent(page.url()), { timeout: 20_000 }).toContain('/decisions?')
		const url = new URL(page.url())
		const x = created.find((c) => c.schema === 'governance-body')?.id
		expect(url.searchParams.get('decidingBody')).toBe(x)
		expect(url.searchParams.get('decisionDate[gte]') ?? '').toContain(`${YEAR}-01-01`)
		expect(url.searchParams.get('decisionDate[lte]') ?? '').toContain(`${YEAR}-`)

		const rows = page.getByRole('row').filter({ hasText: STAMP })
		await expect(rows).toHaveCount(2, { timeout: 30_000 })
		await expect(page.getByText(`Besluit ${STAMP} ${YEAR - 1}`)).toHaveCount(0)
	})
})
