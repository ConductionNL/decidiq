/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the delegation and mandate register
 * (change delegatie-mandaatregister, REQ-DMR-004 to REQ-DMR-006).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * The unit suite proves the guard, the register fragment and the manifest
 * fragment agree with each other. It cannot prove that the imported schema
 * answers the in-force-on-date question as one list query, that the public
 * predicate really lets an anonymous visitor read a published delegation and
 * not an unpublished one, that a withdrawal shows on the next anonymous read,
 * or that the pages render: the register index, the delegation detail with its
 * sub-mandate chain, and the decision's "taken under" link.
 *
 * Every fixture is created through the OpenRegister object API as the admin and
 * removed in afterAll. The anonymous reads use a request context with an empty
 * storage state, so no admin cookie leaks into them.
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md
 * @e2e delegatie-mandaatregister/requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export/who-may-decide-what-on-a-given-date
 * @e2e delegatie-mandaatregister/requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export/per-delegataris-view-and-export
 * @e2e delegatie-mandaatregister/requirement-req-dmr-005-public-register-via-the-or-published-predicate-on-the-live-object/published-toedeling-is-anonymously-readable
 * @e2e delegatie-mandaatregister/requirement-req-dmr-005-public-register-via-the-or-published-predicate-on-the-live-object/intrekking-is-live-on-the-public-register
 * @e2e delegatie-mandaatregister/requirement-req-dmr-005-public-register-via-the-or-published-predicate-on-the-live-object/unpublished-toedeling-is-not-public
 * @e2e delegatie-mandaatregister/requirement-req-dmr-006-assistive-bevoegdheidsgrondslag-link-on-decision-never-enforcement/decision-records-the-mandate-it-was-taken-under
 * @e2e delegatie-mandaatregister/requirement-req-dmr-006-assistive-bevoegdheidsgrondslag-link-on-decision-never-enforcement/no-enforcement-without-or-outside-a-mandate
 */
import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import {
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
	replaceObject,
	uuidOf,
} from './support/api-actors.ts'

const SCHEMA = 'authority-delegation'
const APP = `${BASE}/index.php/apps/decidiq`

test.describe.configure({ mode: 'serial' })

const run = newRunId()
const tag = `e2e-dmr-${run}`
const ledger = new ObjectLedger([SCHEMA, 'decision', 'governance-body'])

let admin: Actor
let anon: Actor
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
 * Read an object back as the admin, for a whole-object PUT.
 *
 * @param id The delegation's UUID.
 * @return The decoded object without its metadata block.
 */
async function current(id: string): Promise<Record<string, unknown>> {
	const resp = await readObject(admin, SCHEMA, id)
	expect(resp.status(), await describe(resp)).toBe(200)
	const { '@self': _self, ...data } = await resp.json()
	return data
}

/**
 * Replace a delegation with `patch` merged in, as the admin.
 *
 * @param id    The delegation's UUID.
 * @param patch The fields to change.
 * @return Resolves once the save succeeded.
 */
async function update(id: string, patch: Record<string, unknown>): Promise<void> {
	const resp = await replaceObject(admin, SCHEMA, id, {
		...(await current(id)),
		...patch,
	})
	expect(resp.status(), await describe(resp)).toBeLessThan(300)
}

/**
 * Create a delegation as a draft and walk it to `status` through the declared
 * transitions only (draft → effective → withdrawn).
 *
 * @param key    The key it is kept under in `ids`.
 * @param status The status it should end in.
 * @param data   The delegation's own fields.
 * @return Resolves once it is in `status`.
 */
async function delegation(
	key: string,
	status: 'draft' | 'effective' | 'withdrawn',
	data: Record<string, unknown>,
): Promise<void> {
	const created = await createObject(admin, ledger, SCHEMA, {
		type: 'mandate',
		delegatingBody: ids.council,
		decision: ids.decision,
		...data,
		subject: `${tag} ${data.subject}`,
		status: 'draft',
	})
	ids[key] = uuidOf(created)
	if (status !== 'draft') {
		await update(ids[key], { status: 'effective' })
	}
	if (status === 'withdrawn') {
		await update(ids[key], { status: 'withdrawn', withdrawnBy: ids.decision })
	}
}

