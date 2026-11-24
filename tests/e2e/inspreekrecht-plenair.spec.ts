/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: speaking rights at meetings (change inspreekrecht-plenair,
 * capabilities inspraak-register and inspraak-agenda-live). The loop end to
 * end: register (the governed decidiq endpoint the portal will call) →
 * moderate on the Speaking requests overview (approve with speaking time,
 * reject with a reason, refer to another meeting) → the agenda item shows the
 * speaking slots → the live meeting preloads the speaker queue → the chair
 * marks spoke / no-show → the outcome shows on the agenda again → the
 * secretariat attaches the written contribution. Plus the meeting-level path
 * (a body whose policy level is `meeting`, the ALV case) and the deadline
 * refusal with the secretariat's override.
 *
 * Covered elsewhere, and so not driven here:
 * - the policy rules, deadline maths, override, approval defaults, referral
 *   and the PUT-semantic outcome write-back:
 *   tests/Unit/Service/SpeakingRequestServiceTest.php;
 * - the public allow-list projection in all three display modes:
 *   tests/Unit/Service/SpeakingSlotsTest.php;
 * - the staff guard on override, moderation, agenda, queue and outcome:
 *   tests/Unit/Controller/SpeakingRequestControllerTest.php;
 * - fragment 64 (schemas declared once with titles, the lifecycle map, the
 *   citizen fields freezing at approval, contact details for the secretariat
 *   only, the nl/en notification declarations, the seeds):
 *   tests/Unit/Settings/SpeakingRequestRegisterTest.php;
 * - slot grouping, queue entries, preload merging, the deadline state and the
 *   API client: tests/vitest/speakingRequests.spec.js.
 *
 * Example set: none is required — every test seeds its own governance bodies,
 * speaking policies, meetings and agenda items through OpenRegister, tagged
 * with the run id, and registers through POST /api/speaking-requests. The
 * municipality set (speaking-policy-gemeenteraad-amsterdam and three
 * speaking-request seeds) and the association set (the meeting-level
 * speaking-policy-ledenraad-vng) may be loaded alongside; the tests filter on
 * their own meetings, so the seeds only add rows to the overview. Runs as the
 * administrator, who passes the meeting staff guard for override, moderation
 * and outcome, and who runs the live meeting as chair.
 *
 * Gaps found while writing this, left as they are (see the excludes below):
 * - the overview sorts by creation (`sortKey: @self.created`), not by meeting
 *   date, so "the ALV registration first" in pending-approvals-across-meetings
 *   is not asserted; the deadline warnings are;
 * - the queue does not display an entry's time limit, so the preloaded limits
 *   are read from the queue endpoint the panel preloads from.
 *
 * Written, not yet run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md
 */
import type { APIResponse, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	deleteObject,
	getObject,
	newLedger,
	objId,
	OR,
	writeHeaders,
} from './workflows/governance-fixture.ts'

// @e2e exclude inspraak-register::register-fragment-is-additive — register-config contract, tests/Unit/Settings/SpeakingRequestRegisterTest.php
// @e2e exclude inspraak-register::single-canonical-definition-no-duplicate-in-the-commissie-register — cross-change register contract, tests/Unit/Settings/SpeakingRequestRegisterTest.php::testBothSchemasAreDeclaredOnceWithTitles
// @e2e exclude inspraak-register::citizen-field-groups-frozen-after-approval — authorization contract, tests/Unit/Settings/SpeakingRequestRegisterTest.php::testTheCitizensFieldsFreezeAtApproval
// @e2e exclude inspraak-register::invalid-transition-rejected-declaratively — lifecycle-map contract, tests/Unit/Settings/SpeakingRequestRegisterTest.php::testTheLifecycleIsTheSpeccedMap
// @e2e exclude inspraak-agenda-live::first-name-only-projection — payload contract, tests/Unit/Service/SpeakingSlotsTest.php::testThePublicProjectionIsAnAllowList
// @e2e exclude inspraak-agenda-live::declarative-notification-on-registration — notification-dialect contract, tests/Unit/Settings/SpeakingRequestRegisterTest.php::testNotificationsAreDeclaredInBothLanguages

test.describe.configure({ mode: 'serial' })

