/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the urgent decision procedure (change
 * urgent-decision-procedure). The meeting's chair declares a decision urgent,
 * a member may not, the urgency fields cannot be written past the flow, the
 * ratification lands on the ratifying body's next regular meeting, the
 * expedited written round keeps to the policy's hour bounds, and the decision
 * page, the decisions list and the dashboard show what is still to ratify.
 *
 * Fixtures (created through the OpenRegister object API as the admin, removed
 * afterwards): a process template copied from the corporate example set with
 * an urgency policy (chair may trigger, 4 to 72 hours, ratified by body R);
 * body B on that template with meeting M (extraordinary, chair1 its chair) and
 * member1; body R with a regular meeting three weeks out; decision D on M with
 * an open decisive stage; decision D2 on M for the direct-write check. chair1
 * and member1 are not Nextcloud admins and sign in with an empty storage state.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
 */
import type { APIRequestContext, Browser, Page, Playwright } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import * as path from 'node:path'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	deleteObject,
	getObject,
	listObjects,
	newLedger,
	OR,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const APP = `${BASE}/index.php/apps/decidiq/api`

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
const users = {
	chair1: `${tag}-chair1`.slice(0, 60),
	member1: `${tag}-member1`.slice(0, 60),
}
const ids: Record<string, string> = {}
const extra: Array<[string, string]> = []

/**
 * The id of an OpenRegister object.
 *
 * @param o The object.
 */
function idOf(o: { id?: string; uuid?: string; '@self'?: { id?: string } }): string {
	return o?.id ?? o?.['@self']?.id ?? o?.uuid ?? ''
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
async function pageAs(browser: Browser, uid: string): Promise<Page> {
	const context = await browser.newContext({
		httpCredentials: { username: uid, password, send: 'always' },
		storageState: { cookies: [], origins: [] },
	})
	return context.newPage()
}

/**
 * The corporate example set's process template that carries an urgency
 * policy, as the fields of a new template (no id, slug or envelope).
 */
function templateFields(): Record<string, unknown> {
	const file = path.resolve(
		__dirname,
		'../../lib/Settings/profiles/corporate.json',
	)
	const found: Array<Record<string, unknown>> = []
	const walk = (node: unknown): void => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node !== null && typeof node === 'object') {
			const row = node as Record<string, unknown>
			if (row.urgencyPolicy && row.stateMachine) {
				found.push(row)
			}
			Object.values(row).forEach(walk)
		}
	}
	walk(JSON.parse(readFileSync(file, 'utf8')))
	expect(
		found.length,
		'the corporate set has a template with an urgency policy',
	).toBeGreaterThan(0)
	const fields = { ...found[0] }
	for (const key of ['@self', 'id', 'uuid', 'slug']) {
		delete fields[key]
	}
	return fields
}

/**
 * A date-time some days from now.
 *
 * @param days Days ahead.
 */
function daysAhead(days: number): string {
	return new Date(Date.now() + days * 86_400_000).toISOString()
}

/**
 * Create an object of a schema the shared ledger does not tear down.
 *
 * @param page The admin page.
 * @param schema The schema slug.
 * @param data The fields.
 */
