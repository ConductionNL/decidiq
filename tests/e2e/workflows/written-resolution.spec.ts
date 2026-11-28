/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — a decision put to a body in writing
 * (change voting-written-resolution, task 6, REQ-WRS-001 to REQ-WRS-006).
 *
 * Three accounts are created for this run: the secretary of a fresh body and
 * two members. Each signs in through the login form in its own browser context
 * with an empty storage state, so no admin session is involved in any of the
 * clicks. The secretary opens the round from the decision page, all three answer
 * "for", and the page shows the round adopted with every answer and its time.
 *
 * The secretary's Participant row is what puts the account in the body's
 * signatory scope group (GovernanceRoleScopeProjector); the Membership rows with
 * a Person carrying nextcloudUserId are what make up the electorate
 * (WrittenElectorateResolver). Both are seeded through the OpenRegister object
 * API as the admin and removed again in afterAll, with the accounts.
 *
 * @spec openspec/changes/archive/2026-11-24-voting-written-resolution/specs/written-resolution/spec.md
 * @e2e voting-written-resolution/requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing/the-secretary-sends-a-resolution-to-the-supervisory-board
 * @e2e voting-written-resolution/requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page/a-member-agrees
 * @e2e voting-written-resolution/requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline/everyone-agrees-before-the-deadline
 * @e2e voting-written-resolution/requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure/the-auditor-reads-the-record
 * @e2e voting-written-resolution/requirement-req-wrs-006-entitled-members-are-told-when-a-written-round-opens/the-notification-reaches-the-board
 */
import type { Browser, Page } from '@playwright/test'
import type { Actor } from '../support/api-actors.ts'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import {
	actorFor,
	adminActor,
	BASE,
	createObject,
	describe,
	disposeActors,
	listObjects,
	newRunId,
	ObjectLedger,
	readObject,
	removeAccounts,
	replaceObject,
	uuidOf,
} from '../support/api-actors.ts'

test.describe.configure({ mode: 'serial' })

const run = newRunId()
const tag = `e2e-wrs-${run}`
const ledger = new ObjectLedger([
	'vote',
	'voting-round',
	'participant',
	'membership',
	'person',
	'decision',
	'governance-body',
])

/** One account of this run. */
interface Account {
	uid: string
	password: string
	role: 'secretary' | 'member'
	name: string
}

const accounts: Account[] = ['secretary', 'member', 'member'].map((role, i) => ({
	name: `${tag} ${role} ${i + 1}`,
	password: `Dq-${randomBytes(18).toString('hex')}-Pw1!`,
	role: role as Account['role'],
	uid: `wrs-${i + 1}-${run}`.slice(0, 60),
}))

let admin: Actor
const ids: Record<string, string> = {}
const pages: Page[] = []

/**
 * Sign in as `account` through the login form in a fresh context.
 *
 * @param browser The browser.
 * @param account The account.
 * @return A page on the decision under test.
 */
async function decisionPageAs(browser: Browser, account: Account): Promise<Page> {
	const context = await browser.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	pages.push(page)
	await page.goto('/index.php/login', { timeout: 60_000 })
	await page.locator('input[name="user"]').fill(account.uid)
	await page.locator('input[name="password"]').fill(account.password)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForURL((url) => !/\/login(\?|$|\/)/.test(url.pathname), {
		timeout: 60_000,
	})
	await page.goto(`/index.php/apps/decidiq/decisions/${ids.decision}`, {
		timeout: 60_000,
	})
	await expect(page.getByTestId('written-resolution-tab')).toBeVisible({
		timeout: 30_000,
	})
	return page
}

/**
 * Move the decision along declared transitions to `lifecycle`.
 *
 * @param lifecycle The state to reach.
 * @return Resolves once saved.
 */
async function decisionTo(lifecycle: string): Promise<void> {
	const resp = await readObject(admin, 'decision', ids.decision)
	const { '@self': _self, ...data } = await resp.json()
	const saved = await replaceObject(admin, 'decision', ids.decision, {
		...data,
		lifecycle,
	})
	expect(saved.status(), await describe(saved)).toBeLessThan(300)
}

/**
 * The decision's written voting round, as the admin.
 *
 * @return The round, or undefined while none exists.
 */
async function writtenRound(): Promise<any> {
	const { results } = await listObjects(admin, 'voting-round', {
		decision: ids.decision,
	})
	return results.find((r: any) => r.procedure === 'written')
}