const APP = `${BASE}/index.php/apps/decidiq`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`

/** Speaking policies and requests: absent from cleanupAll's teardown order, so deleted here first. */
const requestIds: string[] = []
const policyIds: string[] = []

let raadBody = ''
let alvBody = ''
let closedBody = ''

const HOUR = 3_600_000

/**
 * An ISO date-time some hours from now.
 *
 * @param hours Hours from now; negative is in the past.
 */
function hoursFromNow(hours: number): string {
	return new Date(Date.now() + hours * HOUR).toISOString()
}

async function seedBody(
	page: Page,
	name: string,
	policy: Record<string, unknown> | null,
): Promise<string> {
	const body = objId(
		await createObject(page, ledger, 'governance-body', {
			name: `${tag}-${name}`,
			bodyType: 'legislative',
			domain: `${tag} ${name}`,
		}),
	)
	if (policy) {
		const created = await createObject(page, ledger, 'speaking-policy', {
			governanceBody: body,
			...policy,
		})
		policyIds.push(objId(created))
	}
	return body
}

async function seedMeeting(
	page: Page,
	body: string,
	name: string,
	startsInHours: number,
	lifecycle = 'scheduled',
): Promise<{ id: string; title: string }> {
	const title = `${tag}-${name}`
	const meeting = await createObject(page, ledger, 'meeting', {
		title,
		meetingType: 'regular',
		meetingMode: 'in-person',
		lifecycle,
		scheduledDate: hoursFromNow(startsInHours),
		governanceBody: body,
	})
	return { id: objId(meeting), title }
}

async function seedItem(
	page: Page,
	meetingId: string,
	name: string,
	orderNumber = 1,
): Promise<{ id: string; title: string }> {
	const title = `${tag}-${name}`
	const item = await createObject(page, ledger, 'agenda-item', {
		title,
		meeting: meetingId,
		orderNumber,
		itemType: 'discussion',
	})
	return { id: objId(item), title }
}

async function post(
	page: Page,
	path: string,
	data: Record<string, unknown>,
): Promise<APIResponse> {
	return page.request.post(`${APP}/api/${path}`, {
		headers: await writeHeaders(page),
		data,
	})
}

/**
 * Register to speak through the governed endpoint; tracks what it creates.
 *
 * @param page The page whose session posts.
 * @param input meeting, agendaItem, topic, contactDetails, override.
 */
async function register(
	page: Page,
	input: Record<string, unknown>,
): Promise<APIResponse> {
	const resp = await post(page, 'speaking-requests', {
		contactDetails: {
			name: 'Marieke Jansen',
			email: 'm.jansen@example.org',
			phone: '06-12345678',
			address: 'Dorpsstraat 12, 1011 AB Voorbeeldingen',
		},
		...input,
	})
	if (resp.status() < 300) {
		const id = objId(await resp.json())
		if (id) requestIds.push(id)
	}
	return resp
}

/** Register and return the new request's id; fails the test on a refusal. */
async function registered(
	page: Page,
	input: Record<string, unknown>,
): Promise<string> {
	const resp = await register(page, input)
	expect(resp.status(), await resp.text()).toBe(201)
	return objId(await resp.json())
}

async function approve(
	page: Page,
	id: string,
	allottedMinutes: number,
	position: number,
): Promise<void> {
	const resp = await post(page, `speaking-requests/${id}/approve`, {
		allottedMinutes,
		position,
	})
	expect(resp.status(), await resp.text()).toBe(200)
}

function topic(speakerName: string, topicText: string): Record<string, unknown> {
	return { speakerName, topicText, requestedMinutes: 5 }
}

async function requestsOf(page: Page, meetingId: string): Promise<any[]> {
	const resp = await page.request.get(`${OR}/speaking-request`, {
		params: { meeting: meetingId, _limit: 50 },
		headers: { Accept: 'application/json' },
	})
	expect(resp.ok()).toBe(true)
	const body = await resp.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	return rows.filter((row: any) => row.meeting === meetingId)
}

/**
 * The overview row of a request on a meeting. The Meeting column holds the
 * reference; it shows the meeting's title once resolved, its id otherwise.
 */
function overviewRow(page: Page, meeting: { id: string; title: string }) {
	return page
		.getByTestId('cn-object-row')
		.filter({ hasText: new RegExp(`${meeting.id}|${meeting.title}`) })
		.first()
}

async function rowAction(
	page: Page,
	meeting: { id: string; title: string },
	label: string,
): Promise<void> {
	const row = overviewRow(page, meeting)
	await expect(row).toBeVisible()
	await row.getByTestId('cn-row-actions').getByRole('button').first().click()
	await page.getByRole('menuitem', { name: label }).click()
}

/**
 * An opened meeting with one agenda item and two approved speakers:
 * Mw. Jansen (5 min, first) and Dhr. De Boer (3 min, second).
 */
async function seedLiveItem(
	page: Page,
	name: string,
): Promise<{
	meeting: { id: string; title: string }
	item: { id: string; title: string }
	jansen: string
	deBoer: string
}> {
	const meeting = await seedMeeting(page, raadBody, name, 7 * 24, 'opened')
	const item = await seedItem(page, meeting.id, `${name}-dorpsplein`)
	const jansen = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Mw. Jansen', 'Herinrichting dorpsplein'),
	})
	const deBoer = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Dhr. De Boer', 'Voorgenomen kap 12 eiken'),
	})
	await approve(page, jansen, 5, 1)
	await approve(page, deBoer, 3, 2)
	return { meeting, item, jansen, deBoer }
}

async function openLiveItem(
	page: Page,
	meetingId: string,
	itemTitle: string,
): Promise<void> {
	await page.goto(`${APP}/meetings/${meetingId}/live`)
	await expect(page.getByTestId('meeting-live')).toBeVisible({ timeout: 15_000 })
	await page
		.getByTestId('meeting-live')
		.getByRole('button', { name: `Activate ${itemTitle}` })
		.click()
	await expect(page.getByTestId('speaker-queue-panel')).toBeVisible()
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	raadBody = await seedBody(page, 'gemeenteraad', {
		enabled: true,
		registrationDeadlineHours: 24,
		defaultSpeakingMinutes: 5,
		level: 'agenda-item',
		publicDisplay: 'count',
	})
	alvBody = await seedBody(page, 'vve-alv', {
		enabled: true,
		registrationDeadlineHours: 48,
		defaultSpeakingMinutes: 3,
		level: 'meeting',
		publicDisplay: 'count',
	})
	closedBody = await seedBody(page, 'zonder-beleid', null)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const id of requestIds) await deleteObject(page, 'speaking-request', id)
	for (const id of policyIds) await deleteObject(page, 'speaking-policy', id)
	await cleanupAll(page, ledger)
	await page.close()
})

// ── Registration (REQ-INS-001, 003, 004) ─────────────────────────────────────

// @e2e inspraak-register::citizen-registers-to-speak-on-a-plenary-agenda-item
test('a registration on a plenary agenda item is stored as registered and waits on the overview', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-registratie', 7 * 24)
	const item = await seedItem(page, meeting.id, 'omgevingsvisie')

	const resp = await register(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Mw. Jansen', 'Herinrichting dorpsplein'),
	})
	expect(resp.status(), await resp.text()).toBe(201)
	const stored = await resp.json()
	expect(stored.lifecycle).toBe('registered')
	expect(stored.governanceBody).toBe(raadBody)

	// Missing speaker name or topic, or an unknown meeting, is refused.
	const noName = await register(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: { topicText: 'Herinrichting dorpsplein' },
	})
	expect(noName.status()).toBe(400)
	const noTopic = await register(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: { speakerName: 'Mw. Jansen' },
	})
	expect(noTopic.status()).toBe(400)
	const noMeeting = await register(page, {
		agendaItem: item.id,
		topic: topic('Mw. Jansen', 'Herinrichting dorpsplein'),
	})
	expect(noMeeting.status()).toBeGreaterThanOrEqual(400)
	expect(await requestsOf(page, meeting.id)).toHaveLength(1)

	await page.goto(`${APP}/speaking-requests`)
	await expect(overviewRow(page, meeting)).toBeVisible()
})

// @e2e inspraak-register::body-without-a-policy-object-refuses-registration
test('a body without a speaking policy refuses the registration and stores nothing', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, closedBody, 'zonder-beleid', 7 * 24)
	const item = await seedItem(page, meeting.id, 'zonder-beleid-punt')

	const resp = await register(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('A. Visser', 'Verkeersbesluit centrum'),
	})
	expect(resp.status()).toBe(409)
	expect((await resp.json()).message).toContain(
		'does not offer the public a chance to speak',
	)
	expect(await requestsOf(page, meeting.id)).toHaveLength(0)
})

// @e2e inspraak-register::alv-enables-meeting-level-inspraak
test('at a body with meeting-level speaking a member registers without an agenda item', async ({
	page,
}) => {
	const alv = await seedMeeting(page, alvBody, 'alv-voorjaar', 7 * 24)
	const item = await seedItem(page, alv.id, 'servicekosten')

	const id = await registered(page, {
		meeting: alv.id,
		topic: topic('Mw. Pietersen', 'Verhoging servicekosten'),
	})
	const stored = await getObject(page, 'speaking-request', id)
	expect(stored?.meeting).toBe(alv.id)
	expect(stored?.agendaItem || null).toBeNull()

	// Naming an agenda item at a meeting-level body is refused.
	const withItem = await register(page, {
		meeting: alv.id,
		agendaItem: item.id,
		topic: topic('Dhr. Bakker', 'Onderhoud dak'),
	})
	expect(withItem.status()).toBe(400)
})

// @e2e inspraak-register::late-registration-refused
test('a registration after the deadline is refused with the deadline named', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-over-drie-uur', 3)
	const item = await seedItem(page, meeting.id, 'laat-punt')

	const resp = await register(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('A. Visser', 'Verkeersbesluit centrum'),
	})
	expect(resp.status()).toBe(409)
	expect((await resp.json()).message).toContain('closed 24 hours before it starts')
	expect(await requestsOf(page, meeting.id)).toHaveLength(0)
})

// @e2e inspraak-register::griffier-override-after-the-deadline
test('the secretariat registers after the deadline with the override, and it is recorded', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-override', 3)
	const item = await seedItem(page, meeting.id, 'override-punt')

	const id = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('A. Visser', 'Verkeersbesluit centrum'),
		override: true,
	})
	const stored = await getObject(page, 'speaking-request', id)
	expect(stored?.registeredAfterDeadline).toBe(true)

	const trail = await page.request.get(
		`${OR}/speaking-request/${id}/audit-trails`,
		{
			headers: { Accept: 'application/json' },
		},
	)
	expect(trail.ok()).toBe(true)
	const entries = await trail.json()
	expect(
		(Array.isArray(entries) ? entries : (entries.results ?? [])).length,
	).toBeGreaterThan(0)

	await page.goto(`${APP}/speaking-requests/${id}`)
	await expect(
		page.getByText('Registered after the deadline').first(),
	).toBeVisible()
})

// ── Moderation (REQ-INS-002, 005) ────────────────────────────────────────────

// @e2e inspraak-register::griffier-approves-an-aanmelding
test('the secretariat approves a pending request with five minutes from the overview', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-goedkeuren', 7 * 24)
	const item = await seedItem(page, meeting.id, 'goedkeuren-punt')
	const id = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Mw. Jansen', 'Herinrichting dorpsplein'),
	})

	await page.goto(`${APP}/speaking-requests`)
	await rowAction(page, meeting, 'Approve')
	const dialog = page.getByTestId('speaking-request-approve-dialog')
	await expect(dialog).toBeVisible()
	await expect(dialog.getByText('Speaker: Mw. Jansen')).toBeVisible()
	await dialog
		.getByTestId('speaking-request-approve-minutes')
		.locator('input')
		.fill('5')
	await dialog
		.getByTestId('speaking-request-approve-position')
		.locator('input')
		.fill('1')
	await dialog.getByTestId('speaking-request-approve-confirm').click()
	await expect(dialog).toBeHidden()

	const stored = await getObject(page, 'speaking-request', id)
	expect(stored?.lifecycle).toBe('approved')
	expect(stored?.allottedMinutes).toBe(5)
	expect(stored?.position).toBe(1)

	await page.goto(`${APP}/agenda-items/${item.id}`)
	const approved = page
		.getByTestId('agenda-item-speakers')
		.getByTestId('speaking-slots-approved')
	await expect(approved).toContainText('Mw. Jansen')
	await expect(approved).toContainText('5 min')
})

// @e2e inspraak-register::rejection-requires-a-reason
test('the secretariat rejects only with a reason, and the reason is stored', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-afwijzen', 7 * 24)
	const item = await seedItem(page, meeting.id, 'afwijzen-punt')
	const id = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Mw. Pietersen', 'Verhoging servicekosten'),
	})

	// The server refuses a rejection without a reason, whatever the dialog does.
	const bare = await post(page, `speaking-requests/${id}/reject`, {
		rejectionReason: '',
	})
	expect(bare.status()).toBe(400)

	await page.goto(`${APP}/speaking-requests`)
	await rowAction(page, meeting, 'Reject')
	const dialog = page.getByTestId('speaking-request-reject-dialog')
	await expect(dialog).toBeVisible()
	const confirm = dialog.getByTestId('speaking-request-reject-confirm')
	await expect(confirm).toBeDisabled()
	const reason = 'Onderwerp staat niet op de agenda van deze vergadering'
	await dialog
		.getByTestId('speaking-request-reject-reason')
		.locator('textarea')
		.fill(reason)
	await expect(confirm).toBeEnabled()
	await confirm.click()
	await expect(dialog).toBeHidden()

	const stored = await getObject(page, 'speaking-request', id)
	expect(stored?.lifecycle).toBe('rejected')
	expect(stored?.rejectionReason).toBe(reason)

	// Rejected requests stay off the agenda.
	await page.goto(`${APP}/agenda-items/${item.id}`)
	const block = page.getByTestId('agenda-item-speakers')
	await expect(
		block.getByText('No public speakers have registered.'),
	).toBeVisible()
})

// @e2e inspraak-register::referral-to-a-commissievergadering
test('the secretariat refers a request to a committee meeting item and it stays pending', async ({
	page,
}) => {
	const raad = await seedMeeting(page, raadBody, 'raad-verwijzen', 14 * 24)
	const raadItem = await seedItem(page, raad.id, 'verwijzen-raadspunt')
	const commissie = await seedMeeting(page, raadBody, 'commissie-ruimte', 7 * 24)
	const commissieItem = await seedItem(page, commissie.id, 'kap-eikenlaan')
	const id = await registered(page, {
		meeting: raad.id,
		agendaItem: raadItem.id,
		topic: topic('Dhr. De Boer', 'Voorgenomen kap 12 eiken'),
	})

	await page.goto(`${APP}/speaking-requests`)
	await rowAction(page, raad, 'Refer')
	const dialog = page.getByTestId('speaking-request-refer-dialog')
	await expect(dialog).toBeVisible()
	await dialog.getByRole('combobox', { name: 'Meeting' }).click()
	await page.getByRole('option', { name: new RegExp(commissie.title) }).click()
	await dialog
		.getByRole('combobox', {
			name: 'Agenda item (empty: the meeting as a whole)',
		})
		.click()
	await page.getByRole('option', { name: new RegExp(commissieItem.title) }).click()
	await dialog.getByTestId('speaking-request-refer-confirm').click()
	await expect(dialog).toBeHidden()

	const stored = await getObject(page, 'speaking-request', id)
	expect(stored?.meeting).toBe(commissie.id)
	expect(stored?.agendaItem).toBe(commissieItem.id)
	expect(stored?.lifecycle).toBe('registered')

	// It now waits as pending on the committee item, not on the plenary one.
	await page.goto(`${APP}/agenda-items/${commissieItem.id}`)
	await expect(
		page
			.getByTestId('agenda-item-speakers')
			.getByTestId('speaking-slots-pending'),
	).toContainText('Dhr. De Boer')
})

// ── The agenda (REQ-INS-007, 008) ────────────────────────────────────────────

// @e2e inspraak-agenda-live::chair-sees-speaking-slots-on-an-agenda-item
test('the agenda item shows two approved slots in order and the pending request apart', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-slots', 7 * 24)
	const item = await seedItem(page, meeting.id, 'slots-punt')
	const second = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Dhr. De Boer', 'Voorgenomen kap 12 eiken'),
	})
	const first = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Mw. Jansen', 'Herinrichting dorpsplein'),
	})
	await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('A. Visser', 'Verkeersbesluit centrum'),
	})
	// Approved in reverse, so the order shown is the position, not creation.
	await approve(page, second, 4, 2)
	await approve(page, first, 5, 1)

	await page.goto(`${APP}/agenda-items/${item.id}`)
	const block = page.getByTestId('agenda-item-speakers')
	const slots = block.getByTestId('speaking-slots-approved').locator('li')
	await expect(slots).toHaveCount(2)
	await expect(slots.nth(0)).toContainText('Mw. Jansen')
	await expect(slots.nth(0)).toContainText('5 min')
	await expect(slots.nth(1)).toContainText('Dhr. De Boer')
	await expect(slots.nth(1)).toContainText('4 min')

	const pending = block.getByTestId('speaking-slots-pending')
	await expect(pending).toContainText('A. Visser')
	await expect(pending).toContainText('Pending approval')
	await expect(block.getByTestId('speaking-slots-approved')).not.toContainText(
		'A. Visser',
	)
})

// @e2e inspraak-agenda-live::meeting-level-insprekers-block
test('a meeting-level speaker shows in the meeting block and on no agenda item', async ({
	page,
}) => {
	const alv = await seedMeeting(page, alvBody, 'alv-najaar', 7 * 24)
	const item = await seedItem(page, alv.id, 'begroting')
	const id = await registered(page, {
		meeting: alv.id,
		topic: topic('Mw. Pietersen', 'Verhoging servicekosten'),
	})
	await approve(page, id, 3, 1)

	await page.goto(`${APP}/meetings/${alv.id}`)
	const block = page.getByTestId('meeting-speakers')
	await expect(block.getByTestId('speaking-slots-approved')).toContainText(
		'Mw. Pietersen',
	)
	await expect(block.getByTestId('speaking-slots-approved')).toContainText('3 min')

	await page.goto(`${APP}/agenda-items/${item.id}`)
	const itemBlock = page.getByTestId('agenda-item-speakers')
	await expect(
		itemBlock.getByText('No public speakers have registered.'),
	).toBeVisible()
	await expect(itemBlock).not.toContainText('Mw. Pietersen')
})

// The public agenda payload has no decidiq page of its own (the portal renders
// it), so this drives the deployed endpoint the portal reads.
// @e2e inspraak-agenda-live::count-only-public-projection
test('under count display the public agenda carries only the number of speakers', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-publiek', 7 * 24)
	const item = await seedItem(page, meeting.id, 'publiek-punt')
	const names = ['Mw. Jansen', 'Dhr. De Boer', 'A. Visser']
	for (const [i, name] of names.entries()) {
		const id = await registered(page, {
			meeting: meeting.id,
			agendaItem: item.id,
			topic: {
				...topic(name, `Onderwerp ${i + 1}`),
				organisation: 'Bewonerscomité Eikenlaan',
			},
		})
		await approve(page, id, 3, i + 1)
	}

	const resp = await page.request.get(
		`${APP}/api/meetings/${meeting.id}/speaking-slots/public`,
		{
			headers: { Accept: 'application/json' },
		},
	)
	expect(resp.ok()).toBe(true)
	const payload = await resp.json()
	expect(payload.display).toBe('count')
	expect(payload.agenda[item.id]).toEqual({ count: 3 })

	const raw = JSON.stringify(payload)
	for (const name of names) expect(raw).not.toContain(name)
	expect(raw).not.toContain('Bewonerscomité')
	expect(raw).not.toContain('Onderwerp')
	expect(raw).not.toContain('example.org')
	expect(raw).not.toContain('contactDetails')
})

// ── The live meeting (REQ-INS-009) ───────────────────────────────────────────

// @e2e inspraak-agenda-live::queue-preloaded-when-the-item-opens
test('the speaker queue starts with the approved speakers in order, labelled public speaker', async ({
	page,
}) => {
	const { meeting, item } = await seedLiveItem(page, 'live-preload')

	await openLiveItem(page, meeting.id, item.title)
	const entries = page.getByTestId('speaker-queue-list').locator('li')
	await expect(entries).toHaveCount(2)
	await expect(entries.nth(0)).toContainText('Mw. Jansen')
	await expect(
		entries.nth(0).getByTestId('speaker-queue-public-speaker'),
	).toHaveText('Public speaker')
	await expect(entries.nth(1)).toContainText('Dhr. De Boer')
	await expect(
		entries.nth(1).getByTestId('speaker-queue-public-speaker'),
	).toHaveText('Public speaker')

	// The panel shows no limit per entry; the limits it preloads come from here.
	const queue = await page.request.get(
		`${APP}/api/meetings/${meeting.id}/speaking-queue`,
		{
			params: { agendaItem: item.id },
			headers: { Accept: 'application/json' },
		},
	)
	expect(queue.ok()).toBe(true)
	const rows = await queue.json()
	expect(rows.map((r: any) => [r.name, r.minutes])).toEqual([
		['Mw. Jansen', 5],
		['Dhr. De Boer', 3],
	])
})

// @e2e inspraak-agenda-live::marking-gesproken-flows-back
test('marking a public speaker as spoken moves the request to spoke and keeps its other fields', async ({
	page,
}) => {
	const { meeting, item, jansen } = await seedLiveItem(page, 'live-spoke')
	const contribution = 'Namens de buurt vraag ik aandacht voor het dorpsplein.'
	const patched = await page.request.patch(`${OR}/speaking-request/${jansen}`, {
		headers: await writeHeaders(page),
		data: { contribution },
	})
	expect(patched.status(), await patched.text()).toBeLessThan(300)

	await openLiveItem(page, meeting.id, item.title)
	await page.getByRole('button', { name: 'Mark Mw. Jansen as spoken' }).click()
	const entries = page.getByTestId('speaker-queue-list').locator('li')
	await expect(entries).toHaveCount(1)
	await expect(entries.nth(0)).toContainText('Dhr. De Boer')

	await expect
		.poll(
			async () =>
				(await getObject(page, 'speaking-request', jansen))?.lifecycle,
		)
		.toBe('spoke')
	const stored = await getObject(page, 'speaking-request', jansen)
	expect(stored?.contribution).toBe(contribution)
	expect(stored?.allottedMinutes).toBe(5)
	expect(stored?.topic?.speakerName).toBe('Mw. Jansen')

	// The outcome flows back onto the agenda slot.
	await page.goto(`${APP}/agenda-items/${item.id}`)
	const first = page
		.getByTestId('agenda-item-speakers')
		.getByTestId('speaking-slots-approved')
		.locator('li')
		.filter({ hasText: 'Mw. Jansen' })
	await expect(first).toContainText('Spoke')
})

// @e2e inspraak-agenda-live::no-show-recorded
test('marking a public speaker as no-show records it and the queue moves to the next', async ({
	page,
}) => {
	const { meeting, item, jansen } = await seedLiveItem(page, 'live-no-show')

	await openLiveItem(page, meeting.id, item.title)
	await page.getByRole('button', { name: 'Mark Mw. Jansen as no-show' }).click()
	const entries = page.getByTestId('speaker-queue-list').locator('li')
	await expect(entries).toHaveCount(1)
	await expect(entries.nth(0)).toContainText('Dhr. De Boer')
	await expect(page.getByTestId('speaker-queue-outcome-error')).toHaveCount(0)

	await expect
		.poll(
			async () =>
				(await getObject(page, 'speaking-request', jansen))?.lifecycle,
		)
		.toBe('no-show')

	await page.goto(`${APP}/agenda-items/${item.id}`)
	await expect(
		page
			.getByTestId('agenda-item-speakers')
			.getByTestId('speaking-slots-approved'),
	).toContainText('Did not show up')
})

// ── After the meeting (REQ-INS-006) ──────────────────────────────────────────

// @e2e inspraak-register::written-contribution-attached-after-the-meeting
test('the secretariat attaches the written contribution and the agenda item shows it', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-bijdrage', 7 * 24)
	const item = await seedItem(page, meeting.id, 'bijdrage-punt')
	const id = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Mw. Jansen', 'Herinrichting dorpsplein'),
	})
	await approve(page, id, 5, 1)
	const spoke = await post(page, `speaking-requests/${id}/outcome`, {
		outcome: 'spoke',
	})
	expect(spoke.status(), await spoke.text()).toBe(200)

	const contribution = 'Houd het dorpsplein autovrij op marktdagen.'
	await page.goto(`${APP}/speaking-requests/${id}`)
	await page.getByTestId('cn-detail-page-edit').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()
	await dialog.getByLabel('Written contribution').fill(contribution)
	await dialog.getByRole('button', { name: /Save|Update/ }).click()
	await expect(dialog).toBeHidden()

	const stored = await getObject(page, 'speaking-request', id)
	expect(stored?.contribution).toBe(contribution)
	expect(stored?.lifecycle).toBe('spoke')

	await page.goto(`${APP}/agenda-items/${item.id}`)
	const slot = page
		.getByTestId('agenda-item-speakers')
		.getByTestId('speaking-slots-approved')
		.locator('li')
		.filter({ hasText: 'Mw. Jansen' })
	await expect(slot).toContainText('Written contribution')
	await expect(slot).toContainText('Spoke')
})

// @e2e inspraak-register::transcript-segment-linked
test('the secretariat links the transcript segment and the agenda slot opens the transcript there', async ({
	page,
}) => {
	const meeting = await seedMeeting(page, raadBody, 'raad-transcript', 7 * 24)
	const item = await seedItem(page, meeting.id, 'transcript-punt')
	const id = await registered(page, {
		meeting: meeting.id,
		agendaItem: item.id,
		topic: topic('Mw. Jansen', 'Herinrichting dorpsplein'),
	})
	await approve(page, id, 5, 1)
	const spoke = await post(page, `speaking-requests/${id}/outcome`, {
		outcome: 'spoke',
	})
	expect(spoke.status(), await spoke.text()).toBe(200)

	// The meeting's transcript: the public speaker starts at 12:34 in the recording.
	await createObject(page, ledger, 'transcript', {
		title: `${tag} transcript`,
		meeting: meeting.id,
		status: 'done',
		segments: [
			{ startTime: 600, endTime: 754, speakerLabel: 'Speaker 1', agendaItem: item.id, text: 'Ik geef het woord aan de inspreker.' },
			{ startTime: 754.4, endTime: 1054, speakerLabel: 'Speaker 2', agendaItem: item.id, text: 'Houd het dorpsplein autovrij op marktdagen.' },
		],
	})

	// The secretariat links the segment as the transcript shows its start.
	await page.goto(`${APP}/speaking-requests/${id}`)
	await page.getByTestId('cn-detail-page-edit').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()
	await dialog.getByLabel('Transcript segment').fill('12:34')
	await dialog.getByRole('button', { name: /Save|Update/ }).click()
	await expect(dialog).toBeHidden()
	expect((await getObject(page, 'speaking-request', id))?.transcriptSegment).toBe('12:34')

	// The agenda item's speaker entry offers the deeplink.
	await page.goto(`${APP}/agenda-items/${item.id}`)
	const link = page
		.getByTestId('agenda-item-speakers')
		.getByTestId('speaking-slots-approved')
		.locator('li')
		.filter({ hasText: 'Mw. Jansen' })
		.getByTestId('speaking-slot-transcript')
	await expect(link).toHaveText(/12:34/)
	await expect(link).toHaveAttribute('href', new RegExp(`/meetings/${meeting.id}#transcript-segment-754$`))

	// Following it lands on that segment in the meeting's transcript.
	await link.click()
	await expect(page).toHaveURL(/#transcript-segment-754$/)
	const segment = page.locator('#transcript-segment-754')
	await expect(segment).toBeVisible()
	await expect(segment).toContainText('Houd het dorpsplein autovrij op marktdagen.')
	await expect(segment).toContainText('12:34')

	// No contact details appear in the public rendering.
	const publicSlots = await page.request.get(`${APP}/api/meetings/${meeting.id}/speaking-slots/public`, {
		headers: { Accept: 'application/json' },
	})
	expect(publicSlots.ok()).toBe(true)
	const raw = await publicSlots.text()
	expect(raw).not.toContain('example.org')
	expect(raw).not.toContain('contactDetails')
})

