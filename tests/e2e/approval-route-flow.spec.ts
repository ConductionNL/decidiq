/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: approval routes run as OpenRegister flows of user tasks
 * (change approval-routes-on-flow-user-tasks). The secretary sends a proposal
 * along a two-step route; the first step's approver finds the task in her own
 * task list and approves it; the decision shows the next step active. A second
 * send while the route is open is refused naming the open step, a rejection
 * concludes the route, and a route that needs a performer rule the installed
 * OpenRegister does not offer is refused when saved.
 *
 * Two real accounts act, each in its own request context with an empty
 * storage state, so nothing the admin's session carries leaks into what the
 * approver may see or do.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	getObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const sanne = `${tag}-sanne`.slice(0, 60)
const wouter = `${tag}-wouter`.slice(0, 60)
const bram = `${tag}-bram`.slice(0, 60)
const lisa = `${tag}-lisa`.slice(0, 60)
const ACCOUNTS = [sanne, wouter, bram, lisa]
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
let route: Record<string, unknown> = {}

/**
 * Basic-auth headers.
 *
 * @param user The user.
 * @param pass The password.
 */
function basic(user: string, pass: string): Record<string, string> {
	return {
		Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * A request context acting as one account, with nothing of the admin's session.
 *
 * @param playwright The Playwright instance.
 * @param user The account.
 */
async function as(playwright: { request: { newContext: (o: object) => Promise<APIRequestContext> } }, user: string): Promise<APIRequestContext> {
	return playwright.request.newContext({
		extraHTTPHeaders: basic(user, password),
		storageState: { cookies: [], origins: [] },
	})
}

/**
 * The open task of a run that an account can see, once the run has raised it.
 *
 * @param ctx The account's context.
 * @param run The run.
 */
async function openTaskOf(ctx: APIRequestContext, run: string): Promise<Record<string, any>> {
	let found: Record<string, any> | null = null
	await expect
		.poll(
			async () => {
				const resp = await ctx.get(`${BASE}/index.php/apps/openregister/api/flow-tasks`, {
					params: { runUuid: run, isTerminal: 'false' },
				})
				const results = (await resp.json())?.results ?? []
				found = results[0] ?? null
				return found?.metadata?.flowNode ?? null
			},
			{ timeout: 60_000 },
		)
		.not.toBeNull()
	return found as unknown as Record<string, any>
}

/**
 * Send a decision along the route, as the admin acting secretary.
 *
 * @param page The admin page.
 * @param decision The decision.
 */
async function send(page: any, decision: string) {
	return page.request.post(`${BASE}/index.php/apps/decidiq/api/approval-routes/instantiate`, {
		headers: await writeHeaders(page),
		data: { route, subject: decision, subjectSchema: 'decision' },
	})
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const user of ACCOUNTS) {
		const created = await page.request.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
			headers: basic(ADMIN_USER, ADMIN_PASS),
			data: { userid: user, password },
		})
		expect(created.ok(), await created.text()).toBe(true)
	}
	route = await createObject(page, ledger, 'approval-route', {
		name: `${tag} Raadscyclus Zuiddrecht`,
		subjectType: 'decision',
		active: true,
		steps: [
			{ order: 1, stageType: 'advisory', actorType: 'person', actor: sanne, label: 'Ambtelijk advies' },
			{ order: 2, stageType: 'decisive', actorType: 'person', actor: wouter, label: 'Ondertekening' },
		],
	})
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	for (const user of ACCOUNTS) {
		await page.request
			.delete(`${BASE}/ocs/v2.php/cloud/users/${user}?format=json`, { headers: basic(ADMIN_USER, ADMIN_PASS) })
			.catch(() => undefined)
	}
	await page.close()
})

// @e2e approval-routes::a-four-step-route-compiles
test('saving a route stores it, and sending it starts one run with the decision as subject', async ({ page, playwright }) => {
	const decision = await createObject(page, ledger, 'decision', { title: `${tag} Verordening parkeren binnenstad 2027` })
	const id = decision.id ?? decision['@self']?.id

	// @e2e approval-routes::a-proposal-is-sent
	const sent = await send(page, id)
	expect(sent.status(), await sent.text()).toBe(201)
	const run = (await sent.json()).run
	expect(run).toBeTruthy()

	const stored = await getObject(page, 'decision', id)
	expect(stored).toMatchObject({ approvalRun: run, approvalEngine: 'flow', approvalRouteStatus: 'running', approvalCurrentOrder: 1 })

	// The first step's approver has the open task in her own list.
	const asSanne = await as(playwright, sanne)
	const task = await openTaskOf(asSanne, run)
	expect(task.metadata.flowNode).toBe('step-1-1')
	expect(task.title).toBe('Ambtelijk advies')
	await asSanne.dispose()
})

