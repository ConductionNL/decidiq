# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: workflows/voting-quorum-workflow.spec.ts >> tally math — majority for → adopted
- Location: tests/e2e/workflows/voting-quorum-workflow.spec.ts:203:6

# Error details

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: 3
Received: 0
```

# Test source

```ts
  129 | 			votingMethod: 'for-against-abstain',
  130 | 			isSecret: false,
  131 | 		},
  132 | 	})
  133 | 
  134 | 	// The authorised chair must be allowed through (201), and a round must be created.
  135 | 	expect(resp.status(), `seeded chair must be allowed to open (got ${resp.status()})`).toBe(201)
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
> 229 | 		expect(Number(round?.votesFor)).toBe(c.expectedFor)
      |                                   ^ Error: expect(received).toBe(expected) // Object.is equality
  230 | 		expect(Number(round?.votesAgainst)).toBe(c.expectedAgainst)
  231 | 		expect(Number(round?.votesAbstain)).toBe(c.expectedAbstain)
  232 | 		expect(round?.result).toBe(c.expectedResult)
  233 | 	})
  234 | }
  235 | 
  236 | // @e2e openspec/specs/decision-management/spec.md#view-decision-detail-with-voting-results
  237 | // BUG-B: saveShowOfHandsTally() is typed `: array` but returns the ObjectEntity
  238 | // from saveObject() → TypeError 500. The exact expected tally math (for=5,
  239 | // against=2 → adopted) is asserted here and will pass once the return type is
  240 | // fixed to serialise the entity.
  241 | test('show-of-hands tally math — for=5 against=2 abstain=1 → adopted', async ({ page }) => {
  242 | 	const vr = await createObject(page, ledger, 'voting-round', {
  243 | 		votingMethod: 'show-of-hands',
  244 | 		isSecret: false,
  245 | 	})
  246 | 	const vrId = objId(vr)
  247 | 
  248 | 	const resp = await page.request.post(`${API}/voting-rounds/${vrId}/tally`, {
  249 | 		headers: await writeHeaders(page),
  250 | 		data: { votesFor: 5, votesAgainst: 2, votesAbstain: 1 },
  251 | 	})
  252 | 	expect(resp.status(), 'show-of-hands tally should not 500').toBe(200)
  253 | 	const round = await resp.json()
  254 | 	expect(Number(round.votesFor)).toBe(5)
  255 | 	expect(Number(round.votesAgainst)).toBe(2)
  256 | 	expect(Number(round.votesAbstain)).toBe(1)
  257 | 	expect(round.result).toBe('adopted')
  258 | })
  259 | 
  260 | // BUG-B (cast): castVote() has the same `: array` return-type defect — a real
  261 | // vote cast 500s before the tally can ever be computed from cast votes.
  262 | test('casting a vote returns the persisted vote (no return-type 500)', async ({ page }) => {
  263 | 	const s = await seedGovernanceScenario(page, ledger, {
  264 | 		quorumRequired: 0,
  265 | 		memberCount: 3,
  266 | 		chairIsAdmin: true,
  267 | 	})
  268 | 	// Even reaching cast requires a round; open is guarded, so this is doubly
  269 | 	// blocked today. Once open + cast are reachable, casting must return 201 with
  270 | 	// the persisted vote value.
  271 | 	const open = await page.request.post(`${API}/voting-rounds`, {
  272 | 		headers: await writeHeaders(page),
  273 | 		data: { motionId: s.motionId, meetingId: s.meetingId, votingMethod: 'for-against-abstain', isSecret: false },
  274 | 	})
  275 | 	expect(open.status()).toBe(201)
  276 | 	const round = await open.json()
  277 | 	const cast = await page.request.post(`${API}/voting-rounds/${objId(round)}/cast`, {
  278 | 		headers: await writeHeaders(page),
  279 | 		data: { value: 'for' },
  280 | 	})
  281 | 	expect(cast.status(), 'cast must not 500 on a return-type error').toBe(201)
  282 | 	expect((await cast.json()).value).toBe('for')
  283 | })
  284 | 
```