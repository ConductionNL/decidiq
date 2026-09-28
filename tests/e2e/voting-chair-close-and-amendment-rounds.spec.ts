/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the meeting's own chair and secretary run its votes, and an
 * amendment is voted on from the amendment page (change
 * voting-chair-close-and-amendment-rounds).
 *
 * Fixtures: meeting M with chair1 (chair), griffier1 (secretary) and member1
 * (member), none of them Nextcloud admins; meeting N with griffier2
 * (secretary); motion "Groen dak" in M with amendments A1 (order 1) and A2
 * (order 2); a motion without a meeting with one amendment.
 *
 * @spec openspec/changes/voting-chair-close-and-amendment-rounds/specs/voting-round-management/spec.md
 */
import type { APIRequestContext, Browser, Playwright } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const APP = `${BASE}/index.php/apps/decidiq/api`

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
const users = {
	chair1: `${tag}-chair1`.slice(0, 60),
	griffier1: `${tag}-griffier1`.slice(0, 60),
	griffier2: `${tag}-griffier2`.slice(0, 60),
	member1: `${tag}-member1`.slice(0, 60),
}
const ids: Record<string, string> = {}

/**
 * The id of an OpenRegister object.
 *
 * @param o The object.
 */
function idOf(o: { id?: string; '@self'?: { id?: string } }): string {
	return o?.id ?? o?.['@self']?.id ?? ''
}

/**
 * Basic-auth headers for the admin.
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
 * @param uid The user.
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
 * @param uid The user.
 */
