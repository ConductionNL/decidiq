/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: ancillary positions and declared gifts (change
 * interests-and-integrity). The schemas, the lifecycle, the read predicate and
 * the seeds are covered as configuration by
 * tests/Unit/Settings/IntegrityDisclosuresRegisterTest.php, the COI context
 * by tests/vitest/ancillaryContext.spec.js and the compliance join by
 * tests/vitest/integrityCompliance.spec.js. This file walks them on a live
 * instance.
 *
 * Needs the municipality example set (council policy, four positions, two
 * gifts) and, for the internal declaration, the corporate one. Runs as the
 * administrator; the anonymous reads use a request context without the
 * stored session.
 *
 * Written, not yet run: it needs a live instance with the example sets. The
 * self-service scenarios create their own person linked to the signed-in
 * administrator (no example person carries a Nextcloud account), walk the
 * My declarations panel, and delete what they made.
 *
 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'
import { chooseLens, pickFirstOptionByKeyboard } from './support/quick-filter.ts'

/**
 * The organisations of the ancillary positions an anonymous reader sees.
 *
 * @return {Promise<string[]>} The organisation names.
 */
async function anonymousPositions(): Promise<string[]> {
	const anonymous = await request.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
	})
	const response = await anonymous.get(
		'/index.php/apps/openregister/api/objects/decidiq/ancillary-position?_limit=100',
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	expect(response.ok()).toBe(true)
	const rows: Array<{ organisation?: string; lifecycle?: string }> =
		(await response.json()).results ?? []
	await anonymous.dispose()
	return rows.map((row) => `${row.organisation ?? ''}|${row.lifecycle ?? ''}`)
}

// @e2e interests-and-integrity::published-nevenfunctie-is-anonymously-readable
// @e2e interests-and-integrity::internal-declaration-is-not-public
// @e2e interests-and-integrity::ended-position-is-live-on-the-public-register
test('Public read: published positions, ended ones included, are readable without signing in; declared and internal ones are not', async () => {
	const seen = await anonymousPositions()

	expect(seen.some((row) => row.endsWith('|public'))).toBe(true)
	expect(seen.some((row) => row.endsWith('|ended'))).toBe(true)
	expect(seen.some((row) => row.endsWith('|internal'))).toBe(false)
	expect(seen.some((row) => row.endsWith('|reported'))).toBe(false)
	expect(seen.some((row) => row.startsWith('Stedin|'))).toBe(false)
})

// @e2e interests-and-integrity::griffie-filters-the-per-body-register-and-exports
test('AncillaryPositions: the lenses narrow the register and the list exports', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/ancillary-positions`)
	await expect(page.locator('tbody tr').first()).toBeVisible()
	const all = await page.locator('tbody tr').count()

	// The lenses are a dropdown on this page (quickFilterMode: dropdown).
	await chooseLens(page, 'Public')
	await expect.poll(() => page.locator('tbody tr').count()).toBeLessThan(all)
	const publicRows = await page.locator('tbody tr').count()
	expect(publicRows).toBeLessThan(all)

	await chooseLens(page, 'All')
	// Export is in the index's Actions menu; the dialog offers Excel first, CSV second.
	await page.getByRole('button', { name: 'Actions', exact: true }).first().click()
	await page.getByRole('menuitem', { name: /Export/ }).first().click()
	const dialog = page.getByRole('dialog').last()
	// The format select does not open on a click inside the dialog; typing filters it, Enter picks.
	await dialog.locator('#cn-mass-export-format').focus()
	await page.keyboard.type('CSV')
	await page.keyboard.press('Enter')
	const download = page.waitForEvent('download')
	await dialog.getByRole('button', { name: /^Export$/ }).last().click()
	expect((await download).suggestedFilename()).toMatch(/\.csv$/)
})

// @e2e interests-and-integrity::q.q.-functie-flagged-as-held-ex-officio
test('AncillaryPositionDetail: a position held ex officio says so', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/ancillary-positions`)
	await openRow(page, 'Afvalschap')
	await expect(page.getByText(/Ex officio/)).toBeVisible()
})