async function createExtra(
	page: Page,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await page.request.post(`${OR}/${schema}`, {
		headers: await writeHeaders(page),
		data,
	})
	expect(resp.status(), `create ${schema}: ${await resp.text()}`).toBeLessThan(300)
	const id = idOf(await resp.json())
	extra.push([schema, id])
	return id
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

	ids.R = idOf(
		await createObject(page, ledger, 'governance-body', {
			name: `${tag}-raad`,
			bodyType: 'supervisory-board',
			domain: 'corporate',
		}),
	)
	ids.ratifyingMeeting = idOf(
		await createObject(page, ledger, 'meeting', {
			title: `${tag}-regular meeting of R`,
			meetingType: 'regular',
			meetingMode: 'in-person',
			scheduledDate: daysAhead(21),
			lifecycle: 'scheduled',
			governanceBody: ids.R,
		}),
	)
	ids.template = await createExtra(page, 'decision-template', {
		...templateFields(),
		name: `${tag}-template`,
		urgencyPolicy: {
			allowedTriggerRoles: ['chair'],
			minimumNoticeFloorHours: 12,
			responseDeadlineHours: { min: 4, max: 72 },
			ratificationRequired: true,
			ratifyingBody: ids.R,
		},
	})
	ids.B = idOf(
		await createObject(page, ledger, 'governance-body', {
			name: `${tag}-board`,
			bodyType: 'corporate-board',
			domain: 'corporate',
			processTemplate: ids.template,
		}),
	)
	ids.chair = idOf(
		await createObject(page, ledger, 'participant', {
			displayName: users.chair1,
			role: 'chair',
			nextcloudUserId: users.chair1,
			governanceBody: ids.B,
		}),
	)
	await createObject(page, ledger, 'participant', {
		displayName: users.member1,
		role: 'member',
		nextcloudUserId: users.member1,
		governanceBody: ids.B,
	})
	ids.M = idOf(
		await createObject(page, ledger, 'meeting', {
			title: `${tag}-emergency meeting`,
			meetingType: 'extraordinary',
			meetingMode: 'digital',
			scheduledDate: daysAhead(3),
			lifecycle: 'scheduled',
			governanceBody: ids.B,
			chair: ids.chair,
		}),
	)
	for (const key of ['D', 'D2']) {
		ids[key] = idOf(
			await createObject(page, ledger, 'decision', {
				decisionType: 'resolution',
				title: `${tag}-${key} report the data breach today`,
				text: 'The board reports the breach to the regulator within the legal term.',
				lifecycle: 'deliberating',
				meeting: ids.M,
			}),
		)
	}
	ids.decisive = await createExtra(page, 'decision-stage', {
		decision: ids.D,
		sequence: 1,
		stageType: 'decisive',
		status: 'pending',
		decisionMakerType: 'body',
		assignedBody: ids.B,
		label: 'Board decides',
	})
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	// Stages the procedure appended, then the fixtures, children first.
	for (const stage of (await listObjects(page, 'decision-stage', 500)).filter(
		(s: Record<string, unknown>) =>
			s.decision === ids.D || s.decision === ids.D2,
	)) {
		await deleteObject(page, 'decision-stage', idOf(stage)).catch(
			() => undefined,
		)
	}
	for (const [schema, id] of extra.reverse()) {
		await deleteObject(page, schema, id).catch(() => undefined)
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

// @e2e urgent-decision-procedure::unauthorised-actor-is-rejected
test('a member who is not the chair may not declare the decision urgent', async ({
	playwright,
	browser,
}) => {
	const ctx = await as(playwright, users.member1)
	const resp = await ctx.post(`${APP}/decisions/${ids.D}/urgency`, {
		data: { reason: 'It cannot wait.' },
	})
	expect([403, 404]).toContain(resp.status())
	await ctx.dispose()

	const page = await browser.newPage()
	const decision = await getObject(page, 'decision', ids.D)
	expect(decision?.isUrgent ?? false).toBe(false)
	await page.close()
})

// @e2e urgent-decision-procedure::declaration-without-justification-is-rejected
test('the chair must give a reason', async ({ playwright }) => {
	const ctx = await as(playwright, users.chair1)
	const resp = await ctx.post(`${APP}/decisions/${ids.D}/urgency`, {
		data: { reason: '' },
	})
	expect(resp.status()).toBe(422)
	await ctx.dispose()
})

// @e2e urgent-decision-procedure::chair-declares-a-college-spoedbesluit
// @e2e urgent-decision-procedure::ratifying-stage-appended-to-a-college-spoedbesluit
// @e2e urgent-decision-procedure::ratification-placed-on-the-next-regular-meeting
test('the chair declares the decision urgent and its ratification is placed on the ratifying body', async ({
	playwright,
	browser,
}) => {
	const ctx = await as(playwright, users.chair1)
	const resp = await ctx.post(`${APP}/decisions/${ids.D}/urgency`, {
		data: { reason: 'The regulator must be told within 72 hours.' },
	})
	expect(resp.status(), await resp.text()).toBe(200)

	const again = await ctx.post(`${APP}/decisions/${ids.D}/urgency`, {
		data: { reason: 'Twice.' },
	})
	expect(again.status(), 'a decision is declared urgent once').toBe(409)
	await ctx.dispose()

	const page = await browser.newPage()
	const decision = await getObject(page, 'decision', ids.D)
	expect(decision.isUrgent).toBe(true)
	expect(decision.urgencyReason).toBe(
		'The regulator must be told within 72 hours.',
	)
	expect(decision.urgencyDeclaredBy).toBe(users.chair1)
	expect(decision.lifecycle, 'the lifecycle does not move').toBe('deliberating')

	const stages = (await listObjects(page, 'decision-stage', 500)).filter(
		(s: Record<string, unknown>) => s.decision === ids.D,
	)
	const ratifying = stages.find(
		(s: Record<string, unknown>) => s.stageType === 'ratifying',
	)
	expect(ratifying, 'a ratifying stage is appended').toBeTruthy()
	expect(ratifying.assignedBody).toBe(ids.R)
	expect(ratifying.sequence).toBe(2)

	const items = (await listObjects(page, 'agenda-item', 500)).filter(
		(i: Record<string, unknown>) => i.ratifiesDecision === ids.D,
	)
	expect(items.length, 'one agenda item ratifies the decision').toBe(1)
	expect(items[0].meeting).toBe(ids.ratifyingMeeting)
	extra.push(['agenda-item', idOf(items[0])])
	await page.close()
})

// REQ-001: the urgency fields are written only by the declaration (direct write refused, PUT carry-forward)
test('the urgency fields cannot be written past the procedure', async ({
	browser,
}) => {
	const page = await browser.newPage()
	const headers = await writeHeaders(page)
	const plain = await getObject(page, 'decision', ids.D2)
	const resp = await page.request.put(`${OR}/decision/${ids.D2}`, {
		headers,
		data: { ...plain, isUrgent: true, urgencyReason: 'Set by hand' },
	})
	expect(
		resp.status(),
		'a direct urgency write is refused',
	).toBeGreaterThanOrEqual(400)
	const decision = await getObject(page, 'decision', ids.D2)
	expect(decision?.isUrgent ?? false).toBe(false)

	// An edit of the urgent decision that leaves the urgency fields out keeps them.
	const stored = await getObject(page, 'decision', ids.D)
	const {
		isUrgent,
		urgencyReason,
		urgencyDeclaredBy,
		urgencyDeclaredAt,
		...rest
	} = stored
	expect(
		isUrgent && urgencyReason && urgencyDeclaredBy && urgencyDeclaredAt,
	).toBeTruthy()
	const edit = await page.request.put(`${OR}/decision/${ids.D}`, {
		headers,
		data: { ...rest, title: `${tag}-D report the data breach today (edited)` },
	})
	expect(edit.status(), await edit.text()).toBeLessThan(300)
	const urgent = await getObject(page, 'decision', ids.D)
	expect(urgent.isUrgent).toBe(true)
	expect(urgent.urgencyReason).toBe('The regulator must be told within 72 hours.')
	await page.close()
})

// @e2e urgent-decision-procedure::rvb-opens-an-expedited-written-round
// @e2e urgent-decision-procedure::deadline-outside-the-configured-bounds-is-rejected
test('the expedited written round keeps to the policy hours', async ({
	playwright,
}) => {
	const ctx = await as(playwright, users.chair1)
	const tooShort = await ctx.post(`${APP}/decisions/${ids.D}/expedited-round`, {
		data: { responseDeadlineHours: 1 },
	})
	expect(tooShort.status()).toBe(422)
	expect(await tooShort.text()).toContain('72')

	const opened = Date.now()
	const resp = await ctx.post(`${APP}/decisions/${ids.D}/expedited-round`, {
		data: { responseDeadlineHours: 24 },
	})
	expect(resp.status(), await resp.text()).toBe(201)
	const round = await resp.json()
	const deadline = Date.parse(round.votingDeadline ?? round.round?.votingDeadline)
	expect(Math.abs(deadline - (opened + 24 * 3_600_000))).toBeLessThan(10 * 60_000)
	await ctx.dispose()
})

// @e2e urgent-decision-procedure::detail-banner-while-ratification-is-pending
test('the decision page names the body that still has to ratify', async ({
	browser,
}) => {
	const page = await pageAs(browser, users.chair1)
	await page.goto(`${BASE}/index.php/apps/decidiq/decisions/${ids.D}`)
	const banner = page.getByTestId('urgency-banner')
	await expect(banner).toBeVisible({ timeout: 20_000 })
	await expect(banner).toContainText(`${tag}-raad`)
	await expect(banner).toContainText('The regulator must be told within 72 hours.')
	await expect(page.getByTestId('urgency-placement')).toBeVisible()
	await page.context().close()
})

// @e2e urgent-decision-procedure::chair-declares-a-college-spoedbesluit
test('the chair declares a decision urgent from its page', async ({ browser }) => {
	const page = await pageAs(browser, users.chair1)
	await page.goto(`${BASE}/index.php/apps/decidiq/decisions/${ids.D2}`)
	await page.getByTestId('urgency-declare').click({ timeout: 20_000 })
	const dialog = page.getByTestId('declare-urgency-dialog')
	await expect(dialog).toBeVisible()
	await dialog
		.getByTestId('declare-urgency-reason')
		.locator('input, textarea')
		.first()
		.fill('The board cannot wait for its next meeting.')
	await dialog.getByTestId('declare-urgency-confirm').click()
	await expect(page.getByTestId('urgency-banner')).toContainText(`${tag}-raad`, {
		timeout: 20_000,
	})
	await page.context().close()
})

// @e2e urgent-decision-procedure::dashboard-kpi-counts-and-navigates
test('the urgent decisions list shows what is still to ratify', async ({
	browser,
}) => {
	const page = await pageAs(browser, users.chair1)
	await page.goto(
		`${BASE}/index.php/apps/decidiq/urgent-decisions?isUrgent=true&awaitingRatification=true`,
	)
	await expect(
		page.getByText(`${tag}-D report the data breach today`).first(),
	).toBeVisible({ timeout: 20_000 })
	await page.context().close()
})

// @e2e urgent-decision-procedure::dashboard-kpi-counts-and-navigates
test('the dashboard tile opens the decisions still to ratify', async ({
	browser,
}) => {
	const page = await pageAs(browser, users.chair1)
	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	const tile = page.getByText('To ratify', { exact: true }).first()
	await expect(tile).toBeVisible({ timeout: 20_000 })
	await tile.click()
	await expect(page).toHaveURL(/urgent-decisions/)
	await expect(page).toHaveURL(/awaitingRatification=true/)
	await page.context().close()
})