// @e2e approval-routes::approving-moves-the-route-on
// @e2e approval-routes::the-route-tab-on-a-running-route
test('approving the first step opens the second, which shows active on the decision', async ({ page, playwright }) => {
	const decision = await createObject(page, ledger, 'decision', { title: `${tag} Parkeerverordening wijziging` })
	const id = decision.id ?? decision['@self']?.id
	const run = (await (await send(page, id)).json()).run

	const asSanne = await as(playwright, sanne)
	const first = await openTaskOf(asSanne, run)
	const done = await asSanne.post(`${BASE}/index.php/apps/openregister/api/flow-tasks/${first.uuid}/complete`, {
		data: { outcome: 'advised', comment: 'akkoord' },
	})
	expect(done.status(), await done.text()).toBeLessThan(300)
	await asSanne.dispose()

	const asWouter = await as(playwright, wouter)
	const second = await openTaskOf(asWouter, run)
	expect(second.metadata.flowNode).toBe('step-2-1')
	await asWouter.dispose()

	await expect.poll(async () => (await getObject(page, 'decision', id))?.approvalCurrentOrder, { timeout: 60_000 }).toBe(2)

	// The route tab reads the run: step 1 decided, step 2 current.
	await page.goto(`${BASE}/index.php/apps/decidiq/decisions/${id}`)
	const tab = page.getByTestId('decision-route-tab')
	await expect(tab.getByTestId('route-stage-2')).toBeVisible({ timeout: 30_000 })
	await expect(tab.getByTestId('route-progress')).toContainText('1')
})

// @e2e approval-routes::the-same-proposal-is-not-sent-twice
test('a second send while the route is open is refused naming the open step', async ({ page }) => {
	const decision = await createObject(page, ledger, 'decision', { title: `${tag} Nota reserves` })
	const id = decision.id ?? decision['@self']?.id
	expect((await send(page, id)).status()).toBe(201)

	const again = await send(page, id)
	expect(again.status()).toBe(400)
	expect((await again.json()).message).toContain('step 1 (Ambtelijk advies) is still open')
})

// @e2e approval-routes::rejecting-concludes-the-route
test('rejecting a step concludes the route as rejected and opens no later step', async ({ page, playwright }) => {
	const decision = await createObject(page, ledger, 'decision', { title: `${tag} Subsidieregeling` })
	const id = decision.id ?? decision['@self']?.id
	const run = (await (await send(page, id)).json()).run

	const asSanne = await as(playwright, sanne)
	const first = await openTaskOf(asSanne, run)
	const rejected = await asSanne.post(`${BASE}/index.php/apps/openregister/api/flow-tasks/${first.uuid}/complete`, {
		data: { outcome: 'rejected', comment: 'onvoldoende onderbouwd' },
	})
	expect(rejected.status(), await rejected.text()).toBeLessThan(300)
	await asSanne.dispose()

	await expect.poll(async () => (await getObject(page, 'decision', id))?.approvalRouteStatus, { timeout: 60_000 }).toBe('rejected')
	const asWouter = await as(playwright, wouter)
	const open = await asWouter.get(`${BASE}/index.php/apps/openregister/api/flow-tasks`, { params: { runUuid: run, isTerminal: 'false' } })
	expect((await open.json())?.results ?? []).toEqual([])
	await asWouter.dispose()
})

// @e2e approval-routes::a-step-names-the-manager-rule-on-an-openregister-without-it
test('a route whose step asks for the manager rule is refused when saved, naming the step', async ({ page }) => {
	const headers = await writeHeaders(page)
	const refused = await page.request.post(`${BASE}/index.php/apps/openregister/api/objects/decidiq/approval-route`, {
		headers,
		data: {
			name: `${tag} Parafeerroute inkoop`,
			subjectType: 'decision',
			active: true,
			steps: [
				{ order: 1, stageType: 'preparatory', actorType: 'person', actor: sanne },
				{ order: 2, stageType: 'endorsement', actorRule: 'manager-of-owning-organisation', actorRuleSubject: sanne },
			],
		},
	})
	expect(refused.status()).toBeGreaterThanOrEqual(400)
	expect(await refused.text()).toContain('Step 2 uses performer by rule')
})