/**
 * The run's delegations an anonymous visitor finds on the public register.
 *
 * @return The status of the list call and the ids it returned.
 */
async function anonymousRegister(): Promise<{ status: number; found: string[] }> {
	const { status, results } = await listObjects(anon, SCHEMA, { _search: tag })
	return { found: results.map(uuidOf), status }
}

test.beforeAll(async ({ playwright }) => {
	admin = await adminActor(playwright)
	anon = await anonymousActor(playwright)

	ids.council = uuidOf(
		await createObject(admin, ledger, 'governance-body', {
			name: `${tag} council`,
			bodyType: 'legislative',
			domain: 'municipality',
		}),
	)
	ids.decision = uuidOf(
		await createObject(admin, ledger, 'decision', {
			title: `${tag} mandate decision`,
			text: 'The board grants the mandates recorded in the register.',
			decisionType: 'motion',
		}),
	)

	const yesterday = new Date(Date.now() - 86_400_000).toISOString()

	// In force today, published, and named twice as the town clerk's mandate.
	await delegation('inForce', 'effective', {
		subject: 'subsidy applications',
		delegateRole: 'town clerk',
		financialCeiling: 50000,
		subMandatePermitted: true,
		validFrom: dateFromToday(-30),
		validTo: dateFromToday(30),
		publicationDate: yesterday,
	})
	await delegation('openEnded', 'effective', {
		subject: 'parking permits',
		delegateRole: 'town clerk',
		validFrom: dateFromToday(-10),
	})
	// Not in force today: starts next week, ended last week, withdrawn, draft.
	await delegation('future', 'effective', {
		subject: 'future grants',
		delegateRole: 'head of finance',
		validFrom: dateFromToday(7),
	})
	await delegation('ended', 'effective', {
		subject: 'ended grants',
		delegateRole: 'head of finance',
		validFrom: dateFromToday(-60),
		validTo: dateFromToday(-7),
	})
	await delegation('withdrawn', 'withdrawn', {
		subject: 'withdrawn grants',
		delegateRole: 'head of finance',
		validFrom: dateFromToday(-30),
	})
	await delegation('draft', 'draft', {
		subject: 'unpublished draft',
		delegateRole: 'head of finance',
		validFrom: dateFromToday(-30),
	})
	// A sub-mandate under the published mandate.
	await delegation('subMandate', 'effective', {
		subject: 'small subsidy applications',
		delegateRole: 'team lead subsidies',
		parentAllocation: ids.inForce,
		validFrom: dateFromToday(-5),
	})
})

test.afterAll(async () => {
	await ledger.cleanup(admin)
	await disposeActors(admin, anon)
})

test('the in-force-on-date query returns exactly the delegations in force, as one list call', async () => {
	const today = dateFromToday(0)
	const { status, results } = await listObjects(admin, SCHEMA, {
		_search: tag,
		status: 'effective',
		'validFrom[lte]': today,
		'validTo[gte]': today,
		'validTo[orNull]': 'true',
	})
	expect(status).toBe(200)
	const found = results.map(uuidOf)

	for (const key of ['inForce', 'openEnded', 'subMandate']) {
		expect(found, `${key} is in force today`).toContain(ids[key])
	}
	for (const key of ['future', 'ended', 'withdrawn', 'draft']) {
		expect(found, `${key} is not in force today`).not.toContain(ids[key])
	}
})

test('filtering on the delegate lists only the delegations that name it', async () => {
	const { status, results } = await listObjects(admin, SCHEMA, {
		_search: tag,
		delegateRole: 'town clerk',
	})
	expect(status).toBe(200)
	expect(results.map(uuidOf).sort()).toEqual([ids.inForce, ids.openEnded].sort())
	const row = results.find((r: any) => uuidOf(r) === ids.inForce)
	expect(row.type).toBe('mandate')
	expect(row.financialCeiling).toBe(50000)
	expect(row.decision).toBe(ids.decision)
})

