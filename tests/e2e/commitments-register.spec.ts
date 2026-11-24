/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the commitments register (change toezeggingen-ingekomen-
 * stukken, the commitment half). The schema, lifecycle, reminders and public
 * read predicate are covered as configuration by
 * tests/Unit/Settings/CommitmentsRegisterTest.php, and the pages and the
 * dashboard tile by tests/vitest/commitmentsRegisterPages.spec.js. This file
 * walks them on a live instance.
 *
 * Needs the municipality example set, which seeds an open commitment past its
 * deadline (wachtlijsten jeugdzorg), one in execution (schouw Marktplein) and
 * a disposed one. Runs as the administrator; the anonymous read uses a fresh
 * request context without the stored session.
 *
 * Written, not yet run: it needs a live instance with the example set.
 *
 * @spec openspec/changes/archive/2026-11-24-toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'
import { cleanupAll, createObject, getObject, newLedger, writeHeaders } from './workflows/governance-fixture.ts'

const OVERDUE = 'wachtlijsten jeugdzorg'
const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>): string => String(o.id ?? o['@self']?.id ?? '')

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

/**
 * A council, a person who speaks for the college, an opened meeting and an item on it.
 *
 * @param page The page whose session seeds them.
 * @return The ids.
 */
async function meetingWithItem(page: Page): Promise<Record<string, string>> {
	const body = idOf(await createObject(page, ledger, 'governance-body', { name: `${tag} raad`, bodyType: 'legislative' }))
	const alderman = idOf(await createObject(page, ledger, 'person', { name: `${tag} wethouder De Vries` }))
	const meeting = idOf(await createObject(page, ledger, 'meeting', {
		title: `${tag} raadsvergadering`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: '2026-11-26T19:30:00Z',
		governanceBody: body,
		lifecycle: 'opened',
	}))
	const item = idOf(await createObject(page, ledger, 'agenda-item', { title: `${tag} kadernota`, meeting }))
	return { body, alderman, meeting, item }
}

const IN_EXECUTION = 'schouw van het Marktplein'

// @e2e toezeggingen-register::griffier-browses-and-filters-the-toezeggingenlijst
test('Commitments: the Over the deadline lens lists the overdue open commitment and not the one in execution', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await expect(page.getByText(OVERDUE)).toBeVisible()

	await page.getByRole('button', { name: /Over the deadline/ }).click()
	await expect(page.getByText(OVERDUE)).toBeVisible()
	await expect(page.getByText(IN_EXECUTION)).toHaveCount(0)

	await page.getByRole('button', { name: /In execution/ }).click()
	await expect(page.getByText(IN_EXECUTION)).toBeVisible()
	await expect(page.getByText(OVERDUE)).toHaveCount(0)
})

// @e2e toezeggingen-register::export-toezeggingenlijst-to-csv
test('Commitments: the list exports to CSV', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await page
		.getByRole('button', { name: /Export/ })
		.first()
		.click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()
	const download = page.waitForEvent('download')
	await dialog
		.getByRole('button', { name: /Export/ })
		.last()
		.click()
	expect((await download).suggestedFilename()).toMatch(/\.csv$/)
})

// @e2e toezeggingen-register::kpi-counts-only-overdue-non-terminal-commitments
test('Dashboard: the Commitments over deadline tile counts what the lens lists, and opens it', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await page.getByRole('button', { name: /Over the deadline/ }).click()
	await expect(page.getByText(OVERDUE)).toBeVisible()
	const listed = await page.locator('tbody tr').count()

	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	const tile = page
		.locator('.cn-stats-block, .cn-kpi-card')
		.filter({ hasText: 'Commitments over deadline' })
	await expect(tile).toBeVisible()
	await expect(tile.locator('.cn-stats-block__count-value')).toHaveText(
		String(listed),
	)

	await tile.click()
	await expect(page).toHaveURL(/\/commitments/)
	await expect(page.getByText(OVERDUE)).toBeVisible()
	await expect(page.getByText(IN_EXECUTION)).toHaveCount(0)
})

