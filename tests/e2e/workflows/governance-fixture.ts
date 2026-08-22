/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Seeded-fixture helper for the DEEP, data-dependent decidesk e2e layer.
 *
 * Creates and tears down the governance entities the workflow specs need —
 * GovernanceBody, Meeting (with quorumRequired), Participants (members for
 * quorum), Motion, Decision, VotingRound — through the OpenRegister object
 * REST API (`/index.php/apps/openregister/api/objects/decidesk/{schema}`)
 * using a Playwright-authenticated `APIRequestContext` so the calls carry the
 * admin session cookie + CSRF requesttoken.
 *
 * Every object created through this helper is tagged with a unique
 * `e2e-<runId>` prefix in its human-readable name field and its UUID is tracked
 * in a per-run ledger so `cleanupAll()` (driven from each spec's `afterAll`)
 * can delete exactly the objects this run created — never touching seed data.
 *
 * OR API verbs used are the REAL ones (find/findAll via GET, saveObject via
 * POST, deleteObject via DELETE). No invented endpoints.
 *
 * NOTE ON RELATIONS (deploy reality, 2026-06-10): OpenRegister materialises a
 * `governanceBody` / `meeting` scalar UUID property into `@self.relations` keyed
 * by the *property name*, but decidesk's ParticipantResolver filters
 * participants by the *schema slug* (`relations.governance-body`). In this
 * deployment neither linking form makes that filter match, so quorum /
 * chair-role resolution returns an empty member set. The workflow specs assert
 * the observable consequence of this rather than pretending it resolves.
 */
import type { Page } from '@playwright/test'

import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidesk`

export interface SeedLedger {
	runId: string
	/** schema slug -> created UUIDs (in creation order). */
	created: Record<string, string[]>
}

/**
 * The schema a "motion" is stored in.
 *
 * ADR-005 (accepted, 2026-06-14) folded the standalone `motion` and `amendment`
 * schemas into the single `Decision` supertype, discriminated by `decisionType`.
 * There is no `motion` schema in decidesk_register.json and addressing one
 * returns 404 "Schema not found: 'motion'".
 */
export const MOTION_SCHEMA = 'decision'

/**
 * Schema slugs in safe teardown order (children before parents).
 *
 * ⚠️ A SCHEMA MISSING FROM THIS LIST LEAKS SILENTLY. `cleanupAll()` iterates
 * THIS array, not `ledger.created`, so an object tracked under a slug that is
 * absent here is never deleted and `cleanupAll()` still returns cleanly.
 * `minutes` and `agenda-item` were both created by specs (resolution-minutes)
 * and both absent, so every CI run left them behind. Repeat offenders found
 * by the ux-debt-rendering fixture-pollution sweep (2026-08-19):
 * `board-evaluation` + `evaluation-template` (board-evaluation-workflow) and
 * `consultation-reaction` + `participatory-budget` + `budget-proposal`
 * (citizen-participation-workflow) were all created via `createObject()` but
 * absent from this array — every run of those two workflow specs leaked
 * those objects onto the shared instance. `board-evaluation` and
 * `budget-proposal` are ordered ahead of the parents they reference
 * (`governance-body` / `evaluation-template` and `participatory-budget`
 * respectively) so the child rows delete first.
 */
const TEARDOWN_ORDER = [
	'vote',
	'voting-round',
	'decision',
	'minutes',
	'agenda-item',
	'board-evaluation',
	'consultation-reaction',
	'budget-proposal',
	'evaluation-template',
	'participatory-budget',
	'participant',
	'meeting',
	'governance-body',
]

export function newLedger(): SeedLedger {
	const runId = `${Date.now()}-${Math.floor(Math.random() * 1e4)}`
	return { runId, created: {} }
}

async function requesttoken(page: Page): Promise<string> {
	const r = await page.request.get(`${BASE}/index.php/csrftoken`)
	const body = await r.json()
	return body.token
}

/** Build the auth headers (CSRF requesttoken) for a write call. */
export async function writeHeaders(page: Page): Promise<Record<string, string>> {
	return {
		'Content-Type': 'application/json',
		requesttoken: await requesttoken(page),
	}
}

/** Pull the UUID out of an OR object response (covers both id shapes). */
function objId(o: any): string {
	return o?.id ?? o?.['@self']?.id ?? o?.uuid ?? ''
}

function track(ledger: SeedLedger, schema: string, id: string): void {
	if (!id) return
	;(ledger.created[schema] ??= []).push(id)
}

/** Create one OR object and record it in the ledger. Throws on non-2xx. */
export async function createObject(
	page: Page,
	ledger: SeedLedger,
	schema: string,
	data: Record<string, unknown>,
): Promise<any> {
	const headers = await writeHeaders(page)
	const resp = await page.request.post(`${OR}/${schema}`, { headers, data })
	if (resp.status() >= 300) {
		throw new Error(
			`createObject(${schema}) failed ${resp.status()}: ${await resp.text()}`,
		)
	}
	const obj = await resp.json()
	track(ledger, schema, objId(obj))
	return obj
}

/** GET a single OR object by id. Returns null on 404. */
export async function getObject(
	page: Page,
	schema: string,
	id: string,
): Promise<any | null> {
	const resp = await page.request.get(`${OR}/${schema}/${id}`, {
		headers: { Accept: 'application/json' },
	})
	if (resp.status() === 404) return null
	if (!resp.ok()) return null
	return resp.json()
}

/** List OR objects for a schema (best-effort, limited). */
export async function listObjects(
	page: Page,
	schema: string,
	limit = 200,
): Promise<any[]> {
	const resp = await page.request.get(`${OR}/${schema}?_limit=${limit}`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) return []
	const body = await resp.json()
	return body.results ?? body.items ?? []
}

/** Delete one OR object (used by tests that delete through the API for setup). */
export async function deleteObject(
	page: Page,
	schema: string,
	id: string,
): Promise<number> {
	const headers = await writeHeaders(page)
	const resp = await page.request.delete(`${OR}/${schema}/${id}`, { headers })
	return resp.status()
}

/**
 * Seed a complete governance scenario:
 *   - 1 GovernanceBody
 *   - 1 Meeting (linked to the body, with the given quorumRequired + lifecycle)
 *   - N Participants (linked to the body); the first is the admin chair
 *   - 1 Motion (linked to the meeting, lifecycle = debating)
 *
 * Returns the created ids. `memberCount` participants are created; `chairIsAdmin`
 * makes participant #0 carry nextcloudUserId=admin + role=chair so the
 * logged-in admin maps to that participant for vote-casting / chair checks.
 */
export async function seedGovernanceScenario(
	page: Page,
	ledger: SeedLedger,
	opts: {
		quorumRequired?: number
		memberCount?: number
		chairIsAdmin?: boolean
		meetingLifecycle?: string
	} = {},
): Promise<{
	governanceBodyId: string
	meetingId: string
	motionId: string
	participantIds: string[]
}> {
	const {
		quorumRequired = 0,
		memberCount = 3,
		chairIsAdmin = true,
		meetingLifecycle = 'opened',
	} = opts
	const tag = `e2e-${ledger.runId}`

	const gb = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-body`,
		bodyType: 'legislative',
		domain: `${tag} council`,
	})
	const governanceBodyId = objId(gb)

	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-meeting`,
		meetingType: 'regular',
		scheduledDate: '2026-09-01T10:00:00Z',
		meetingMode: 'in-person',
		lifecycle: meetingLifecycle,
		quorumRequired,
		governanceBody: governanceBodyId,
	})
	const meetingId = objId(meeting)

	const participantIds: string[] = []
	for (let i = 0; i < memberCount; i++) {
		const isChairAdmin = chairIsAdmin && i === 0
		const data: Record<string, unknown> = {
			displayName: `${tag}-member-${i}`,
			role: isChairAdmin ? 'chair' : 'member',
			governanceBody: governanceBodyId,
			attendanceStatus: 'present',
		}
		if (isChairAdmin) data.nextcloudUserId = 'admin'
		const p = await createObject(page, ledger, 'participant', data)
		participantIds.push(objId(p))
	}

	// ADR-005 (accepted): `Decision` is the universal supertype. The standalone
	// `motion` and `amendment` schemas were REMOVED from decidesk_register.json —
	// a motion is a Decision carrying `decisionType: 'motion'`. Seeding schema
	// `motion` therefore 404s with "Schema not found: 'motion'", which is what
	// every test in this fixture's dependants was failing on.
	//
	// Decision.required[] is [title, text, decisionType] — those three must be
	// present or the POST is a 400 naming the missing ones. `decisionDate` and
	// `outcome` are NOT unconditionally required (an in-flight motion has no
	// outcome); they are enforced at the transition boundary instead, when the
	// decision enters decided/enacted/archived. This fixture supplies them
	// anyway so the seeded motion can be driven all the way to a terminal state.
	// `lifecycle` is [draft, proposed, deliberating, voting, decided, enacted,
	// archived, withdrawn]: there is no 'debating'. And Decision has NO `meeting`
	// property (the AgendaItem carries that link), so passing one invents a field.
	const motion = await createObject(page, ledger, MOTION_SCHEMA, {
		decisionType: 'motion',
		title: `${tag}-motion`,
		text: `${tag} motion body text`,
		decisionDate: '2026-09-01T10:00:00Z',
		outcome: 'adopted',
		motionType: 'motion',
		proposer: `${tag}-proposer`,
		lifecycle: 'deliberating',
		submittedAt: '2026-09-01T10:00:00Z',
	})
	const motionId = objId(motion)

	return { governanceBodyId, meetingId, motionId, participantIds }
}

/**
 * How many deletes are in flight at once.
 *
 * Cleanup used to issue every DELETE serially — one full HTTP round-trip per
 * object, plus six listObjects() sweeps, all inside an afterAll hook that
 * inherits the 20s test timeout. On CI that hook timed out, and because a
 * failing afterAll is attributed to the LAST test in the file it kept
 * re-accusing whichever spec happened to sit at the bottom
 * (voting-quorum-workflow: first the tally-tie case, then "casting a vote
 * returns the persisted vote"). Neither test was broken; the teardown was.
 *
 * Deleting in bounded batches keeps the wall time proportional to
 * ceil(n / CLEANUP_CONCURRENCY) round-trips instead of n, which is what puts
 * teardown back inside the budget. The budget itself is UNCHANGED — nothing
 * here relaxes a timeout or an assertion; the work simply stops being serial.
 *
 * Bounded rather than unbounded (`Promise.all` over everything) on purpose: an
 * unbounded fan-out against a single-worker `php -S` CI server trades a slow
 * teardown for a connection-starved one.
 */
const CLEANUP_CONCURRENCY = 8

/** Issue DELETEs in bounded-concurrency batches. Best-effort, never throws. */
async function deleteAll(
	page: Page,
	urls: string[],
	headers: Record<string, string>,
): Promise<void> {
	for (let i = 0; i < urls.length; i += CLEANUP_CONCURRENCY) {
		await Promise.all(
			urls
				.slice(i, i + CLEANUP_CONCURRENCY)
				.map((url) =>
					page.request.delete(url, { headers }).catch(() => undefined),
				),
		)
	}
}

/** Delete every object this run created, children first. Best-effort. */
export async function cleanupAll(page: Page, ledger: SeedLedger): Promise<void> {
	const headers = await writeHeaders(page)

	// Children first: TEARDOWN_ORDER is a dependency order, so the batches stay
	// per-schema. Parallelism WITHIN a schema is safe; across schemas it is not.
	for (const schema of TEARDOWN_ORDER) {
		const ids = ledger.created[schema] ?? []
		await deleteAll(
			page,
			ids.map((id) => `${OR}/${schema}/${id}`),
			headers,
		)
	}

	// Sweep any stragglers tagged with this runId (e.g. votes/rounds created by
	// the app under test, which the ledger never saw).
	const tag = `e2e-${ledger.runId}`
	const sweepSchemas = [
		'vote',
		'voting-round',
		'decision',
		'board-evaluation',
		'consultation-reaction',
		'budget-proposal',
		'evaluation-template',
		'participatory-budget',
		'participant',
		'meeting',
		'governance-body',
	]

	// The six listObjects() reads have no ordering constraint between them —
	// only the deletes do — so they run concurrently and the per-schema deletes
	// then proceed in dependency order as before.
	const listings = await Promise.all(
		sweepSchemas.map(async (schema) => ({
			schema,
			objs: await listObjects(page, schema),
		})),
	)

	for (const { schema, objs } of listings) {
		const urls = objs
			.filter((o) =>
				String(
					(o.title ?? o.name ?? o.displayName ?? '') as string,
				).startsWith(tag),
			)
			.map((o) => `${OR}/${schema}/${objId(o)}`)
		await deleteAll(page, urls, headers)
	}
}

export { BASE, objId, OR }