test('the register index lists the run and offers the mass export', async ({ page }) => {
	await page.goto(`${APP}/authority-delegations`)
	await expect(page.getByText(`${tag} subsidy applications`).first()).toBeVisible({
		timeout: 20_000,
	})
	await expect(
		page.getByRole('button', { name: /export/i }).first(),
	).toBeVisible()
})

test('a published delegation is readable anonymously; an unpublished one is not', async () => {
	const { status, found } = await anonymousRegister()
	expect(status).toBe(200)
	expect(found).toContain(ids.inForce)
	for (const key of ['openEnded', 'future', 'ended', 'withdrawn', 'draft', 'subMandate']) {
		expect(found, `${key} has no publication date`).not.toContain(ids[key])
	}

	const direct = await readObject(anon, SCHEMA, ids.inForce)
	expect(direct.status(), await describe(direct)).toBe(200)
	const body = await direct.json()
	expect(body.type).toBe('mandate')
	expect(body.delegateRole).toBe('town clerk')
	expect(body.status).toBe('effective')

	const hidden = await readObject(anon, SCHEMA, ids.draft)
	expect(hidden.status()).not.toBe(200)
})

test('an anonymous visitor cannot write to the register', async () => {
	const resp = await anon.ctx.put(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/${SCHEMA}/${ids.inForce}`,
		{ data: { status: 'lapsed' } },
	)
	expect(resp.status()).toBeGreaterThanOrEqual(400)
	expect((await current(ids.inForce)).status).toBe('effective')
})

test('the delegation detail shows its sub-mandate in the chain', async ({ page }) => {
	await page.goto(`${APP}/authority-delegations/${ids.inForce}`)
	const children = page.getByTestId('delegation-chain-children')
	await expect(children).toBeVisible({ timeout: 20_000 })
	await expect(children).toContainText(`${tag} small subsidy applications`)
	await expect(
		children.getByTestId('delegation-chain-child').first().locator('a, button').first(),
	).toBeVisible()
})

test('a decision taken under the mandate links to it and saves without any check', async ({ page }) => {
	const decision = await createObject(admin, ledger, 'decision', {
		title: `${tag} grant to the sports club`,
		text: 'The town clerk grants the subsidy.',
		decisionType: 'motion',
		authorityBasis: ids.inForce,
	})
	const id = uuidOf(decision)
	expect(decision.authorityBasis).toBe(ids.inForce)

	// Naming a withdrawn delegation, or none, is accepted the same way.
	const underWithdrawn = await createObject(admin, ledger, 'decision', {
		title: `${tag} taken under a withdrawn mandate`,
		text: 'Recorded for the archive.',
		decisionType: 'motion',
		authorityBasis: ids.withdrawn,
	})
	expect(underWithdrawn.authorityBasis).toBe(ids.withdrawn)

	await page.goto(`${APP}/decisions/${id}`)
	await expect(page.getByText(`${tag} subsidy applications`).first()).toBeVisible({
		timeout: 20_000,
	})

	await page.goto(`${APP}/authority-delegations/${ids.inForce}`)
	await expect(page.getByText(`${tag} grant to the sports club`).first()).toBeVisible({
		timeout: 20_000,
	})
})

test('withdrawing a published delegation shows on the next anonymous read', async () => {
	await update(ids.inForce, { status: 'withdrawn', withdrawnBy: ids.decision })

	const direct = await readObject(anon, SCHEMA, ids.inForce)
	expect(direct.status(), await describe(direct)).toBe(200)
	const body = await direct.json()
	expect(body.status).toBe('withdrawn')
	expect(body.withdrawnBy).toBe(ids.decision)
})

test('depublishing takes it off the public register', async () => {
	await update(ids.inForce, { depublicationDate: new Date(Date.now() - 60_000).toISOString() })
	const { found } = await anonymousRegister()
	expect(found).not.toContain(ids.inForce)
})
