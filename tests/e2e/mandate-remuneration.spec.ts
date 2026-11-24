/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: what a position holder is paid, on the body page and on the
 * position hold page (change bodies-director-remuneration, REQ-DRM-001 to
 * REQ-DRM-003).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * MandateRemunerationRegisterTest pins the schema, its read rule and the
 * manifest widgets as JSON. It cannot prove that the imported read rule really
 * hides a colleague's pay from a member, that an anonymous visitor reads only
 * a disclosed record whose publication date has passed, or that the two pages
 * render: the body page with this year's records and their total, and the
 * hold page with its years newest first.
 *
 * The secretary is the suite's administrator, who reads every record. The
 * member is an account created for this run in no group, as an ordinary
 * member of the board. Every fixture is created through the OpenRegister
 * object API as the administrator and removed in afterAll.
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/bodies-director-remuneration/specs/governance-bodies/spec.md
 * @e2e governance-bodies/requirement-req-drm-001-a-position-holders-remuneration-is-recorded-per-year/the-company-secretary-records-the-chairs-fee
 * @e2e governance-bodies/requirement-req-drm-001-a-position-holders-remuneration-is-recorded-per-year/a-member-cannot-read-a-colleagues-pay
 * @e2e governance-bodies/requirement-req-drm-001-a-position-holders-remuneration-is-recorded-per-year/disclosed-pay-is-public
 * @e2e governance-bodies/requirement-req-drm-002-the-body-page-shows-this-years-remuneration-and-its-total/the-secretary-checks-the-boards-cost
 * @e2e governance-bodies/requirement-req-drm-003-a-position-hold-lists-its-remuneration-over-the-years/two-years-of-fees
 */
import type { Browser, Locator, Page } from '@playwright/test'
import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import {
	actorFor,
	adminActor,
	anonymousActor,
	BASE,
	createObject,
	describe,
	disposeActors,
	listObjects,
	newRunId,
	ObjectLedger,
	readObject,
	removeAccounts,
	uuidOf,
} from './support/api-actors.ts'

const SCHEMA = 'mandate-remuneration'
const APP = `${BASE}/index.php/apps/decidiq`

test.describe.configure({ mode: 'serial' })

const run = newRunId()
const tag = `e2e-drm-${run}`
const memberUid = `e2e-drm-member-${run}`.slice(0, 60)
const ledger = new ObjectLedger([
	SCHEMA,
	'position-hold',
	'position-type',
	'membership',
	'person',
	'decision',
	'governance-body',
])

const thisYear = new Date().getFullYear()

let admin: Actor
let anon: Actor
let member: Actor
// Mixed classes so Nextcloud's password policy accepts it.
const memberPassword = `Dq-${randomBytes(18).toString('hex')}-Pw1!`
const ids: Record<string, string> = {}

/**
 * An ISO date (YYYY-MM-DD) `days` from today.
 *
 * @param days Offset in days, negative for the past.
 * @return The date.
 */
function dateFromToday(days: number): string {
	return new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10)
}

/**
 * Create a person with a membership and a position hold on the board.
 *
 * @param key      The key the hold is kept under in `ids`.
 * @param name     The person's name.
 * @param role     The membership role.
 * @param position The position type's key in `ids`.
 * @return Resolves once the hold exists.
 */
async function holder(key: string, name: string, role: string, position: string): Promise<void> {
	ids[`${key}Person`] = uuidOf(
		await createObject(admin, ledger, 'person', { name: `${tag} ${name}` }),
	)
	ids[`${key}Membership`] = uuidOf(
		await createObject(admin, ledger, 'membership', {
			role,
			person: ids[`${key}Person`],
			governanceBody: ids.board,
			startDate: `${thisYear - 2}-04-01T00:00:00Z`,
		}),
	)
	ids[key] = uuidOf(
		await createObject(admin, ledger, 'position-hold', {
			membership: ids[`${key}Membership`],
			position: ids[position],
			governanceBody: ids.board,
			holdType: 'regular',
			startDate: `${thisYear - 2}-04-01`,
		}),
	)
}

