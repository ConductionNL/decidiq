/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: member onboarding and offboarding (change member-onboarding,
 * task 8). It walks the three flows the task names, each driven as the
 * administrator, who counts as the secretariat:
 *
 *   - onboarding: create, installation, provisioning, induction pack, completed;
 *   - offboarding: end date, revocation, exit confirmation, completed, plus the
 *     completion block while the revocation is still open;
 *   - member turnover: diff, deselect, confirm, then the batch on the index.
 *
 * The checklist steps and the turnover dialog are clicked in the browser.
 * Provisioning, induction-pack delivery and revocation have no button on any
 * page yet (the detail pages' manifest note keeps them DEFERRED), so those
 * three steps are called on their own routes, as the secretariat, with the
 * record open in the browser before and after.
 *
 * Already covered below the browser:
 *   - tests/Unit/Service/OnboardingProvisioningServiceTest.php: assigning and
 *     revoking groups, fail-closed with undo, the allowed-groups setting;
 *   - tests/Unit/Service/InductionPackServiceTest.php: delivery, skip report,
 *     the refusal without a linked account;
 *   - tests/Unit/Service/RosterTurnoverServiceTest.php: the diff, the bulk
 *     reads, the re-check on confirm (TOCTOU);
 *   - tests/Unit/Service/OnboardingChecklistServiceTest.php,
 *     OnboardingStepRulesTest.php and OnboardingStepEffectsTest.php: marking a
 *     step, the skip rule, the installation and end-of-membership effects;
 *   - tests/Unit/Controller/MemberPathwayControllerTest.php and
 *     tests/Unit/Service/Pathway/PathwayStaffGuardTest.php: who may call;
 *   - tests/vitest/onboardingChecklist.spec.js, rosterTurnover.spec.js and
 *     memberPathwayWidgets.spec.js: the checklist rows, the dialog payload and
 *     the dashboard widgets.
 *
 * Every flow seeds its own body, people, accounts, groups and records under a
 * run id and removes them afterwards, and restores the allowed-groups setting
 * it widens. The dashboard test reads the municipality example set
 * (lib/Settings/profiles/municipality.json: the onboardings of Jan de Vries,
 * started, Marie Janssen, in execution, and Janneke de Bruin, completed).
 *
 * Written, not yet run: it needs a live instance.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md
 */
import type { Locator, Page } from '@playwright/test'
import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import {
	adminActor,
	APP_API,
	appPost,
	BASE,
	createObject,
	describe,
	disposeActors,
	listObjects,
	newRunId,
	ObjectLedger,
	OR,
	provisionAccount,
	readObject,
	removeAccounts,
	replaceObject,
	uuidOf,
} from './support/api-actors.ts'
import { openRow } from './support/open-row.ts'

// The overdue reminder and the absence of imperative dispatch carry their
// reason-bearing @e2e exclude in the change spec, where gate 19 reads it.

const APP = `${BASE}/index.php/apps/decidiq`
const OCS = `${BASE}/ocs/v2.php/cloud`
const ONBOARDING = 'member-onboarding'
const OFFBOARDING = 'member-offboarding'
const SETTING = 'member_provisioning_groups'

let admin: Actor
let ledger: ObjectLedger
let run: string
let accounts: string[]
let groups: string[]
let members: Actor[]
let savedSetting: string | null

test.beforeEach(async ({ playwright }) => {
	admin = await adminActor(playwright)
	ledger = new ObjectLedger([
		ONBOARDING,
		OFFBOARDING,
		'membership',
		'meeting',
		'person',
		'governance-body',
	])
	run = newRunId()
	accounts = []
	groups = []
	members = []
	savedSetting = null
})

test.afterEach(async () => {
	await ledger.cleanup(admin)
	if (savedSetting !== null) {
		await admin.ctx
			.put(`${APP_API}/settings`, { data: { [SETTING]: savedSetting } })
			.catch(() => undefined)
	}
	for (const gid of groups) {
		await admin.ctx
			.delete(`${OCS}/groups/${encodeURIComponent(gid)}?format=json`)
			.catch(() => undefined)
	}
	await removeAccounts(admin, accounts)
	await disposeActors(...members, admin)
})

/**
 * Create a Nextcloud group for this run.
 *
 * @param name The group's suffix.
 * @return The group id.
 */
async function seedGroup(name: string): Promise<string> {
	const gid = `e2e-${run}-${name}`
	const resp = await admin.ctx.post(`${OCS}/groups?format=json`, {
		data: { groupid: gid },
	})
	expect(resp.ok(), await describe(resp)).toBeTruthy()
	groups.push(gid)
	return gid
}

/**
 * Create a Nextcloud account for this run, in the given groups.
 *
 * @param playwright The Playwright fixture.
 * @param name       The account's suffix.
 * @param inGroups   The groups to put it in.
 * @return The account's actor.
 */
async function seedAccount(
	playwright: any,
	name: string,
	inGroups: string[] = [],
): Promise<Actor> {
	const uid = `e2e-${run}-${name}`
	const member = await provisionAccount(playwright, admin, uid, inGroups)
	accounts.push(uid)
	members.push(member)
	return member
}

/**
 * The groups an account is in, as Nextcloud reports them.
 *
 * @param uid The account id.
 * @return The group ids.
 */
async function groupsOf(uid: string): Promise<string[]> {
	const resp = await admin.ctx.get(
		`${OCS}/users/${encodeURIComponent(uid)}/groups?format=json`,
	)
	expect(resp.ok(), await describe(resp)).toBeTruthy()
	const body = await resp.json()
	return (body?.ocs?.data?.groups ?? []) as string[]
}

/**
 * Let member provisioning assign and revoke the given groups, on top of what
 * the instance already allows. afterEach puts the old value back.
 *
 * @param names The group ids to allow.
 * @return Resolves once saved.
 */
async function allowGroups(names: string[]): Promise<void> {
	const current = await admin.ctx.get(`${APP_API}/settings`)
	const values = current.ok() ? ((await current.json())?.config ?? {}) : {}
	savedSetting = String(values[SETTING] ?? '')
	const merged = [...savedSetting.split(','), ...names]
		.map((n) => n.trim())
		.filter((n) => n !== '')
	const resp = await admin.ctx.put(`${APP_API}/settings`, {
		data: { [SETTING]: [...new Set(merged)].join(',') },
	})
	expect(resp.status(), await describe(resp)).toBeLessThan(300)
}

/**
 * Seed a governance body for this run.
 *
 * @param extra Fields on top of the name, type and domain.
 * @return The body as created.
 */
async function seedBody(extra: Record<string, unknown> = {}): Promise<any> {
	return createObject(admin, ledger, 'governance-body', {
		name: `${run}-raad`,
		bodyType: 'legislative',
		domain: 'municipality',
		...extra,
	})
}

/**
 * Seed a person for this run.
 *
 * @param name  The person's name suffix.
 * @param extra Fields on top of the name.
 * @return The person as created.
 */
async function seedPerson(
	name: string,
	extra: Record<string, unknown> = {},
): Promise<any> {
	return createObject(admin, ledger, 'person', {
		name: `${run}-${name}`,
		...extra,
	})
}

/**
 * The stored record, read back through OpenRegister.
 *
 * @param schema The schema slug.
 * @param id     The record id.
 * @return Its stored fields.
 */
async function reread(schema: string, id: string): Promise<any> {
	const resp = await readObject(admin, schema, id)
	expect(resp.status(), await describe(resp)).toBe(200)
	return resp.json()
}

/**
 * Save the whole record with some fields changed, the way the SPA saves one.
 *
 * @param schema  The schema slug.
 * @param id      The record id.
 * @param changes The fields to change.
 * @return The raw response.
 */
async function saveWith(
	schema: string,
	id: string,
	changes: Record<string, unknown>,
) {
	const { '@self': _self, id: _id, ...fields } = await reread(schema, id)
	return replaceObject(admin, schema, id, { ...fields, ...changes })
}

/**
 * Open a record's detail page and wait for its checklist.
 *
 * @param page  The page.
 * @param route `member-onboarding` or `member-offboarding`.
 * @param id    The record id.
 * @return The checklist.
 */
async function openChecklist(
	page: Page,
	route: string,
	id: string,
): Promise<Locator> {
	await page.goto(`${APP}/${route}/${id}`)
	const checklist = page.getByTestId('onboarding-checklist-tab')
	await expect(checklist).toBeVisible()
	return checklist
}

/**
 * One row of the checklist, by its title.
 *
 * @param checklist The checklist.
 * @param title     The step title.
 * @return The row.
 */
function stepRow(checklist: Locator, title: string): Locator {
	return checklist
		.getByTestId('onboarding-checklist-step')
		.filter({ hasText: title })
}

// @e2e member-onboarding::fragment-adds-the-schema-without-touching-existing-schemas
test('the register carries both pathway schemas with their fields', async () => {
	const resp = await admin.ctx.get(
		`${BASE}/index.php/apps/openregister/api/registers/decidiq/schemas`,
	)
	test.skip(
		resp.status() === 404,
		"decidiq's register is not imported on this instance",
	)
	expect(resp.ok(), await describe(resp)).toBeTruthy()
	const body = await resp.json()
	const schemas = (body.results ?? body.items ?? []) as any[]
	const bySlug = (slug: string) =>
		schemas.find((s) => String(s.slug ?? '') === slug)

	const expected: Record<string, string[]> = {
		[ONBOARDING]: [
			'person',
			'targetBody',
			'targetRole',
			'trigger',
			'steps',
			'installedOn',
			'installationType',
			'installationMeeting',
			'ncAccount',
			'membership',
			'batch',
			'lifecycle',
		],
		[OFFBOARDING]: [
			'person',
			'body',
			'membership',
			'trigger',
			'endDate',
			'endReason',
			'steps',
			'exitConfirmedBy',
			'exitConfirmedOn',
			'batch',
			'lifecycle',
		],
	}
	for (const [slug, properties] of Object.entries(expected)) {
		const schema = bySlug(slug)
		expect(schema, `the register does not carry ${slug}`).toBeTruthy()
		const declared = Object.keys(schema?.properties ?? {})
		for (const property of properties) {
			expect(declared, `${slug} did not import ${property}`).toContain(
				property,
			)
		}
	}
})

// @e2e member-onboarding::working-the-checklist-from-the-detail-page
// @e2e member-onboarding::oath-step-refuses-incomplete-data
// @e2e member-onboarding::recording-the-oath
// @e2e member-onboarding::completing-one-step-leaves-the-others-untouched
// @e2e member-onboarding::mandatory-step-cannot-be-skipped
// @e2e member-onboarding::provisioning-an-incoming-member
// @e2e member-onboarding::welcome-bundle-delivery
// @e2e member-onboarding::undeliverable-item-is-skipped-not-fatal
// @e2e member-onboarding::installation-creates-the-membership
// @e2e member-onboarding::the-last-open-step-is-marked-done
test('onboarding: installation, provisioning and the induction pack complete the record', async ({
	page,
	playwright,
}) => {
	const councilGroup = await seedGroup('council')
	await allowGroups([councilGroup])
	const member = await seedAccount(playwright, 'joiner')

	const body = await seedBody({
		memberGroups: { member: [councilGroup] },
		inductionDocuments: [
			{ title: 'Council handbook', url: 'https://example.org/handbook' },
			// No such file: the delivery lists it as skipped and goes on.
			{ title: 'Missing standing orders', fileId: 2_000_000_000 },
		],
	})
	const bodyId = uuidOf(body)
	const person = await seedPerson('Joiner', {
		email: `${member.uid}@example.org`,
		nextcloudUserId: member.uid,
	})
	const meeting = await createObject(admin, ledger, 'meeting', {
		title: `${run}-installation`,
		meetingType: 'regular',
		scheduledDate: '2026-03-26T19:30:00Z',
		meetingMode: 'in-person',
		governanceBody: bodyId,
	})

	const onboarding = await createObject(admin, ledger, ONBOARDING, {
		person: uuidOf(person),
		targetBody: bodyId,
		targetRole: 'member',
		trigger: 'new-member',
		ncAccount: member.uid,
		lifecycle: 'started',
		steps: [
			{
				stepType: 'installation',
				title: 'Record the installation',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'account-linking',
				title: 'Link the account',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'assign-groups',
				title: 'Assign the groups',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'induction-pack',
				title: 'Deliver the welcome pack',
				status: 'open',
				mandatory: false,
				note: `${run} prepared by the secretariat`,
			},
			{
				stepType: 'ancillary-positions-intake',
				title: 'Ask about other positions',
				status: 'open',
				mandatory: false,
			},
		],
	})
	const id = uuidOf(onboarding)

	// The detail page lists every step with its status and its actions.
	let checklist = await openChecklist(page, ONBOARDING, id)
	await expect(checklist.getByTestId('onboarding-checklist-step')).toHaveCount(5)
	for (const title of [
		'Record the installation',
		'Link the account',
		'Assign the groups',
		'Deliver the welcome pack',
		'Ask about other positions',
	]) {
		await expect(stepRow(checklist, title)).toContainText('To do')
		await expect(
			stepRow(checklist, title).getByTestId('onboarding-checklist-complete'),
		).toBeVisible()
	}

	// The installation without its date, type and meeting is refused, by name.
	await stepRow(checklist, 'Record the installation')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(checklist.getByRole('alert')).toContainText(
		'Record the installation date, type and meeting',
	)
	await expect(stepRow(checklist, 'Record the installation')).toContainText(
		'To do',
	)

	// With them recorded, the installation completes and starts the membership.
	const recorded = await admin.ctx.patch(`${OR}/${ONBOARDING}/${id}`, {
		data: {
			installedOn: '2026-03-26',
			installationType: 'affirmation',
			installationMeeting: uuidOf(meeting),
		},
	})
	expect(recorded.status(), await describe(recorded)).toBeLessThan(300)
	checklist = await openChecklist(page, ONBOARDING, id)
	await stepRow(checklist, 'Record the installation')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(stepRow(checklist, 'Record the installation')).toContainText('Done')

	let stored = await reread(ONBOARDING, id)
	expect(stored.installedOn).toBe('2026-03-26')
	expect(stored.installationType).toBe('affirmation')
	expect(
		String(stored.installationMeeting?.id ?? stored.installationMeeting),
	).toBe(uuidOf(meeting))
	expect(stored.lifecycle).toBe('in-execution')
	const membershipId = String(stored.membership?.id ?? stored.membership ?? '')
	expect(membershipId, 'the installation starts a membership').not.toBe('')
	ledger.track('membership', membershipId)
	// Completing one step leaves the others as they were, note included.
	// (The checklist route stores the status only: completedAt and completedBy
	// are stamped by the provisioning and induction-pack routes, not here.)
	expect(stored.steps[3].status).toBe('open')
	expect(stored.steps[3].note).toBe(`${run} prepared by the secretariat`)
	expect(stored.steps[1].status).toBe('open')

	// A mandatory step offers no Skip, and the server refuses one anyway.
	await expect(
		stepRow(checklist, 'Link the account').getByTestId(
			'onboarding-checklist-skip',
		),
	).toHaveCount(0)
	await expect(
		stepRow(checklist, 'Deliver the welcome pack').getByTestId(
			'onboarding-checklist-skip',
		),
	).toBeVisible()
	const skipped = await admin.ctx.put(
		`${APP_API}/onboarding/onboarding/${id}/steps/1`,
		{ data: { status: 'skipped' } },
	)
	expect(skipped.status()).toBe(409)
	expect((await skipped.json()).message).toBe(
		'A mandatory step cannot be skipped.',
	)
	await stepRow(checklist, 'Link the account')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(stepRow(checklist, 'Link the account')).toContainText('Done')

	// Provisioning: the role's groups are assigned and the step records them.
	expect(await groupsOf(member.uid)).not.toContain(councilGroup)
	const provisioned = await appPost(
		admin,
		`/member-onboardings/${id}/assign-groups`,
	)
	expect(provisioned.status(), await describe(provisioned)).toBe(200)
	expect((await provisioned.json()).groups).toEqual([councilGroup])
	expect(await groupsOf(member.uid)).toContain(councilGroup)
	stored = await reread(ONBOARDING, id)
	expect(stored.steps[2].status).toBe('completed')
	expect(stored.steps[2].reference).toBe(councilGroup)
	expect(String(stored.steps[2].note)).toContain(councilGroup)
	expect(stored.steps[2].completedBy).toBeTruthy()

	// The induction pack lands in the member's Files; the missing item is skipped.
	const delivered = await appPost(
		admin,
		`/member-onboardings/${id}/induction-pack`,
	)
	expect(delivered.status(), await describe(delivered)).toBe(200)
	const pack = await delivered.json()
	expect(String(pack.folder)).toMatch(/^\/Welcome/)
	expect(pack.delivered.length).toBeGreaterThan(0)
	expect(pack.skipped.join(' ')).toContain('Missing standing orders')
	const folder = await member.ctx.fetch(
		`${BASE}/remote.php/dav/files/${encodeURIComponent(member.uid)}/${encodeURIComponent(String(pack.folder).replace(/^\//, ''))}`,
		{ method: 'PROPFIND', headers: { Depth: '1' } },
	)
	expect(folder.status(), "the welcome folder is in the member's own Files").toBe(
		207,
	)
	stored = await reread(ONBOARDING, id)
	expect(stored.steps[3].status).toBe('completed')
	expect(stored.steps[3].reference).toBe(pack.folder)

	// The page shows the routes' work, and the last open step completes the record.
	checklist = await openChecklist(page, ONBOARDING, id)
	await expect(stepRow(checklist, 'Assign the groups')).toContainText('Done')
	await expect(stepRow(checklist, 'Deliver the welcome pack')).toContainText(
		'Done',
	)
	await stepRow(checklist, 'Ask about other positions')
		.getByTestId('onboarding-checklist-skip')
		.click()
	await expect(stepRow(checklist, 'Ask about other positions')).toContainText(
		'Skipped',
	)
	await expect(checklist.getByTestId('onboarding-checklist-complete')).toHaveCount(
		0,
	)
	expect((await reread(ONBOARDING, id)).lifecycle).toBe('completed')

	// The index shows the new status on the member's row.
	await page.goto(`${APP}/member-onboarding`)
	const row = page.locator('tr').filter({ hasText: `${run}-Joiner` })
	await expect(row).toContainText(/completed/i)
})

// @e2e member-onboarding::failed-group-write-leaves-the-step-open
test('onboarding: a group that does not exist keeps the provisioning step open', async ({
	page,
	playwright,
}) => {
	const missing = `e2e-${run}-no-such-group`
	await allowGroups([missing])
	const member = await seedAccount(playwright, 'joiner')
	const body = await seedBody({ memberGroups: { member: [missing] } })
	const person = await seedPerson('Joiner', { nextcloudUserId: member.uid })
	const onboarding = await createObject(admin, ledger, ONBOARDING, {
		person: uuidOf(person),
		targetBody: uuidOf(body),
		targetRole: 'member',
		trigger: 'new-member',
		ncAccount: member.uid,
		lifecycle: 'started',
		steps: [
			{
				stepType: 'assign-groups',
				title: 'Assign the groups',
				status: 'open',
				mandatory: true,
			},
		],
	})
	const id = uuidOf(onboarding)

	const refused = await appPost(admin, `/member-onboardings/${id}/assign-groups`)
	expect(refused.status()).toBe(422)
	const answer = await refused.json()
	expect(answer.outcome).toBe('refused')
	expect(answer.message).toContain(missing)
	expect(answer.message).toContain('No group was assigned')
	expect(answer.groups).toBeUndefined()

	expect((await reread(ONBOARDING, id)).steps[0].status).toBe('open')
	const checklist = await openChecklist(page, ONBOARDING, id)
	await expect(stepRow(checklist, 'Assign the groups')).toContainText('To do')
})

// @e2e member-onboarding::offboarding-traject-created-for-a-departing-member
// @e2e member-onboarding::revocation-removes-access
// @e2e member-onboarding::offboarding-ends-it
test('offboarding: end date, revocation and exit confirmation complete the record', async ({
	page,
	playwright,
}) => {
	const councilGroup = await seedGroup('council')
	const unrelated = await seedGroup('choir')
	await allowGroups([councilGroup])
	const member = await seedAccount(playwright, 'leaver', [councilGroup, unrelated])

	const body = await seedBody({ memberGroups: { member: [councilGroup] } })
	const bodyId = uuidOf(body)
	const person = await seedPerson('Leaver', { nextcloudUserId: member.uid })
	const membership = await createObject(admin, ledger, 'membership', {
		person: uuidOf(person),
		governanceBody: bodyId,
		role: 'member',
		startDate: '2022-03-01T00:00:00Z',
	})
	const membershipId = uuidOf(membership)

	const offboarding = await createObject(admin, ledger, OFFBOARDING, {
		person: uuidOf(person),
		body: bodyId,
		membership: membershipId,
		trigger: 'individual',
		endDate: '2026-03-29',
		endReason: 'end-of-council-membership',
		lifecycle: 'started',
		steps: [
			{
				stepType: 'end-membership',
				title: 'End the membership',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'revoke-groups',
				title: 'Withdraw groups and access',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'personal-data-note',
				title: 'Personal data note',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'exit-confirmation',
				title: 'Confirm the exit',
				status: 'open',
				mandatory: true,
			},
		],
	})
	const id = uuidOf(offboarding)

	// The record refers to the person, the body and the membership, and its
	// checklist holds the four steps of a departure.
	let stored = await reread(OFFBOARDING, id)
	expect(String(stored.person?.id ?? stored.person)).toBe(uuidOf(person))
	expect(String(stored.body?.id ?? stored.body)).toBe(bodyId)
	expect(String(stored.membership?.id ?? stored.membership)).toBe(membershipId)
	expect(stored.steps.map((s: any) => s.stepType)).toEqual([
		'end-membership',
		'revoke-groups',
		'personal-data-note',
		'exit-confirmation',
	])

	let checklist = await openChecklist(page, OFFBOARDING, id)
	await expect(checklist.getByTestId('onboarding-checklist-step')).toHaveCount(4)
	await expect(
		stepRow(checklist, 'Withdraw groups and access').getByTestId(
			'onboarding-checklist-skip',
		),
	).toHaveCount(0)

	// End date: the membership carries the offboarding's end date.
	await stepRow(checklist, 'End the membership')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(stepRow(checklist, 'End the membership')).toContainText('Done')
	expect(String((await reread('membership', membershipId)).endDate)).toMatch(
		/^2026-03-29/,
	)

	await stepRow(checklist, 'Personal data note')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(stepRow(checklist, 'Personal data note')).toContainText('Done')

	// Revocation: the body's groups go, the unrelated group stays, the step
	// records the verified state.
	const revoked = await appPost(admin, `/member-offboardings/${id}/revoke-groups`)
	expect(revoked.status(), await describe(revoked)).toBe(200)
	const answer = await revoked.json()
	expect(answer.removed).toEqual([councilGroup])
	expect(answer.endDate).toBe('2026-03-29')
	const after = await groupsOf(member.uid)
	expect(after).not.toContain(councilGroup)
	expect(after).toContain(unrelated)
	stored = await reread(OFFBOARDING, id)
	expect(stored.steps[1].status).toBe('completed')
	expect(String(stored.steps[1].note)).toContain(
		'Not in any group of this body any more.',
	)
	expect(String((await reread('membership', membershipId)).endDate)).toMatch(
		/^2026-03-29/,
	)

	// Exit confirmation, the last open step, completes the record.
	checklist = await openChecklist(page, OFFBOARDING, id)
	await expect(stepRow(checklist, 'Withdraw groups and access')).toContainText(
		'Done',
	)
	await stepRow(checklist, 'Confirm the exit')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(stepRow(checklist, 'Confirm the exit')).toContainText('Done')
	expect((await reread(OFFBOARDING, id)).lifecycle).toBe('completed')
})

// @e2e member-onboarding::offboarding-cannot-complete-without-revocation
test('offboarding: the record stays open while the revocation is open', async ({
	page,
}) => {
	const body = await seedBody()
	const person = await seedPerson('Leaver')
	const membership = await createObject(admin, ledger, 'membership', {
		person: uuidOf(person),
		governanceBody: uuidOf(body),
		role: 'member',
		startDate: '2022-03-01T00:00:00Z',
		endDate: '2026-03-29T00:00:00Z',
	})
	// As the example set's batch offboarding: ended, but access not withdrawn.
	const offboarding = await createObject(admin, ledger, OFFBOARDING, {
		person: uuidOf(person),
		body: uuidOf(body),
		membership: uuidOf(membership),
		trigger: 'individual',
		endDate: '2026-03-29',
		endReason: 'resignation',
		lifecycle: 'in-execution',
		steps: [
			{
				stepType: 'end-membership',
				title: 'End the membership',
				status: 'completed',
				mandatory: true,
			},
			{
				stepType: 'revoke-groups',
				title: 'Withdraw groups and access',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'personal-data-note',
				title: 'Personal data note',
				status: 'completed',
				mandatory: true,
			},
			{
				stepType: 'exit-confirmation',
				title: 'Confirm the exit',
				status: 'open',
				mandatory: true,
			},
		],
	})
	const id = uuidOf(offboarding)

	const checklist = await openChecklist(page, OFFBOARDING, id)
	await stepRow(checklist, 'Confirm the exit')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(stepRow(checklist, 'Confirm the exit')).toContainText('Done')

	// Everything else is settled, and still the record does not complete.
	await expect(stepRow(checklist, 'Withdraw groups and access')).toContainText(
		'To do',
	)
	await expect(
		stepRow(checklist, 'Withdraw groups and access').getByTestId(
			'onboarding-checklist-skip',
		),
	).toHaveCount(0)
	expect((await reread(OFFBOARDING, id)).lifecycle).toBe('in-execution')

	// Nor can the revocation step be skipped to get there.
	const skipped = await admin.ctx.put(
		`${APP_API}/onboarding/offboarding/${id}/steps/1`,
		{ data: { status: 'skipped' } },
	)
	expect(skipped.status()).toBe(409)
	expect((await reread(OFFBOARDING, id)).lifecycle).toBe('in-execution')
})

// @e2e member-onboarding::personal-data-step-points-at-the-annotations-export-not-a-bespoke-one
test('offboarding: the personal-data step links to the own-annotations export', async ({
	page,
}) => {
	const body = await seedBody()
	const person = await seedPerson('Leaver')
	const offboarding = await createObject(admin, ledger, OFFBOARDING, {
		person: uuidOf(person),
		body: uuidOf(body),
		trigger: 'individual',
		endDate: '2026-03-29',
		endReason: 'end-of-council-membership',
		lifecycle: 'started',
		steps: [
			{ stepType: 'personal-data-note', title: 'Personal data note', status: 'open' },
			{ stepType: 'exit-confirmation', title: 'Confirm the exit', status: 'open' },
		],
	})

	const checklist = await openChecklist(page, OFFBOARDING, uuidOf(offboarding))
	const link = stepRow(checklist, 'Personal data note').getByTestId('onboarding-checklist-annotations-export')
	await expect(link).toBeVisible()
	await expect(link).toHaveAttribute('href', /\/apps\/decidiq\/api\/annotations\/export\?format=csv$/)
	await expect(stepRow(checklist, 'Confirm the exit').getByTestId('onboarding-checklist-annotations-export')).toHaveCount(0)

	// No offboarding-owned export exists: the step points at the document-annotations one.
	const own = await admin.ctx.get(`${APP}/api/onboarding/offboarding/${uuidOf(offboarding)}/export`)
	expect(own.status()).toBe(404)
})

// @e2e member-onboarding::a-plain-member-cannot-tick-steps
test('onboarding: a plain member cannot tick a step', async ({ playwright }) => {
	const body = await seedBody()
	const person = await seedPerson('Joiner')
	const onboarding = await createObject(admin, ledger, ONBOARDING, {
		person: uuidOf(person),
		targetBody: uuidOf(body),
		targetRole: 'member',
		trigger: 'new-member',
		lifecycle: 'started',
		steps: [{ stepType: 'custom', title: 'Sign the code of conduct', status: 'open', mandatory: true }],
	})
	const id = uuidOf(onboarding)
	const kees = await seedAccount(playwright, 'kees')

	const answer = await kees.ctx.put(`${APP_API}/onboarding/onboarding/${id}/steps/0`, {
		data: { status: 'completed' },
	})
	expect(answer.status()).toBe(403)
	const stored = await reread(ONBOARDING, id)
	expect(stored.steps[0].status).toBe('open')
	expect(stored.lifecycle).toBe('started')
})

// @e2e member-onboarding::traject-cannot-complete-with-open-mandatory-steps
test('onboarding: an open mandatory step keeps the record from completing', async ({
	page,
}) => {
	const body = await seedBody()
	const person = await seedPerson('Joiner')
	const onboarding = await createObject(admin, ledger, ONBOARDING, {
		person: uuidOf(person),
		targetBody: uuidOf(body),
		targetRole: 'member',
		trigger: 'new-member',
		lifecycle: 'started',
		steps: [
			{
				stepType: 'custom',
				title: 'Sign the code of conduct',
				status: 'open',
				mandatory: true,
			},
			{
				stepType: 'custom',
				title: 'Take the building tour',
				status: 'open',
				mandatory: false,
			},
		],
	})
	const id = uuidOf(onboarding)

	const checklist = await openChecklist(page, ONBOARDING, id)
	await stepRow(checklist, 'Take the building tour')
		.getByTestId('onboarding-checklist-skip')
		.click()
	await expect(stepRow(checklist, 'Take the building tour')).toContainText(
		'Skipped',
	)
	await expect(stepRow(checklist, 'Sign the code of conduct')).toContainText(
		'To do',
	)
	expect((await reread(ONBOARDING, id)).lifecycle).toBe('in-execution')

	// Writing the status directly, as the record's edit form would, is refused too.
	const forced = await saveWith(ONBOARDING, id, { lifecycle: 'completed' })
	expect(
		forced.status(),
		'completing with an open mandatory step is refused',
	).toBeGreaterThanOrEqual(400)
	expect(forced.status()).toBeLessThan(500)
	expect(await forced.text()).toContain('Sign the code of conduct')
	expect((await reread(ONBOARDING, id)).lifecycle).toBe('in-execution')
})

// @e2e member-onboarding::terminal-state-is-final
test('onboarding: a completed record cannot go back', async ({ page }) => {
	const body = await seedBody()
	const person = await seedPerson('Joiner')
	const onboarding = await createObject(admin, ledger, ONBOARDING, {
		person: uuidOf(person),
		targetBody: uuidOf(body),
		targetRole: 'member',
		trigger: 'new-member',
		lifecycle: 'started',
		steps: [
			{
				stepType: 'custom',
				title: 'Sign the code of conduct',
				status: 'open',
				mandatory: true,
			},
		],
	})
	const id = uuidOf(onboarding)

	const checklist = await openChecklist(page, ONBOARDING, id)
	await stepRow(checklist, 'Sign the code of conduct')
		.getByTestId('onboarding-checklist-complete')
		.click()
	await expect(stepRow(checklist, 'Sign the code of conduct')).toContainText(
		'Done',
	)
	expect((await reread(ONBOARDING, id)).lifecycle).toBe('completed')

	// A finished record offers no action, and its steps no longer change.
	await page.reload()
	await expect(page.getByTestId('onboarding-checklist-tab')).toBeVisible()
	await expect(page.getByTestId('onboarding-checklist-complete')).toHaveCount(0)
	await expect(page.getByTestId('onboarding-checklist-skip')).toHaveCount(0)

	// The declared lifecycle map has no way back from completed.
	for (const state of ['started', 'in-execution']) {
		const back = await saveWith(ONBOARDING, id, { lifecycle: state })
		expect(
			back.status(),
			`completed -> ${state} is refused`,
		).toBeGreaterThanOrEqual(400)
		expect(back.status()).toBeLessThan(500)
	}
	expect((await reread(ONBOARDING, id)).lifecycle).toBe('completed')
})

// @e2e member-onboarding::diff-produces-onboarding-and-offboarding-suggestions
// @e2e member-onboarding::only-confirmed-suggestions-become-trajecten
// @e2e member-onboarding::batch-filter-on-the-index
test('member turnover: diff, deselect one leaver, confirm one batch, filter on it', async ({
	page,
}) => {
	const body = await seedBody()
	const bodyId = uuidOf(body)
	const batch = `e2e-${run}-turnover`

	// Two sitting members; the new roster names neither of them.
	const stays = await seedPerson('Reinstalled', {
		email: `reinstalled-${run}@example.org`,
	})
	const leaves = await seedPerson('Departing', {
		email: `departing-${run}@example.org`,
	})
	const memberships: Record<string, string> = {}
	for (const p of [stays, leaves]) {
		const m = await createObject(admin, ledger, 'membership', {
			person: uuidOf(p),
			governanceBody: bodyId,
			role: 'member',
			startDate: '2022-03-01T00:00:00Z',
		})
		memberships[uuidOf(p)] = uuidOf(m)
	}
	const csv = [
		'name,email,role',
		`${run}-Newcomer One,newcomer1-${run}@example.org,member`,
		`${run}-Newcomer Two,newcomer2-${run}@example.org,member`,
	].join('\n')

	try {
		await page.goto(`${APP}/governance-bodies/${bodyId}`)
		await expect(page.getByTestId('body-members-tab')).toBeVisible()
		await page.getByRole('button', { name: 'Import members' }).click()
		await page.getByRole('menuitem', { name: 'Member turnover' }).click()
		const dialog = page.getByTestId('member-turnover-dialog')
		await expect(dialog).toBeVisible()

		await dialog.getByTestId('member-turnover-file').setInputFiles({
			name: 'roster.csv',
			mimeType: 'text/csv',
			buffer: Buffer.from(csv, 'utf8'),
		})

		// The diff: two joining, two leaving, all selected, nothing created yet.
		const joining = dialog.getByTestId('member-turnover-joining')
		const leaving = dialog.getByTestId('member-turnover-leaving')
		await expect(joining).toContainText('Joining (2)')
		await expect(joining).toContainText(`${run}-Newcomer One (member)`)
		await expect(joining).toContainText(`${run}-Newcomer Two (member)`)
		await expect(leaving).toContainText('Leaving (2)')
		await expect(leaving).toContainText(`${run}-Reinstalled`)
		await expect(leaving).toContainText(`${run}-Departing`)
		expect(
			(await listObjects(admin, ONBOARDING, { batch })).results,
		).toHaveLength(0)
		expect(
			(await listObjects(admin, OFFBOARDING, { batch })).results,
		).toHaveLength(0)

		// Deselect the member who is reinstalled, name the batch, confirm.
		const reinstalled = leaving.getByRole('checkbox', {
			name: new RegExp(`${run}-Reinstalled`),
		})
		await leaving.getByText(`${run}-Reinstalled`).click()
		await expect(reinstalled).not.toBeChecked()
		await dialog
			.getByTestId('member-turnover-batch')
			.locator('input')
			.fill(batch)
		await page.locator('#member-turnover-end-date').fill('2026-03-29')
		await expect(dialog.getByTestId('member-turnover-confirm')).toBeEnabled()
		await dialog.getByTestId('member-turnover-confirm').click()
		await expect(dialog.getByTestId('member-turnover-done')).toContainText(
			'2 onboardings and 1 offboardings created; 0 no longer applied.',
		)

		// Only the confirmed suggestions became records, all in the one batch.
		const onboardings = (await listObjects(admin, ONBOARDING, { batch })).results
		const offboardings = (await listObjects(admin, OFFBOARDING, { batch }))
			.results
		expect(onboardings).toHaveLength(2)
		expect(offboardings).toHaveLength(1)
		for (const record of [...onboardings, ...offboardings]) {
			expect(record.batch).toBe(batch)
			expect(record.trigger).toBe('council-turnover-batch')
		}
		expect(String(offboardings[0].person?.id ?? offboardings[0].person)).toBe(
			uuidOf(leaves),
		)

		// The run itself created and ended no membership.
		for (const membershipId of Object.values(memberships)) {
			expect(
				(await reread('membership', membershipId)).endDate ?? null,
			).toBeNull()
		}
		const bodyMemberships = (
			await listObjects(admin, 'membership', { governanceBody: bodyId })
		).results
		expect(bodyMemberships).toHaveLength(2)

		// The index filtered on the batch lists exactly this run's records.
		await page.goto(`${APP}/member-onboarding?${new URLSearchParams({ batch })}`)
		const table = page.locator('table')
		await expect(table.getByText(`${run}-Newcomer One`)).toBeVisible()
		await expect(table.getByText(`${run}-Newcomer Two`)).toBeVisible()
		await expect(table.locator('tbody tr')).toHaveCount(2)
		await page.goto(
			`${APP}/member-offboarding?${new URLSearchParams({ batch })}`,
		)
		await expect(
			page.locator('table').getByText(`${run}-Departing`),
		).toBeVisible()
		await expect(page.locator('table tbody tr')).toHaveCount(1)
	} finally {
		// The run made these; the ledger never saw them.
		for (const schema of [ONBOARDING, OFFBOARDING]) {
			for (const record of (await listObjects(admin, schema, { batch }))
				.results) {
				ledger.track(schema, uuidOf(record))
				if (schema === ONBOARDING) {
					ledger.track(
						'person',
						String(record.person?.id ?? record.person ?? ''),
					)
				}
			}
		}
	}
})

// @e2e member-onboarding::status-overview-widget
test('dashboard: the joining-and-leaving counts open the pre-filtered index', async ({
	page,
}) => {
	await page.goto(`${APP}/`)
	await expect(page.getByText('Joining and leaving')).toBeVisible()
	for (const title of [
		'Onboarding started',
		'Onboarding in progress',
		'Offboarding started',
		'Offboarding in progress',
	]) {
		await expect(page.getByText(title, { exact: true }).first()).toBeVisible()
	}

	await page.getByText('Onboarding open', { exact: true }).first().click()
	await expect(page).toHaveURL(/\/member-onboarding\?.*lifecycle=/)
	const table = page.locator('table')
	await expect(table.getByText('Marie Janssen').first()).toBeVisible()
	await expect(table.getByText('Jan de Vries').first()).toBeVisible()
	// The completed onboarding of the example set is not in the open set.
	await expect(table.getByText('Janneke de Bruin')).toHaveCount(0)
	await openRow(page, 'Marie Janssen')
	await expect(page.getByTestId('onboarding-checklist-tab')).toBeVisible()
})