// @e2e toezeggingen-register::commitment-is-settled
test('CommitmentDetail: recording a settlement disposes a commitment in execution', async ({
	page,
}) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/commitments`)
	await openRow(page, IN_EXECUTION)

	const tab = page.getByTestId('commitment-publication-tab')
	await tab.getByTestId('commitment-settle').click()
	const dialog = page.getByTestId('commitment-settlement-dialog')
	await expect(dialog).toBeVisible()
	await dialog
		.getByTestId('commitment-settlement-note')
		.locator('textarea, input')
		.first()
		.fill('Schouw gehouden op 12 mei; verslag naar de raad.')
	await dialog.getByTestId('commitment-settlement-confirm').click()

	await expect(dialog).toBeHidden()
	await expect(tab.getByTestId('commitment-settlement-note-shown')).toContainText(
		'Schouw gehouden op 12 mei',
	)
})

// @e2e toezeggingen-register::published-commitment-is-anonymously-readable
// @e2e toezeggingen-register::unpublished-commitment-is-not-public
test('Public read: a published commitment is readable without signing in, an unpublished one is not', async () => {
	const anonymous = await request.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
	})
	const response = await anonymous.get(
		'/index.php/apps/openregister/api/objects/decidiq/governance-commitment?_limit=100',
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	expect(response.ok()).toBe(true)
	const texts: string[] = ((await response.json()).results ?? []).map(
		(row: { text?: string }) => String(row.text ?? ''),
	)

	expect(texts.some((text) => text.includes(OVERDUE))).toBe(true)
	expect(
		texts.some((text) => text.includes('energieplan voor zwembad De Hoge Dijk')),
	).toBe(false)
	await anonymous.dispose()
})

// @e2e toezeggingen-register::griffier-registers-a-commitment-made-during-a-meeting
test('A commitment made at the meeting is stored with its meeting, item and body; one without text is refused', async ({ page }) => {
	const at = await meetingWithItem(page)
	const commitment = await createObject(page, ledger, 'governance-commitment', {
		text: `${tag} Het college zegt toe de evaluatie voor de zomer te sturen.`,
		madeBy: at.alderman,
		meeting: at.meeting,
		agendaItem: at.item,
		directedTo: at.body,
		deadline: '2027-06-30',
		lifecycle: 'open',
	})
	const stored = await getObject(page, 'governance-commitment', idOf(commitment))
	expect(stored?.meeting).toBe(at.meeting)
	expect(stored?.agendaItem).toBe(at.item)
	expect(stored?.directedTo).toBe(at.body)
	expect(stored?.lifecycle).toBe('open')

	const missing = await page.request.post(`${OR}/governance-commitment`, {
		headers: await writeHeaders(page),
		data: { madeBy: at.alderman, meeting: at.meeting, directedTo: at.body, lifecycle: 'open' },
	})
	expect(missing.status()).toBeGreaterThanOrEqual(400)
	expect(missing.status()).toBeLessThan(500)
})

// @e2e toezeggingen-register::commitment-stemming-from-a-college-takeover-of-a-motion
test('A commitment from a motion the college took over shows on the motion, and adds no progress updates', async ({ page }) => {
	const at = await meetingWithItem(page)
	const motion = idOf(await createObject(page, ledger, 'decision', {
		title: `${tag} Motie meer groen in de wijk`,
		decisionType: 'motion',
		proposer: 'Anna',
		lifecycle: 'decided',
		outcome: 'adopted',
		decisionDate: '2026-10-01',
	}))
	const text = `${tag} Het college werkt de motie uit in een groenplan.`
	await createObject(page, ledger, 'governance-commitment', {
		text,
		madeBy: at.alderman,
		meeting: at.meeting,
		directedTo: at.body,
		relatedMotion: motion,
		deadline: '2027-03-01',
		lifecycle: 'open',
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/decisions/${motion}`)
	await expect(page.getByText(text).first()).toBeVisible()

	const updates = await page.request.get(`${OR}/implementation-update?decision=${motion}&_limit=10`, {
		headers: { Accept: 'application/json' },
	})
	expect(updates.ok()).toBe(true)
	expect((await updates.json()).results ?? []).toHaveLength(0)
})

// @e2e toezeggingen-register::status-change-is-live-on-the-public-list
test('Public read: a published commitment shows its new status at once, without republishing', async ({ page }) => {
	const at = await meetingWithItem(page)
	const id = idOf(await createObject(page, ledger, 'governance-commitment', {
		text: `${tag} Het college zegt toe de raad over de aanbesteding te informeren.`,
		madeBy: at.alderman,
		meeting: at.meeting,
		directedTo: at.body,
		deadline: '2027-01-31',
		lifecycle: 'open',
		publicationDate: '2026-01-01T00:00:00Z',
	}))
	const anonymous = await request.newContext({ baseURL: BASE, storageState: { cookies: [], origins: [] } })
	const read = async (): Promise<string> => {
		const response = await anonymous.get(`/index.php/apps/openregister/api/objects/decidiq/governance-commitment/${id}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(response.ok()).toBe(true)
		return String((await response.json()).lifecycle)
	}
	expect(await read()).toBe('open')

	for (const lifecycle of ['in-execution', 'disposed']) {
		const current = await getObject(page, 'governance-commitment', id)
		const saved = await page.request.put(`${OR}/governance-commitment/${id}`, {
			headers: await writeHeaders(page),
			data: { ...current, lifecycle, settlementNotes: lifecycle === 'disposed' ? 'Raad geïnformeerd per brief.' : undefined },
		})
		expect(saved.status(), await saved.text()).toBeLessThan(300)
	}

	expect(await read()).toBe('disposed')
	await anonymous.dispose()
})