async function pageAs(browser: Browser, uid: string) {
	const context = await browser.newContext({
		httpCredentials: { username: uid, password, send: 'always' },
		storageState: { cookies: [], origins: [] },
	})
	return context.newPage()
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
	for (const name of ['M', 'N']) {
		ids[`body${name}`] = idOf(
			await createObject(page, ledger, 'governance-body', {
				name: `${tag}-body-${name}`,
				bodyType: 'legislative',
			}),
		)
		ids[name] = idOf(
			await createObject(page, ledger, 'meeting', {
				title: `${tag}-meeting-${name}`,
				meetingType: 'regular',
				scheduledDate: '2026-03-12T19:30:00Z',
				lifecycle: 'opened',
				governanceBody: ids[`body${name}`],
			}),
		)
	}
	const roles: Array<[string, string, string]> = [
		[users.chair1, 'chair', 'M'],
		[users.griffier1, 'secretary', 'M'],
		[users.member1, 'member', 'M'],
		[users.griffier2, 'secretary', 'N'],
	]
	for (const [uid, role, meeting] of roles) {
		await createObject(page, ledger, 'participant', {
			displayName: uid,
			role,
			nextcloudUserId: uid,
			governanceBody: ids[`body${meeting}`],
			attendanceStatus: 'present',
		})
	}
	ids.motion = idOf(
		await createObject(page, ledger, 'decision', {
			decisionType: 'motion',
			title: `${tag}-Groen dak op het stadhuis`,
			lifecycle: 'deliberating',
			meeting: ids.M,
		}),
	)
	for (const [key, order] of [
		['A1', 1],
		['A2', 2],
	] as const) {
		ids[key] = idOf(
			await createObject(page, ledger, 'decision', {
				decisionType: 'amendment',
				title: `${tag}-${key}`,
				lifecycle: 'deliberating',
				amends: ids.motion,
				votingOrder: order,
			}),
		)
	}
	ids.lonelyMotion = idOf(
		await createObject(page, ledger, 'decision', {
			decisionType: 'motion',
			title: `${tag}-no meeting`,
			lifecycle: 'deliberating',
		}),
	)
	ids.lonelyAmendment = idOf(
		await createObject(page, ledger, 'decision', {
			decisionType: 'amendment',
			title: `${tag}-lonely`,
			lifecycle: 'deliberating',
			amends: ids.lonelyMotion,
			votingOrder: 1,
		}),
	)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
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

// @e2e voting-round-management::the-answer-matches-the-endpoints
// @e2e voting-round-management::a-secretary-may-close-but-may-not-cast-the-chairs-vote
test('the permissions read answers per role in the meeting', async ({
	playwright,
}) => {
	const expected: Record<string, Record<string, boolean>> = {
		[users.chair1]: {
			canOpen: true,
			canClose: true,
			canEnterTally: true,
			canCastChairVote: true,
		},
		[users.griffier1]: {
			canOpen: true,
			canClose: true,
			canEnterTally: true,
			canCastChairVote: false,
		},
		[users.member1]: {
			canOpen: false,
			canClose: false,
			canEnterTally: false,
			canCastChairVote: false,
		},
	}
	for (const [uid, answer] of Object.entries(expected)) {
		const ctx = await as(playwright, uid)
		const resp = await ctx.get(`${APP}/meetings/${ids.M}/voting-permissions`)
		expect(resp.status(), uid).toBe(200)
		expect(await resp.json(), uid).toEqual(answer)
		await ctx.dispose()
	}
})

// @e2e voting-round-management::a-member-sees-neither-the-open-nor-the-close-control
test('a member sees no open control on a deliberating motion', async ({
	browser,
}) => {
	const page = await pageAs(browser, users.member1)
	await page.goto(`${BASE}/index.php/apps/decidiq/motions/${ids.motion}`)
	await expect(page.getByTestId('motion-voting-round-tab')).toBeVisible({
		timeout: 20_000,
	})
	await expect(
		page.getByRole('button', { name: 'Open voting round' }),
	).toHaveCount(0)
	await page.context().close()
})

// @e2e voting-round-management::the-chair-votes-on-an-amendment
// @e2e voting-round-management::an-amendment-out-of-order-is-refused-on-the-page
test('the chair opens the first amendment round from its page, the second is refused', async ({
	browser,
}) => {
	const page = await pageAs(browser, users.chair1)
	await page.goto(`${BASE}/index.php/apps/decidiq/amendments/${ids.A2}`)
	const a2 = page.getByTestId('amendment-voting-round-tab')
	await expect(a2).toBeVisible({ timeout: 20_000 })
	await a2.getByRole('button', { name: 'Open voting round' }).click()
	await page
		.getByRole('dialog', { name: 'Open voting round' })
		.getByRole('button', { name: /open/i })
		.last()
		.click()
	await expect(a2.getByRole('alert')).toBeVisible()

	await page.goto(`${BASE}/index.php/apps/decidiq/amendments/${ids.A1}`)
	const a1 = page.getByTestId('amendment-voting-round-tab')
	await a1.getByRole('button', { name: 'Open voting round' }).click()
	await page
		.getByRole('dialog', { name: 'Open voting round' })
		.getByRole('button', { name: /open/i })
		.last()
		.click()
	await expect(a1.getByRole('button', { name: 'Close voting round' })).toBeVisible(
		{ timeout: 15_000 },
	)
	await page.context().close()
})

// @e2e voting-round-management::a-chair-who-is-not-an-admin-closes-a-vote
test('the chair, not an admin, closes the amendment round', async ({ browser }) => {
	const page = await pageAs(browser, users.chair1)
	await page.goto(`${BASE}/index.php/apps/decidiq/amendments/${ids.A1}`)
	const a1 = page.getByTestId('amendment-voting-round-tab')
	await a1
		.getByRole('button', { name: 'Close voting round' })
		.click({ timeout: 20_000 })
	await a1
		.getByRole('button', { name: /confirm|close/i })
		.last()
		.click()
	await expect(a1.getByRole('button', { name: 'Close voting round' })).toHaveCount(
		0,
		{ timeout: 15_000 },
	)
	await page.context().close()
})

// @e2e voting-round-management::a-meeting-secretary-enters-a-show-of-hands-result
// @e2e voting-round-management::the-secretary-of-another-meeting-is-refused
test("tally entry is checked against the round's own meeting", async ({
	playwright,
}) => {
	const chair = await as(playwright, users.chair1)
	const opened = await chair.post(`${APP}/voting-rounds`, {
		data: {
			motionId: ids.A2,
			subjectType: 'amendment',
			meetingId: ids.M,
			votingMethod: 'show-of-hands',
		},
	})
	expect(opened.status(), await opened.text()).toBeLessThan(300)
	const round = await opened.json()
	const roundId = round.id ?? round.votingRound?.id ?? round['@self']?.id
	await chair.dispose()

	const other = await as(playwright, users.griffier2)
	const refused = await other.post(`${APP}/voting-rounds/${roundId}/tally`, {
		data: { votesFor: 1, votesAgainst: 0, votesAbstain: 0 },
	})
	expect(refused.status()).toBe(403)
	await other.dispose()

	const secretary = await as(playwright, users.griffier1)
	const saved = await secretary.post(`${APP}/voting-rounds/${roundId}/tally`, {
		data: { votesFor: 14, votesAgainst: 9, votesAbstain: 2 },
	})
	expect(saved.status(), await saved.text()).toBe(200)
	await secretary.dispose()
})

// @e2e voting-round-management::the-motion-vote-follows-its-amendments
test('with both amendments decided the motion round can be opened', async ({
	playwright,
}) => {
	const chair = await as(playwright, users.chair1)
	const opened = await chair.post(`${APP}/voting-rounds`, {
		data: { motionId: ids.motion, subjectType: 'motion', meetingId: ids.M },
	})
	expect(opened.status(), await opened.text()).toBeLessThan(300)
	await chair.dispose()
})

// @e2e voting-round-management::an-amendment-whose-motion-has-no-meeting
// @e2e voting-round-management::an-admin-keeps-the-controls-when-the-round-has-no-meeting
test('without a meeting the admin sees a disabled open button that says why', async ({
	page,
}) => {
	const resp = await page.request.get(`${APP}/voting-permissions`)
	expect((await resp.json()).canClose).toBe(true)
	await page.goto(
		`${BASE}/index.php/apps/decidiq/amendments/${ids.lonelyAmendment}`,
	)
	const tab = page.getByTestId('amendment-voting-round-tab')
	const open = tab.getByRole('button', { name: 'Open voting round' })
	await expect(open).toBeDisabled({ timeout: 20_000 })
	await expect(open).toHaveAttribute('title', /No meeting linked/)
})