/**
 * Record one year's remuneration on a hold.
 *
 * @param key  The key it is kept under in `ids`.
 * @param hold The hold's key in `ids`.
 * @param data The record's own fields.
 * @return Resolves once it is saved.
 */
async function remuneration(key: string, hold: string, data: Record<string, unknown>): Promise<void> {
	ids[key] = uuidOf(
		await createObject(admin, ledger, SCHEMA, {
			positionHold: ids[hold],
			person: ids[`${hold}Person`],
			governanceBody: ids.board,
			currency: 'EUR',
			setByDecision: ids.decision,
			...data,
		}),
	)
}

/**
 * A page signed in as `uid`, in a context without the suite's admin state.
 *
 * @param browser  The browser.
 * @param uid      The account.
 * @param password Its password.
 * @return The page.
 */
async function signIn(browser: Browser, uid: string, password: string): Promise<Page> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto(`${BASE}/index.php/login`)
	await page.locator('input[name="user"]').fill(uid)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	return page
}

/**
 * The widget on a detail page whose heading is `title`.
 *
 * @param page  The page.
 * @param title The widget title, exactly.
 * @return The widget's container.
 */
function widget(page: Page, title: string): Locator {
	const heading = page.getByRole('heading', { name: title, exact: true })
	return page.locator('.grid-stack-item, section, article').filter({ has: heading }).last()
}

test.beforeAll(async ({ playwright }) => {
	admin = await adminActor(playwright)
	anon = await anonymousActor(playwright)

	// Created here rather than through provisionAccount so the browser can
	// sign in with the same password the API actor uses.
	const created = await admin.ctx.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
		data: { password: memberPassword, userid: memberUid },
	})
	expect(created.ok(), await describe(created)).toBe(true)
	member = await actorFor(playwright, memberUid, memberPassword)

	ids.board = uuidOf(
		await createObject(admin, ledger, 'governance-body', {
			name: `${tag} supervisory board`,
			bodyType: 'supervisory-board',
			domain: 'corporate',
		}),
	)
	ids.decision = uuidOf(
		await createObject(admin, ledger, 'decision', {
			title: `${tag} fees of the supervisory board ${thisYear}`,
			text: 'The general meeting sets the fees of the supervisory board.',
			decisionType: 'remuneration',
		}),
	)
	ids.chairPosition = uuidOf(
		await createObject(admin, ledger, 'position-type', {
			name: `${tag} chair`,
			governanceBody: ids.board,
		}),
	)
	ids.memberPosition = uuidOf(
		await createObject(admin, ledger, 'position-type', {
			name: `${tag} member`,
			governanceBody: ids.board,
		}),
	)

	await holder('chair', 'Janneke de Bruin', 'chair', 'chairPosition')
	await holder('colleague', 'Mark van den Berg', 'member', 'memberPosition')

	// The chair: last year, and this year (not disclosed).
	await remuneration('chairLastYear', 'chair', {
		year: thisYear - 1,
		fixedFee: 30000,
		expenseAllowance: 1500,
		disclosed: true,
		publicationDate: `${thisYear - 1}-04-30`,
	})
	await remuneration('chairThisYear', 'chair', {
		year: thisYear,
		fixedFee: 32000,
		expenseAllowance: 1500,
		disclosed: false,
	})
	// The colleague: this year, disclosed and published.
	await remuneration('colleagueThisYear', 'colleague', {
		year: thisYear,
		fixedFee: 24000,
		disclosed: true,
		publicationDate: dateFromToday(-1),
	})
	// Disclosed, but the publication date is still ahead.
	await remuneration('colleagueFuture', 'colleague', {
		year: thisYear + 1,
		fixedFee: 25000,
		disclosed: true,
		publicationDate: dateFromToday(30),
	})
})