// ── The secretariat overview (REQ-INS-010) ───────────────────────────────────

// @e2e inspraak-agenda-live::pending-approvals-across-meetings
test('the overview lists pending requests across meetings with a warning on the imminent one', async ({
	page,
}) => {
	const raad = await seedMeeting(page, raadBody, 'raad-volgende-week', 7 * 24)
	const raadItem = await seedItem(page, raad.id, 'volgende-week-punt')
	await registered(page, {
		meeting: raad.id,
		agendaItem: raadItem.id,
		topic: topic('A. Visser', 'Verkeersbesluit centrum'),
	})
	// The ALV is tomorrow: past its 48-hour deadline, so only the override registers it.
	const alv = await seedMeeting(page, alvBody, 'alv-morgen', 20)
	await registered(page, {
		meeting: alv.id,
		topic: topic('Mw. Pietersen', 'Verhoging servicekosten'),
		override: true,
	})

	await page.goto(`${APP}/speaking-requests`)
	const alvRow = overviewRow(page, alv)
	const raadRow = overviewRow(page, raad)
	await expect(alvRow).toBeVisible()
	await expect(raadRow).toBeVisible()

	// Warned in text with a sign, never by colour alone.
	await expect(alvRow.getByTestId('speaking-request-deadline')).toContainText(
		'Registration closed',
	)
	await expect(alvRow.getByTestId('speaking-request-deadline')).toContainText('⚠')
	await expect(raadRow.getByTestId('speaking-request-deadline')).toHaveText(
		'Registration open',
	)

	// Moderation is actionable from the row.
	await alvRow.getByTestId('cn-row-actions').getByRole('button').first().click()
	await expect(page.getByRole('menuitem', { name: 'Approve' })).toBeVisible()
	await expect(page.getByRole('menuitem', { name: 'Reject' })).toBeVisible()
	await expect(page.getByRole('menuitem', { name: 'Refer' })).toBeVisible()
})
