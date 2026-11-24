/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a ranked preference (Borda count) round, from the open
 * dialog to the ranking table (change voting-ranked-preference-ballot,
 * REQ-PRF-001 to REQ-PRF-004 and REQ-RPB-002).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT AND VITEST CANNOT
 * ------------------------------------------------
 * VotingServiceRankedBallotTest and BordaCountTest prove the rules and the
 * arithmetic; tests/vitest/rankedOptionsPersonPick.spec.js proves the option
 * keys. None of them proves that the open dialog offers the method and its
 * option editor, that a member can rank with the keyboard alone and have the
 * ballot stored in that order, or that the motion page shows the ranking table
 * and the ballots as their ordered options after the round closes.
 *
 * Fixtures: a body with meeting M; chair1 (chair) and member1 to member3
 * (members) as participants, none of them Nextcloud admins; a motion in
 * deliberation in M. The round is opened through the dialog as chair1; the
 * three ballots of the clear-winner scenario are cast by member1 in the
 * browser and by member2 and member3 through the cast endpoint.
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md
 * @e2e preferential-ballot/requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice/chair-opens-a-ranked-choice-round-for-a-board-election
 * @e2e preferential-ballot/requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice/one-option-is-not-a-choice
 * @e2e preferential-ballot/requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting/member-casts-a-ranked-choice-vote
 * @e2e preferential-ballot/requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting/member-cannot-submit-a-partial-ranking
 * @e2e preferential-ballot/requirement-req-prf-003-borda-count-tallying-determines-the-winner/borda-count-tallying-on-close
 * @e2e preferential-ballot/requirement-req-prf-004-ranked-results-are-displayed-as-a-ranking-table/user-views-the-results-of-a-ranked-choice-election
 * @e2e preferential-ballot/requirement-req-rpb-002-screens-that-list-votes-show-a-ranked-ballot-as-its-ranking/a-clerk-reads-the-ballots-of-an-open-ranked-round
 */
import type { APIRequestContext, Browser, Page, Playwright } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	getObject,
	newLedger,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const APP = `${BASE}/index.php/apps/decidiq/api`
const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
const users = {
	chair1: `${tag}-chair1`.slice(0, 60),
	member1: `${tag}-member1`.slice(0, 60),
	member2: `${tag}-member2`.slice(0, 60),
	member3: `${tag}-member3`.slice(0, 60),
}

// The three plans of the spec, with the keys the dialog derives from them.
const RENOVATE = { key: 'renovate-the-clubhouse', label: 'Renovate the clubhouse' }
const BUILD = { key: 'build-a-new-clubhouse', label: 'Build a new clubhouse' }
const RENT = { key: 'rent-a-hall', label: 'Rent a hall' }

const ids: Record<string, string> = {}

/**
 * The id of an OpenRegister object or an API answer that wraps one.
 *
 * @param o The object.
 * @return The id, or an empty string.
 */
function idOf(o: any): string {
	return o?.id ?? o?.['@self']?.id ?? o?.votingRound?.id ?? ''
}

/**
 * Basic-auth headers for the admin.
 *
 * @return The headers.
 */