// @e2e interests-and-integrity::member-registers-a-received-gift
// @e2e interests-and-integrity::invitation-registered-and-handed-over
test('DeclaredGifts: the lenses split gifts from invitations and by decision', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/declared-gifts`)
	await chooseLens(page, 'Invitations')
	await expect(page.locator('tbody tr', { hasText: /diner/i })).toBeVisible()
	await chooseLens(page, 'Accepted')
	await expect(page.locator('tbody tr', { hasText: /boek/i })).toBeVisible()
})

// @e2e interests-and-integrity::dashboard-kpi-counts-overdue-reviews
test('Dashboard: the overdue-review tile is not zero on the example set', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	const tile = page
		.locator('.cn-stats-block, .cn-kpi-card')
		.filter({ hasText: /review/i })
		.first()
	await expect(tile).toBeVisible()
	await expect(tile.locator('.cn-stats-block__count-value')).not.toHaveText('0')
})

const OBJECTS = '/index.php/apps/openregister/api/objects/decidiq'

/**
 * The text input a declaration dialog marks with a test id: the id sits on the
 * input itself (NcTextField hands its attributes to the input) or on a wrapper.
 *
 * @param {import('@playwright/test').Locator} scope The dialog.
 * @param {string} testId The test id.
 * @return {import('@playwright/test').Locator} The input.
 */
function textInput(scope, testId: string) {
	return scope.locator(`input[data-testid="${testId}"], [data-testid="${testId}"] input`).first()
}
const JSON_HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/**
 * Create a record through the OpenRegister object API as the signed-in user.
 *
 * @param {import('@playwright/test').APIRequestContext} api The request context.
 * @param {string} slug The schema slug.
 * @param {object} data The record.
 * @return {Promise<string>} The new record's id.
 */
async function create(api, slug: string, data: object): Promise<string> {
	const response = await api.post(`${BASE}${OBJECTS}/${slug}`, { headers: JSON_HEADERS, data })
	expect(response.ok()).toBe(true)
	const body = await response.json()
	return String(body.id ?? body['@self']?.id ?? body.uuid)
}

// @e2e interests-and-integrity::member-manages-own-declarations
// @e2e interests-and-integrity::member-confirms-own-declaration
test('MyDeclarations: the member sees only their own declarations, declares, registers a gift, confirms and ends', async ({
	page,
}) => {
	const api = page.request
	const bodies = await api.get(`${BASE}${OBJECTS}/governance-body?_limit=1`, { headers: JSON_HEADERS })
	const body = String((await bodies.json()).results[0].id)
	const person = await create(api, 'person', { name: 'E2E Declaring Member', nextcloudUserId: 'admin' })
	const position = await create(api, 'ancillary-position', {
		person,
		governanceBody: body,
		organisation: 'E2E Stichting',
		role: 'Board member',
		remunerated: false,
		startDate: '2024-01-01',
		declaredAt: '2024-01-05',
		// A new position starts as reported: the lifecycle refuses any other first state.
		lifecycle: 'reported',
	})
	// Staff publish it (reported -> public), so the member can end a public position below.
	const published = await api.patch(`${BASE}${OBJECTS}/ancillary-position/${position}`, { headers: JSON_HEADERS, data: { lifecycle: 'public' } })
	expect(published.ok()).toBe(true)
	try {
		await page.goto(`${BASE}/index.php/apps/decidiq/my-declarations`)
		const panel = page.getByTestId('my-declarations-panel')
		await expect(panel).toBeVisible()

		// Only the member's own: the one position made above, none of the example set.
		const rows = panel.getByTestId('my-declarations-position')
		await expect(rows).toHaveCount(1)
		await expect(rows.first()).toContainText('E2E Stichting')
		await expect(panel.getByTestId('my-declarations-review-due')).toBeVisible()

		// Confirm the annual review: reviewedAt becomes today and the badge goes.
		await panel.getByTestId('my-declarations-confirm').click()
		await expect(panel.getByTestId('my-declarations-review-due')).toHaveCount(0)
		const confirmed = await (await api.get(`${BASE}${OBJECTS}/ancillary-position/${position}`, { headers: JSON_HEADERS })).json()
		expect(confirmed.reviewedAt).toBe(new Date().toISOString().slice(0, 10))
		expect(confirmed.organisation).toBe('E2E Stichting')

		// Declare another position.
		await panel.getByTestId('my-declarations-add-position').click()
		const dialog = page.getByTestId('declaration-position-dialog')
		await textInput(dialog, 'declaration-organisation').fill('E2E Vereniging')
		await textInput(dialog, 'declaration-role').fill('Treasurer')
		await dialog.getByTestId('declaration-startDate').fill('2026-01-01')
		await pickFirstOptionByKeyboard(page, dialog.getByLabel('Governance body'))
		await dialog.getByTestId('declaration-position-confirm').click()
		await expect(rows).toHaveCount(2)

		// Register a gift.
		await panel.getByTestId('my-declarations-add-gift').click()
		const gift = page.getByTestId('declaration-gift-dialog')
		await pickFirstOptionByKeyboard(page, gift.getByLabel('Governance body'))
		await textInput(gift, 'declaration-giver').fill('E2E Giver')
		await textInput(gift, 'declaration-description').fill('A book')
		await textInput(gift, 'declaration-estimatedValue').fill('20')
		await gift.getByTestId('declaration-receivedOn').fill('2026-11-01')
		await gift.getByTestId('declaration-gift-confirm').click()
		await expect(panel.getByTestId('my-declarations-gift')).toHaveCount(1)

		// End the public position.
		await rows.filter({ hasText: 'E2E Stichting' }).getByTestId('my-declarations-end').click()
		await page.getByTestId('declaration-end-confirm').click()
		// The dialog saves after it closes; read until the write has landed.
		const read = async () => (await api.get(`${BASE}${OBJECTS}/ancillary-position/${position}`, { headers: JSON_HEADERS })).json()
		await expect.poll(async () => (await read()).lifecycle, { timeout: 15_000 }).toBe('ended')
		const ended = await read()
		expect(ended.endDate).toBeTruthy()
	} finally {
		const own = async (slug: string, field: string) => {
			const list = await (await api.get(`${BASE}${OBJECTS}/${slug}?${field}=${person}&_limit=50`, { headers: JSON_HEADERS })).json()
			for (const row of list.results ?? []) {
				await api.delete(`${BASE}${OBJECTS}/${slug}/${row.id}`, { headers: JSON_HEADERS })
			}
		}
		await own('ancillary-position', 'person')
		await own('declared-gift', 'recipient')
		await api.delete(`${BASE}${OBJECTS}/person/${person}`, { headers: JSON_HEADERS })
	}
})

test('Detail pages: staff publish and withdraw a position from the header', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/ancillary-positions`)
	await openRow(page, 'Afvalschap')
	// The detail header shows a few actions and puts the rest under More.
	const named = /^(Publish|Withdraw from the public register)$/
	const inHeader = page.getByRole('button', { name: named })
	if (await inHeader.count() === 0) {
		await page.getByRole('button', { name: 'More', exact: true }).first().click()
	}
	await expect(inHeader.or(page.getByRole('menuitem', { name: named })).first()).toBeVisible()
})