test.afterAll(async () => {
	await ledger.cleanup(admin)
	await removeAccounts(admin, [memberUid])
	await disposeActors(admin, anon, member)
})

test('the secretary records the chair\'s fee, linked to the decision that set it', async () => {
	const resp = await readObject(admin, SCHEMA, ids.chairThisYear)
	expect(resp.status(), await describe(resp)).toBe(200)
	const record = await resp.json()
	expect(record.positionHold).toBe(ids.chair)
	expect(record.fixedFee).toBe(32000)
	expect(record.expenseAllowance).toBe(1500)
	expect(record.setByDecision).toBe(ids.decision)
})

test('a member cannot read a colleague\'s pay that is not disclosed', async () => {
	const { status, results } = await listObjects(member, SCHEMA, { governanceBody: ids.board })
	expect(status).toBe(200)
	const found = results.map(uuidOf)
	expect(found).not.toContain(ids.chairThisYear)
	expect(found).not.toContain(ids.colleagueFuture)

	const direct = await readObject(member, SCHEMA, ids.chairThisYear)
	expect([403, 404], await describe(direct)).toContain(direct.status())
})

test('disclosed pay whose publication date has passed is public', async () => {
	const { status, results } = await listObjects(anon, SCHEMA, { governanceBody: ids.board })
	expect(status).toBe(200)
	const found = results.map(uuidOf)
	expect(found).toContain(ids.colleagueThisYear)
	expect(found).toContain(ids.chairLastYear)
	expect(found).not.toContain(ids.chairThisYear)
	expect(found).not.toContain(ids.colleagueFuture)
})

test('the secretariat lists every record of the board', async () => {
	const { status, results } = await listObjects(admin, SCHEMA, { governanceBody: ids.board })
	expect(status).toBe(200)
	expect(results.map(uuidOf).sort()).toEqual(
		[ids.chairLastYear, ids.chairThisYear, ids.colleagueThisYear, ids.colleagueFuture].sort(),
	)
})

test('the secretary sees this year\'s two records and a total of 56000 on the body page', async ({ page }) => {
	await page.goto(`${APP}/governance-bodies/${ids.board}`)

	const list = widget(page, 'Remuneration this year')
	await expect(list).toBeVisible({ timeout: 20_000 })
	await expect(list.getByText(/32[.,\s]?000/).first()).toBeVisible()
	await expect(list.getByText(/24[.,\s]?000/).first()).toBeVisible()
	// Last year's and next year's records are not this year's.
	await expect(list.getByText(/30[.,\s]?000/)).toHaveCount(0)
	await expect(list.getByText(/25[.,\s]?000/)).toHaveCount(0)

	const total = widget(page, 'Remuneration total')
	await expect(total.getByText(/56[.,\s]?000/).first()).toBeVisible({ timeout: 20_000 })
})

test('a member without the secretariat group does not see the undisclosed fee on the body page', async ({ browser }) => {
	const page = await signIn(browser, memberUid, memberPassword)
	await page.goto(`${APP}/governance-bodies/${ids.board}`)

	const list = widget(page, 'Remuneration this year')
	await expect(list).toBeVisible({ timeout: 20_000 })
	await expect(list.getByText(/32[.,\s]?000/)).toHaveCount(0)
	await page.context().close()
})

test('the chair\'s hold lists this year above last year', async ({ page }) => {
	await page.goto(`${APP}/position-holds/${ids.chair}`)

	const list = widget(page, 'Remuneration')
	await expect(list).toBeVisible({ timeout: 20_000 })
	const rows = list.getByRole('row').filter({ hasText: /\b(19|20)\d{2}\b/ })
	await expect(rows).toHaveCount(2)
	await expect(rows.nth(0)).toContainText(String(thisYear))
	await expect(rows.nth(1)).toContainText(String(thisYear - 1))
})