test.beforeAll(async ({ playwright }) => {
	admin = await adminActor(playwright)

	for (const account of accounts) {
		const resp = await admin.ctx.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
			data: { password: account.password, userid: account.uid },
		})
		expect(resp.ok(), await describe(resp)).toBe(true)
	}

	ids.body = uuidOf(
		await createObject(admin, ledger, 'governance-body', {
			name: `${tag} supervisory board`,
			bodyType: 'supervisory-board',
			domain: 'corporate',
			active: true,
		}),
	)
	const yesterday = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10)
	for (const [i, account] of accounts.entries()) {
		const person = uuidOf(
			await createObject(admin, ledger, 'person', {
				name: account.name,
				nextcloudUserId: account.uid,
			}),
		)
		ids[`person${i}`] = person
		await createObject(admin, ledger, 'membership', {
			role: account.role,
			person,
			governanceBody: ids.body,
			startDate: yesterday,
			votingWeight: 1,
		})
	}
	// The participant row projects the secretary into the body's signatory scope.
	await createObject(admin, ledger, 'participant', {
		displayName: accounts[0].name,
		role: 'secretary',
		nextcloudUserId: accounts[0].uid,
		governanceBody: ids.body,
	})

	ids.decision = uuidOf(
		await createObject(admin, ledger, 'decision', {
			title: `${tag} Appoint the external auditor for 2026`,
			text: 'The board appoints the external auditor for the 2026 accounts.',
			decisionType: 'motion',
		}),
	)
	await decisionTo('proposed')
})

test.afterAll(async () => {
	for (const page of pages) {
		await page.context().close().catch(() => undefined)
	}
	await ledger.cleanup(admin)
	const round = await writtenRound().catch(() => undefined)
	if (round) {
		await admin.ctx
			.delete(`${BASE}/index.php/apps/openregister/api/objects/decidiq/voting-round/${uuidOf(round)}`)
			.catch(() => undefined)
	}
	await removeAccounts(admin, accounts.map((a) => a.uid))
	await disposeActors(admin)
})

test('a member who is not in the presiding roles is not offered "Decide in writing"', async ({ browser }) => {
	const page = await decisionPageAs(browser, accounts[1])
	await expect(page.getByTestId('written-resolution-none')).toBeVisible()
	await expect(page.getByTestId('written-resolution-open')).toHaveCount(0)
})

test('the secretary puts the decision to the body in writing', async ({ browser }) => {
	const page = await decisionPageAs(browser, accounts[0])
	await page.getByTestId('written-resolution-open').click()

	const modal = page.getByTestId('written-resolution-modal')
	await expect(modal).toBeVisible()
	const deadline = new Date(Date.now() + 3 * 86_400_000)
	const local = new Date(deadline.getTime() - deadline.getTimezoneOffset() * 60_000)
		.toISOString()
		.slice(0, 16)
	await page.locator('#decidiq-written-deadline').fill(local)
	await page.getByTestId('written-resolution-confirm').click()
	await expect(modal).toBeHidden({ timeout: 20_000 })

	await expect(page.getByTestId('written-resolution-progress')).toContainText('0 of 3')

	const round = await writtenRound()
	expect(round, 'a written round exists for the decision').toBeTruthy()
	expect(round.governanceBody).toBe(ids.body)
	expect(round.writtenRule).toBe('unanimous')
	expect(round.electorateUserIds.sort()).toEqual(accounts.map((a) => a.uid).sort())
	ids.round = uuidOf(round)
})

test('every entitled member is notified with a link to the decision', async ({ playwright }) => {
	for (const account of accounts) {
		const member = await actorFor(playwright, account.uid, account.password)
		const resp = await member.ctx.get(
			`${BASE}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`,
		)
		expect(resp.ok(), await describe(resp)).toBe(true)
		const notifications = (await resp.json()).ocs?.data ?? []
		const forDecision = notifications.filter(
			(n: any) => n.app === 'decidiq' && String(n.link ?? '').includes(ids.decision),
		)
		expect(forDecision, `${account.uid} has a notification for the decision`).toHaveLength(1)
		await disposeActors(member)
	}
})

test('the three members answer for and the round is adopted at once', async ({ browser }) => {
	for (const [i, account] of accounts.entries()) {
		const page = await decisionPageAs(browser, account)
		const answers = page.getByTestId('written-resolution-answers')
		await expect(answers).toBeVisible()
		await answers.getByTestId('written-answer-for').click()
		if (i < accounts.length - 1) {
			await expect(page.getByTestId('written-resolution-progress')).toContainText(
				`${i + 1} of 3`,
			)
		}
	}

	const round = await writtenRound()
	expect(round.closedAt, 'the round closed on the last answer').toBeTruthy()
	expect(round.result).toBe('adopted')
	expect(round.closedReason).toBe('all-answered')

	const decision = await (await readObject(admin, 'decision', ids.decision)).json()
	expect(decision.lifecycle).toBe('decided')
})

test('the decision page keeps the record of every answer and the close reason', async ({ browser }) => {
	const page = await decisionPageAs(browser, accounts[1])
	await expect(page.getByTestId('written-resolution-result')).toContainText(/adopted/i)
	await expect(page.getByTestId('written-resolution-answers')).toHaveCount(0)
	const tab = page.getByTestId('written-resolution-tab')
	for (const account of accounts) {
		await expect(tab).toContainText(account.name)
	}
	await expect(tab).not.toContainText('No answer')
})