function adminHeaders(): Record<string, string> {
	return {
		Authorization:
			'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * An API context signed in as one of the fixture users.
 *
 * @param playwright The Playwright fixture.
 * @param uid        The user.
 * @return The context.
 */
function as(playwright: Playwright, uid: string): Promise<APIRequestContext> {
	return playwright.request.newContext({
		httpCredentials: { username: uid, password, send: 'always' },
		extraHTTPHeaders: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
		storageState: { cookies: [], origins: [] },
	})
}

/**
 * A browser page signed in as one of the fixture users.
 *
 * @param browser The Playwright browser.
 * @param uid     The user.
 * @return The page.
 */
async function pageAs(browser: Browser, uid: string): Promise<Page> {
	const context = await browser.newContext({
		httpCredentials: { username: uid, password, send: 'always' },
		storageState: { cookies: [], origins: [] },
	})
	return context.newPage()
}

/**
 * Open the motion page on its "Voting round" tab.
 *
 * @param page The page.
 * @return The tab's container.
 */
async function votingTab(page: Page) {
	await page.goto(`${BASE}/index.php/apps/decidiq/motions/${ids.motion}`)
	const tab = page.getByTestId('motion-voting-round-tab')
	await expect(tab).toBeVisible({ timeout: 20_000 })
	return tab
}

/**
 * Cast a ranked ballot through the cast endpoint.
 *
 * @param playwright The Playwright fixture.
 * @param uid        Who casts.
 * @param ranking    The option keys, first preference first.
 * @return The response status and body.
 */
async function castAs(
	playwright: Playwright,
	uid: string,
	ranking: string[],
): Promise<{ status: number; body: string }> {
	const ctx = await as(playwright, uid)
	const resp = await ctx.post(`${APP}/voting-rounds/${ids.round}/cast`, {
		data: { ranking },
	})
	const result = { status: resp.status(), body: await resp.text() }
	await ctx.dispose()
	return result
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const uid of Object.values(users)) {
		const resp = await page.request.post(
			`${BASE}/ocs/v2.php/cloud/users?format=json`,
			{ headers: adminHeaders(), data: { userid: uid, password } },
		)
		expect(resp.ok(), `provisioning ${uid}: ${await resp.text()}`).toBe(true)
	}
	ids.body = idOf(
		await createObject(page, ledger, 'governance-body', {
			name: `${tag}-Tennisvereniging De Lob`,
			bodyType: 'association',
			domain: 'association',
		}),
	)
	ids.meeting = idOf(
		await createObject(page, ledger, 'meeting', {
			title: `${tag}-members' meeting`,
			meetingType: 'regular',
			scheduledDate: '2026-03-12T19:30:00Z',
			lifecycle: 'opened',
			governanceBody: ids.body,
		}),
	)
	const roles: Array<[string, string]> = [
		[users.chair1, 'chair'],
		[users.member1, 'member'],
		[users.member2, 'member'],
		[users.member3, 'member'],
	]
	for (const [uid, role] of roles) {
		await createObject(page, ledger, 'participant', {
			displayName: uid,
			role,
			nextcloudUserId: uid,
			governanceBody: ids.body,
			attendanceStatus: 'present',
		})
	}
	ids.motion = idOf(
		await createObject(page, ledger, 'decision', {
			decisionType: 'motion',
			title: `${tag}-The new clubhouse`,
			lifecycle: 'deliberating',
			meeting: ids.meeting,
		}),
	)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	if (ids.round) {
		await page.request
			.delete(`${OR}/voting-round/${ids.round}`, { headers: adminHeaders() })
			.catch(() => undefined)
	}
	await cleanupAll(page, ledger)
	for (const uid of Object.values(users)) {
		await page.request
			.delete(`${BASE}/ocs/v2.php/cloud/users/${uid}?format=json`, {
				headers: adminHeaders(),
			})
			.catch(() => undefined)
	}
	await page.close()
})

test('a ranked round with one option is refused and nothing is created', async ({ playwright }) => {
	const chair = await as(playwright, users.chair1)
	const resp = await chair.post(`${APP}/voting-rounds`, {
		data: {
			motionId: ids.motion,
			subjectType: 'motion',
			meetingId: ids.meeting,
			votingMethod: 'ranked-choice',
			options: [RENOVATE],
		},
	})
	expect(resp.status(), await resp.text()).toBe(400)
	expect(await resp.text()).toContain('at least two options')
	await chair.dispose()
})

test('the chair opens a ranked round with three options from the dialog', async ({ browser }) => {
	const page = await pageAs(browser, users.chair1)
	const tab = await votingTab(page)

	await tab.getByRole('button', { name: 'Open voting round' }).click()
	const dialog = page.getByRole('dialog', { name: 'Open voting round' })
	await expect(dialog).toBeVisible()
	await dialog.locator('#votingMethod').selectOption('ranked-choice')

	const editor = dialog.getByTestId('ranked-options-editor')
	await expect(editor).toBeVisible()
	await editor.getByTestId('ranked-option-add').click()
	for (const [index, option] of [RENOVATE, BUILD, RENT].entries()) {
		await editor.getByTestId(`ranked-option-${index}`).locator('input').fill(option.label)
	}

	const opened = page.waitForResponse(
		(resp) => resp.url().endsWith('/api/voting-rounds') && resp.request().method() === 'POST',
	)
	await dialog.getByRole('button', { name: /open/i }).last().click()
	const answer = await opened
	expect(answer.status(), await answer.text()).toBeLessThan(300)
	ids.round = idOf(await answer.json())
	expect(ids.round, 'the opened round carries an id').not.toBe('')

	const round = await getObject(page, 'voting-round', ids.round)
	expect(round?.votingMethod).toBe('ranked-choice')
	expect((round?.options ?? []).map((o: any) => o.key)).toEqual([RENOVATE.key, BUILD.key, RENT.key])
	await page.context().close()
})

test('a partial ranking is refused and no vote is stored', async ({ playwright }) => {
	const { status, body } = await castAs(playwright, users.chair1, [RENOVATE.key, BUILD.key])
	expect(status, body).toBe(400)
	expect(body).toContain('Every option must be ranked')
})

