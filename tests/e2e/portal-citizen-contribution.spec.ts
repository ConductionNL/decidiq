/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a resident's reaction travels from the portal into decidiq
 * and back (change portal-citizen-contribution-live-pass, REQ-DKPCA-005).
 *
 * The resident acts through portaliq's portal API with a low-trust citizen
 * session from portaliq's dev-login. The griffie acts through decidiq as the
 * administrator. What Mijn inspraak shows is the resident's own
 * `citizenReactions` collection, read here through the same portal API the
 * page reads.
 *
 * The instance needs what portaliq's own tests/e2e/ci-seed.sh sets up:
 * portaliq enabled with `dev_login_enabled = yes`, an OpenRegister
 * organisation with slug `dev-org` (the organisation dev-login signs in as),
 * and the administrator working in that organisation, so the consultations
 * created here belong to the portal's organisation (a portal session reads
 * and writes as its organisation only). Run live 25 Nov 2026 on Nextcloud 34
 * + PostgreSQL 16 with OpenRegister main and portaliq development.
 *
 * @spec openspec/specs/portal-citizen-create-actions/spec.md#requirement-a-residents-contribution-reaches-the-griffie-and-comes-back-on-mijn-inspraak-req-dkpca-005
 */

import type { APIRequestContext } from '@playwright/test'
import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	adminActor,
	appPost,
	createObject,
	daysFromNow,
	describe as describeResponse,
	disposeActors,
	listObjects,
	newRunId,
	ObjectLedger,
	uuidOf,
} from './support/api-actors.ts'

const RUN = newRunId()
const TAG = `e2e-portal-${RUN}`
const PORTAL = `${BASE}/index.php/apps/portaliq/portal/api`
const REACTIONS = `${PORTAL}/collections/decidiq/consultation-reaction`

const ledger = new ObjectLedger(['consultation-reaction', 'public-consultation'])
let admin: Actor

test.describe.configure({ mode: 'serial', timeout: 60_000 })

test.beforeAll(async ({ playwright }) => {
	admin = await adminActor(playwright)
	const probe = await admin.ctx.get(`${PORTAL}/contributions`)
	test.skip(probe.status() === 404, 'portaliq is not installed on this instance')
})

test.afterAll(async () => {
	if (admin) {
		await ledger.cleanup(admin)
		await disposeActors(admin)
	}
})

/**
 * A low-trust citizen session on the portal, as its bearer token.
 *
 * portaliq's dev-login is rate limited (10 a minute) and answers a tripped
 * limit with a 503, so each test mints one session.
 *
 * @param request    A request context.
 * @param subjectRef The resident this session speaks for.
 * @return The bearer token.
 */
async function residentToken(request: APIRequestContext, subjectRef: string): Promise<string> {
	const resp = await request.post(`${PORTAL}/session/dev-login`, {
		data: { audience: 'citizen', subjectRef },
	})
	expect(resp.ok(), 'portaliq dev-login needs portaliq dev_login_enabled = yes and the dev-org organisation (a 503 is its rate limit)').toBeTruthy()
	const token = (await resp.json()).token as string
	expect(token, 'dev-login returned no token').toBeTruthy()
	return token
}

/**
 * A consultation with the given status, created as the griffie.
 *
 * @param status open or closed.
 * @return Its id.
 */
async function consultation(status: 'open' | 'closed'): Promise<string> {
	const created = await createObject(admin, ledger, 'public-consultation', {
		moderationPolicy: 'pre-moderation',
		// Published: a resident only ever reacts on a consultation the
		// public may read, and the open-parent guard reads it as the public.
		publicationDate: daysFromNow(-1),
		status,
		submissionDeadline: daysFromNow(status === 'open' ? 14 : -1),
		title: `${TAG} Herinrichting Marktplein (${status})`,
	})
	return uuidOf(created)
}

/**
 * The resident's own reactions, as Mijn inspraak reads them.
 *
 * @param request A request context.
 * @param token   The resident's bearer token.
 * @return The reactions.
 */
async function myReactions(request: APIRequestContext, token: string): Promise<any[]> {
	const resp = await request.get(REACTIONS, { headers: { Authorization: `Bearer ${token}` } })
	expect(resp.ok(), `GET ${REACTIONS}: ${resp.status()}`).toBeTruthy()
	const body = await resp.json()
	return body.results ?? body.objects ?? body.items ?? []
}

// @e2e portal-citizen-create-actions::a-citizen-reacts-to-an-open-consultation
// @e2e portal-citizen-create-actions::a-resident-reacts-and-the-griffie-approves
test('a resident reacts on the portal, the griffie approves, and Mijn inspraak follows', async ({ request }) => {
	const consultationId = await consultation('open')
	const token = await residentToken(request, `${TAG}-jan-willem`)
	const text = `${TAG} Meer bomen op het plein, graag.`

	const submitted = await request.post(REACTIONS, {
		data: { body: text, consultation: consultationId },
		headers: { Authorization: `Bearer ${token}` },
	})
	expect(submitted.ok(), await describeResponse(submitted)).toBeTruthy()
	const reactionId = uuidOf((await submitted.json()).object)
	ledger.track('consultation-reaction', reactionId)

	// The griffie sees it on the consultation's moderation list, pending.
	const pending = await listObjects(admin, 'consultation-reaction', { _search: text })
	const onTheList = pending.results.filter((row) => row?.body === text)
	expect(onTheList, 'the reaction reached decidiq once').toHaveLength(1)
	expect(onTheList[0].moderationStatus).toBe('pending')
	expect(onTheList[0].consultation).toBe(consultationId)

	// Mijn inspraak lists it as in behandeling (pending).
	const before = (await myReactions(request, token)).filter((row) => row?.body === text)
	expect(before).toHaveLength(1)
	expect(before[0].moderationStatus).toBe('pending')

	// The griffie approves it; Mijn inspraak shows it approved.
	const approved = await appPost(admin, `/participation/reactions/${reactionId}/approve`)
	expect(approved.ok(), await describeResponse(approved)).toBeTruthy()
	const after = (await myReactions(request, token)).filter((row) => row?.body === text)
	expect(after).toHaveLength(1)
	expect(after[0].moderationStatus).toBe('approved')
})

// @e2e portal-citizen-create-actions::a-reaction-on-a-closed-consultation-is-refused-end-to-end
test('a reaction on a closed consultation is refused and nothing is created', async ({ request }) => {
	const consultationId = await consultation('closed')
	const token = await residentToken(request, `${TAG}-closed`)
	const text = `${TAG} Te laat.`

	const submitted = await request.post(REACTIONS, {
		data: { body: text, consultation: consultationId },
		headers: { Authorization: `Bearer ${token}` },
	})
	// 409 `refused`: the site reads it as "Dit kan voor dit item niet meer."
	// (portaliq site-forms REQ-ORR-001), not as a failure of the store.
	expect(submitted.status(), 'the portal refuses the reaction as no longer possible').toBe(409)
	expect(await submitted.json()).toEqual({ error: 'refused' })

	const { results } = await listObjects(admin, 'consultation-reaction', { _search: text })
	expect(results.filter((row) => row?.body === text), 'no reaction was created in decidiq').toHaveLength(0)
	expect((await myReactions(request, token)).filter((row) => row?.body === text)).toHaveLength(0)
})
