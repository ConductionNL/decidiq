# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: workflows/voting-quorum-workflow.spec.ts >> open voting round succeeds for a seeded meeting chair (guard resolves the chair)
- Location: tests/e2e/workflows/voting-quorum-workflow.spec.ts:117:5

# Error details

```
Error: seeded chair must be allowed to open (got 403)

expect(received).toBe(expected) // Object.is equality

Expected: 201
Received: 403
```

# Test source

```ts
  35  |  *          returns ObjectEntity, never array/null) — a TypeError 500 on every
  36  |  *          show-of-hands tally and every real vote cast.
  37  |  *
  38  |  * @e2e openspec/specs/meeting-management/spec.md#quorum-not-met-meeting-cannot-proceed-to-voting
  39  |  * @e2e openspec/specs/decision-management/spec.md#transition-a-decision-from-draft-to-proposed
  40  |  */
  41  | import { test, expect } from '@playwright/test'
  42  | import {
  43  | 	BASE,
  44  | 	newLedger,
  45  | 	seedGovernanceScenario,
  46  | 	createObject,
  47  | 	getObject,
  48  | 	listObjects,
  49  | 	writeHeaders,
  50  | 	cleanupAll,
  51  | 	objId,
  52  | 	type SeedLedger,
  53  | } from './governance-fixture'
  54  | 
  55  | const API = `${BASE}/index.php/apps/decidesk/api`
  56  | 
  57  | let ledger: SeedLedger
  58  | 
  59  | test.beforeAll(() => {
  60  | 	ledger = newLedger()
  61  | })
  62  | 
  63  | test.beforeEach(async ({ page }) => {
  64  | 	await page.goto(`${BASE}/apps/decidesk/`)
  65  | 	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
  66  | })
  67  | 
  68  | test.afterAll(async ({ browser }) => {
  69  | 	const page = await browser.newPage()
  70  | 	await cleanupAll(page, ledger)
  71  | 	await page.close()
  72  | })
  73  | 
  74  | // ── AUTHORIZATION GUARD: open is chair-gated and fail-CLOSED (REAL, green) ────
  75  | 
  76  | // @e2e openspec/specs/meeting-management/spec.md#quorum-not-met-meeting-cannot-proceed-to-voting
  77  | // The per-meeting chair/secretary guard MUST block an unauthorized open. This
  78  | // asserts the guard is wired and fail-CLOSED (not fail-open): the caller is not
  79  | // a resolvable chair/secretary of the meeting, so open is rejected with 403 and
  80  | // NO voting-round is persisted.
  81  | test('open voting round is blocked (403) when caller is not a meeting chair/secretary', async ({ page }) => {
  82  | 	// Seed a meeting whose only members are plain members — the admin caller is
  83  | 	// NOT a chair/secretary of this body.
  84  | 	const s = await seedGovernanceScenario(page, ledger, {
  85  | 		quorumRequired: 0,
  86  | 		memberCount: 2,
  87  | 		chairIsAdmin: false,
  88  | 	})
  89  | 
  90  | 	const before = (await listObjects(page, 'voting-round')).length
  91  | 
  92  | 	const resp = await page.request.post(`${API}/voting-rounds`, {
  93  | 		headers: await writeHeaders(page),
  94  | 		data: {
  95  | 			motionId: s.motionId,
  96  | 			meetingId: s.meetingId,
  97  | 			votingMethod: 'for-against-abstain',
  98  | 			isSecret: false,
  99  | 		},
  100 | 	})
  101 | 
  102 | 	// Guard must reject — fail CLOSED, not open.
  103 | 	expect(resp.status(), 'unauthorized open must be 403').toBe(403)
  104 | 	const body = await resp.json()
  105 | 	expect(body.message).toMatch(/chair or secretary/i)
  106 | 
  107 | 	// And crucially: no voting-round leaked into the store.
  108 | 	const after = (await listObjects(page, 'voting-round')).length
  109 | 	expect(after, 'no voting-round may be created when the guard blocks').toBe(before)
  110 | })
  111 | 
  112 | // With the relation filter fixed (BUG-A), a participant seeded as chair (role=chair,
  113 | // nextcloudUserId=admin) now resolves correctly, so the per-meeting guard ALLOWS the
  114 | // authorised chair to open the round. Combined with the fail-CLOSED test above (a
  115 | // non-chair caller is rejected with 403), this proves the guard resolves the real
  116 | // chair role rather than blanket-blocking or blanket-allowing.
  117 | test('open voting round succeeds for a seeded meeting chair (guard resolves the chair)', async ({ page }) => {
  118 | 	const s = await seedGovernanceScenario(page, ledger, {
  119 | 		quorumRequired: 0,
  120 | 		memberCount: 3,
  121 | 		chairIsAdmin: true,
  122 | 	})
  123 | 
  124 | 	const resp = await page.request.post(`${API}/voting-rounds`, {
  125 | 		headers: await writeHeaders(page),
  126 | 		data: {
  127 | 			motionId: s.motionId,
  128 | 			meetingId: s.meetingId,
  129 | 			votingMethod: 'for-against-abstain',
  130 | 			isSecret: false,
  131 | 		},
  132 | 	})
  133 | 
  134 | 	// The authorised chair must be allowed through (201), and a round must be created.
> 135 | 	expect(resp.status(), `seeded chair must be allowed to open (got ${resp.status()})`).toBe(201)
      |                                                                                       ^ Error: seeded chair must be allowed to open (got 403)
  136 | 	const round = await resp.json()
  137 | 	expect(objId(round), 'an opened round must be returned').toBeTruthy()
  138 | })
  139 | 
  140 | // ── QUORUM enforcement (REAL where reachable): no round persists when blocked ─
  141 | 
  142 | // @e2e openspec/specs/meeting-management/spec.md#quorum-not-met-meeting-cannot-proceed-to-voting
  143 | // Whether the rejection comes from the chair guard or the quorum check, the
  144 | // invariant under test is the same and security-relevant: a meeting that cannot
  145 | // satisfy its preconditions MUST NOT produce a voting round. We seed a high
  146 | // quorum requirement that the (broken-resolution) member set can never meet and
  147 | // assert open is rejected and nothing is persisted.
  148 | test('quorum-not-met / preconditions-unmet meeting cannot open a voting round', async ({ page }) => {
  149 | 	const s = await seedGovernanceScenario(page, ledger, {
  150 | 		quorumRequired: 99, // impossible to meet
  151 | 		memberCount: 3,
  152 | 		chairIsAdmin: true,
  153 | 	})
  154 | 
  155 | 	const before = (await listObjects(page, 'voting-round')).length
  156 | 	const resp = await page.request.post(`${API}/voting-rounds`, {
  157 | 		headers: await writeHeaders(page),
  158 | 		data: {
  159 | 			motionId: s.motionId,
  160 | 			meetingId: s.meetingId,
  161 | 			votingMethod: 'for-against-abstain',
  162 | 			isSecret: false,
  163 | 		},
  164 | 	})
  165 | 
  166 | 	expect(resp.status(), 'open must be rejected when preconditions are unmet').toBeGreaterThanOrEqual(400)
  167 | 	const after = (await listObjects(page, 'voting-round')).length
  168 | 	expect(after, 'no voting-round may be created for a quorum-blocked meeting').toBe(before)
  169 | 
  170 | 	// The motion must NOT have been advanced to "voting" by a blocked open.
  171 | 	const motion = await getObject(page, 'motion', s.motionId)
  172 | 	expect(motion?.lifecycle, 'motion lifecycle must not advance on a blocked open').not.toBe('voting')
  173 | })
  174 | 
  175 | // ── TALLY MATH correctness — exact inputs → expected outcomes ─────────────────
  176 | //
  177 | // These document the canonical tally rules with concrete inputs and the exact
  178 | // expected computed outcome, driven through close() (which runs tallyResults).
  179 | // Currently blocked by BUG-A (votes are linked but `relations.voting-round`
  180 | // matches zero rows, so the count is always 0/0/0 → "invalid") — so they are
  181 | // marked test.fixme. Un-fixme once the relation filter resolves.
  182 | 
  183 | interface TallyCase {
  184 | 	name: string
  185 | 	votes: Array<'for' | 'against' | 'abstain'>
  186 | 	expectedFor: number
  187 | 	expectedAgainst: number
  188 | 	expectedAbstain: number
  189 | 	expectedResult: 'adopted' | 'rejected' | 'tied' | 'invalid'
  190 | }
  191 | 
  192 | const TALLY_CASES: TallyCase[] = [
  193 | 	{ name: 'majority for → adopted', votes: ['for', 'for', 'for', 'against', 'abstain'], expectedFor: 3, expectedAgainst: 1, expectedAbstain: 1, expectedResult: 'adopted' },
  194 | 	{ name: 'majority against → rejected', votes: ['against', 'against', 'against', 'for'], expectedFor: 1, expectedAgainst: 3, expectedAbstain: 0, expectedResult: 'rejected' },
  195 | 	{ name: 'equal for/against → tied (abstain excluded from the comparison)', votes: ['for', 'for', 'against', 'against', 'abstain'], expectedFor: 2, expectedAgainst: 2, expectedAbstain: 1, expectedResult: 'tied' },
  196 | ]
  197 | 
  198 | for (const c of TALLY_CASES) {
  199 | 	// @e2e openspec/specs/decision-management/spec.md#view-decision-detail-with-voting-results
  200 | 	// BUG-A: votes are linked to the round but tallyResults() filters with
  201 | 	// `relations.voting-round` which matches 0 rows in this deployment, so the
  202 | 	// computed tally is always 0/0/0 = invalid regardless of the votes cast.
  203 | 	test(`tally math — ${c.name}`, async ({ page }) => {
  204 | 		// Orphan round (no motion link) so close()'s auth falls back to the
  205 | 		// global-admin path and the round is actually closable.
  206 | 		const vr = await createObject(page, ledger, 'voting-round', {
  207 | 			votingMethod: 'for-against-abstain',
  208 | 			isSecret: false,
  209 | 			openedAt: '2026-09-01T10:00:00Z',
  210 | 		})
  211 | 		const vrId = objId(vr)
  212 | 
  213 | 		for (const value of c.votes) {
  214 | 			await createObject(page, ledger, 'vote', {
  215 | 				value,
  216 | 				weight: 1,
  217 | 				castAt: '2026-09-01T10:05:00Z',
  218 | 				relations: [{ register: 'decidesk', schema: 'voting-round', id: vrId }],
  219 | 			})
  220 | 		}
  221 | 
  222 | 		const resp = await page.request.post(`${API}/voting-rounds/${vrId}/close`, {
  223 | 			headers: await writeHeaders(page),
  224 | 			data: {},
  225 | 		})
  226 | 		expect(resp.status()).toBe(200)
  227 | 
  228 | 		const round = await getObject(page, 'voting-round', vrId)
  229 | 		expect(Number(round?.votesFor)).toBe(c.expectedFor)
  230 | 		expect(Number(round?.votesAgainst)).toBe(c.expectedAgainst)
  231 | 		expect(Number(round?.votesAbstain)).toBe(c.expectedAbstain)
  232 | 		expect(round?.result).toBe(c.expectedResult)
  233 | 	})
  234 | }
  235 | 
```