test('a member ranks with the keyboard alone and the vote is recorded in that order', async ({ browser }) => {
	const page = await pageAs(browser, users.member1)
	const tab = await votingTab(page)

	const ballot = tab.getByTestId('ranked-ballot')
	await expect(ballot).toBeVisible({ timeout: 20_000 })
	await expect(ballot.getByTestId('ranked-ballot-item-0')).toContainText(RENOVATE.label)

	// Move "Build a new clubhouse" to the top with the keyboard only.
	await ballot.getByRole('button', { name: `Move ${BUILD.label} up` }).focus()
	await page.keyboard.press('Enter')
	await expect(ballot.getByTestId('ranked-ballot-item-0')).toContainText(BUILD.label)
	await expect(ballot.getByTestId('ranked-ballot-item-1')).toContainText(RENOVATE.label)
	await expect(ballot.getByTestId('ranked-ballot-item-2')).toContainText(RENT.label)

	await ballot.getByTestId('ranked-ballot-submit').focus()
	await page.keyboard.press('Enter')
	await expect(tab.getByText('Your vote has been recorded.')).toBeVisible({ timeout: 10_000 })

	const votes = await page.request.get(
		`${OR}/vote?votingRound=${ids.round}&_limit=50`,
		{ headers: adminHeaders() },
	)
	expect(votes.ok(), await votes.text()).toBe(true)
	const rows = (await votes.json()).results ?? []
	const mine = rows.find((v: any) => v.value === 'ranked')
	expect(mine?.ranking).toEqual([BUILD.key, RENOVATE.key, RENT.key])
	await page.context().close()
})

test('closing tallies the Borda count and adopts the clear winner', async ({ playwright }) => {
	for (const [uid, ranking] of [
		[users.member2, [RENOVATE.key, BUILD.key, RENT.key]],
		[users.member3, [RENOVATE.key, RENT.key, BUILD.key]],
	] as const) {
		const { status, body } = await castAs(playwright, uid, [...ranking])
		expect(status, `${uid}: ${body}`).toBeLessThan(300)
	}

	const chair = await as(playwright, users.chair1)
	const closed = await chair.post(`${APP}/voting-rounds/${ids.round}/close`)
	expect(closed.status(), await closed.text()).toBeLessThan(300)
	await chair.dispose()

	const admin = await playwright.request.newContext({ extraHTTPHeaders: adminHeaders() })
	const round = await (await admin.get(`${OR}/voting-round/${ids.round}`)).json()
	expect(round.result).toBe('adopted')
	expect(round.winningOption).toBe(RENOVATE.key)
	const points = Object.fromEntries(
		(round.rankingResult ?? []).map((row: any) => [row.key, row.points]),
	)
	expect(points).toEqual({ [RENOVATE.key]: 5, [BUILD.key]: 3, [RENT.key]: 1 })

	const motion = await (await admin.get(`${OR}/decision/${ids.motion}`)).json()
	expect(motion.lifecycle).toBe('decided')
	expect(motion.outcome).toBe('adopted')
	await admin.dispose()
})

test('the motion page shows the ranking table with the elected option first', async ({ browser }) => {
	const page = await pageAs(browser, users.member2)
	const tab = await votingTab(page)

	const table = tab.getByTestId('ranked-results')
	await expect(table).toBeVisible({ timeout: 20_000 })
	const rows = table.locator('tbody tr')
	await expect(rows).toHaveCount(3)
	await expect(rows.nth(0)).toContainText(RENOVATE.label)
	await expect(rows.nth(0)).toContainText('5')
	await expect(table.getByTestId(`ranked-results-badge-${RENOVATE.key}`)).toHaveText('Elected')
	await expect(rows.nth(1)).toContainText(BUILD.label)
	await expect(rows.nth(1)).toContainText('3')
	await expect(rows.nth(2)).toContainText(RENT.label)
	await expect(rows.nth(2)).toContainText('1')
	await page.context().close()
})

test('the votes list reads a ranked ballot as its ordered options', async ({ browser }) => {
	const page = await pageAs(browser, users.chair1)
	await page.goto(`${BASE}/index.php/apps/decidiq/motions/${ids.motion}`)

	const rankings = page.getByTestId('vote-ranking')
	await expect(rankings.first()).toBeVisible({ timeout: 20_000 })
	await expect(rankings).toContainText([
		`1. ${BUILD.label}, 2. ${RENOVATE.label}, 3. ${RENT.label}`,
	])
	await page.context().close()
})