// @e2e approval-routes::the-committee-sends-the-proposal-back-to-the-officials
test('returning a step to an earlier one opens a new task there and keeps the earlier outcome', async ({ page, playwright }) => {
	const decision = await createObject(page, ledger, 'decision', { title: `${tag} Mobiliteitsplan` })
	const id = decision.id ?? decision['@self']?.id
	const run = (await (await send(page, id)).json()).run

	const asSanne = await as(playwright, sanne)
	const first = await openTaskOf(asSanne, run)
	const advised = await asSanne.post(`${BASE}/index.php/apps/openregister/api/flow-tasks/${first.uuid}/complete`, {
		data: { outcome: 'advised', comment: 'akkoord' },
	})
	expect(advised.status(), await advised.text()).toBeLessThan(300)

	// Step 2 names step 1 on its task's form and sends the proposal back.
	const asWouter = await as(playwright, wouter)
	const second = await openTaskOf(asWouter, run)
	expect(second.metadata.flowNode).toBe('step-2-1')
	const returned = await asWouter.post(`${BASE}/index.php/apps/openregister/api/flow-tasks/${second.uuid}/complete`, {
		data: { outcome: 'returned', comment: 'kostenraming ontbreekt', data: { returnToStep: 1 } },
	})
	expect(returned.status(), await returned.text()).toBeLessThan(300)
	await asWouter.dispose()

	// Step 1 has a new open task; its first task and outcome stay in the run.
	const reopened = await openTaskOf(asSanne, run)
	expect(reopened.metadata.flowNode).toBe('step-1-1')
	expect(reopened.uuid).not.toBe(first.uuid)
	const all = await asSanne.get(`${BASE}/index.php/apps/openregister/api/flow-tasks`, { params: { runUuid: run } })
	const earlier = ((await all.json())?.results ?? []).find((t: Record<string, any>) => t.uuid === first.uuid)
	expect(earlier?.outcome).toBe('advised')
	await asSanne.dispose()

	await expect.poll(async () => (await getObject(page, 'decision', id))?.approvalCurrentOrder, { timeout: 60_000 }).toBe(1)
})

// @e2e approval-routes::lisa-signs-for-wouter
test('a substitute named in an absence can complete the away person\'s step', async ({ page, playwright }) => {
	// Bram records an absence covering today, with Lisa as his substitute.
	const asBram = await as(playwright, bram)
	const today = new Date()
	const until = new Date(today.getTime() + 10 * 24 * 3600 * 1000)
	const away = await asBram.put(`${BASE}/index.php/apps/decidiq/api/notification-preference`, {
		data: { delegate: lisa, delegationFrom: today.toISOString().slice(0, 10), delegationUntil: until.toISOString().slice(0, 10) },
	})
	expect(away.status(), await away.text()).toBeLessThan(300)
	await asBram.dispose()

	const signing = await createObject(page, ledger, 'approval-route', {
		name: `${tag} Ondertekening Bram`,
		subjectType: 'decision',
		active: true,
		steps: [{ order: 1, stageType: 'decisive', actorType: 'person', actor: bram, label: 'Ondertekening' }],
	})
	const decision = await createObject(page, ledger, 'decision', { title: `${tag} Mandaatbesluit` })
	const id = decision.id ?? decision['@self']?.id
	const sent = await page.request.post(`${BASE}/index.php/apps/decidiq/api/approval-routes/instantiate`, {
		headers: await writeHeaders(page),
		data: { route: signing, subject: id, subjectSchema: 'decision' },
	})
	expect(sent.status(), await sent.text()).toBe(201)
	const run = (await sent.json()).run

	// Lisa sees the task, claims it and approves; that closes the step.
	const asLisa = await as(playwright, lisa)
	const task = await openTaskOf(asLisa, run)
	expect(task.candidateUsers).toEqual(expect.arrayContaining([bram, lisa]))
	const claimed = await asLisa.post(`${BASE}/index.php/apps/openregister/api/flow-tasks/${task.uuid}/claim`)
	expect(claimed.status(), await claimed.text()).toBeLessThan(300)
	const approved = await asLisa.post(`${BASE}/index.php/apps/openregister/api/flow-tasks/${task.uuid}/complete`, {
		data: { outcome: 'approved', comment: 'namens Bram' },
	})
	expect(approved.status(), await approved.text()).toBeLessThan(300)
	await asLisa.dispose()

	await expect.poll(async () => (await getObject(page, 'decision', id))?.approvalRouteStatus, { timeout: 60_000 }).toBe('approved')
})
