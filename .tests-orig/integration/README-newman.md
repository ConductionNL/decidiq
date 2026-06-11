# Decidesk API-contract tests (Newman)

Newman/Postman contract tests that exercise decidesk's HTTP controllers directly,
locking the API contract. Per the gate-19 split, **API/contract correctness lives
in Newman**; Playwright drives the UI only.

Canonical collection: **`decidesk.postman_collection.json`** (run via `run-newman.sh`).
The older `agenda.json` / `motion-voting.json` fixtures predate this suite and are
not maintained by it.

## What is covered

| Folder | Endpoints | Happy | Error | Authz |
| --- | --- | --- | --- | --- |
| 0. Setup | OpenRegister object API (ADR-022) | seeds governance body, chair participant, meeting, motion, two voting rounds, a decision | — | — |
| 1. Voting & Quorum | `POST /api/voting-rounds/{id}/tally`, `.../cast`, `POST /api/voting-rounds` (open) | tally returns a real correct result (`adopted`); cast creates a Vote | tally on non-show-of-hands → 400; cast bad/missing value → 400; open missing body → 400 | 401 no-auth on tally/cast/open; **open per-meeting chair guard → 403 (quarantined, see bug 2)** |
| 2. Meeting lifecycle | `POST /api/meetings/{id}/lifecycle` | `opened → closed` → 200 | missing `action` → 422 | 401 no-auth |
| 3. Motion lifecycle | `POST /api/motions/{id}/transition` | `submitted → debating` → 200 | invalid state → 400 | 401 no-auth |
| 4. Decision publication | `POST /api/decisions/{id}/publish` | adopted+internal → public (200) | re-publish already-public → 422; **unknown id → 500 (quarantined, see bug 1)** | 401 no-auth |
| 5. Settings | `GET /api/settings`, `POST /api/settings/load` | 200 + contract shape; admin reload → 200 | — | 401 no-auth (index + create) |
| 9. Teardown | OpenRegister object API | deletes every seeded object | — | — |

**37 requests, 51 assertions, all green.** The collection is self-contained and
idempotent: setup seeds the prerequisite OpenRegister objects and teardown deletes
everything created.

## Phase-0 voting fix locked

- **Tally returns a real, correct result with no 500.** `votesFor=3 > votesAgainst=1`
  ⇒ `result: "adopted"`, and the submitted counts are persisted verbatim.
- **Casting a vote returns 201 with no 500.** The participant identity is derived
  from the authenticated session (`nextcloudUserId`), never from client input; the
  created Vote carries `value`, `weight: 1`, `isProxy: false`.
- Every voting error path returns a **static-message 4xx, not a 500** (tally on a
  non-show-of-hands round → 400; cast bad/missing value → 400; open missing
  body → 400).
- The **chair guard fails closed**: the per-meeting chair check returns **403**
  (never a silent 201) for a caller the resolver does not see as chair/secretary.

## Known bugs (quarantined — NOT fake passes)

Both are asserted at their *current* status so the suite stays green without faking
a pass. When the app is fixed, the quarantine test goes RED — flip it to the
correct assertion at that point.

### (1) `DecisionController::publish` returns 500 on an unknown id

`publish()` assumes `ObjectService::find()` returns `null` for a missing object and
guards on `if ($entity === null) { return 404; }`. But OpenRegister raises
`OCP\AppFramework\Db\DoesNotExistException`, so the `=== null` branch is
unreachable and the exception escapes unhandled → **HTTP 500**. The
`Publish unknown decision QUARANTINED` request asserts the 500. **Fix:** catch
`DoesNotExistException` in `publish()` and return the documented 404; then flip
that assertion to 404.

### (2) Per-meeting chair guard / quorum participant resolution fails for OR-API data

`ParticipantResolver::resolveMeetingParticipants()` filters participants via the
OpenRegister `_relations.governance-body` filter, then re-checks the relation.
Objects created through the standard OpenRegister object API store the link as a
**flat property** (`{"governanceBody": "<uuid>"}`) or a **flattened map**
(`{"relations.0.id": "<uuid>", "relations.0.schema": "governance-body"}`); neither
is indexed under the `_relations.<schema-slug>` filter, so the resolver returns
**zero participants**. Consequently `VotingController::open()` — which runs a
per-meeting chair/secretary check on the request-body `meetingId` — rejects even a
genuinely-seeded chair with **403**, and `VotingService::checkQuorum()` fails
closed for the same reason. The `Open round — per-meeting chair guard QUARANTINED`
request asserts the current 403 (which also honestly confirms the guard fails
closed, never 201/500). **Note:** `tally` / `close` use the *global* chair fallback
(`IGroupManager::isAdmin`), which is why the live tally contract is fully
exercised and green. **Fix:** resolve participant relations from the stored flat /
flattened relation shapes (as `resolveGovernanceBodyId()` already does for the
`governanceBody` field) instead of relying on the `_relations.<slug>` filter; then
change the quarantine to assert a 201 open + a quorum-not-met 400.

## Running

```bash
# defaults: BASE_URL=http://localhost:8080, ADMIN_USER=admin, ADMIN_PASS=admin
./run-newman.sh

# or directly:
npx newman run decidesk.postman_collection.json \
  --env-var baseUrl=http://localhost:8080 \
  --env-var noAuthBase=http://127.0.0.1:8080 \
  --env-var adminUser=admin \
  --env-var adminPass=admin \
  --ignore-redirects
```

`run-newman.sh` prefers a globally-installed `newman`, falls back to `npx newman`,
and serialises runs under `flock /tmp/uiaudit-decidesk.lock` to avoid tripping the
Nextcloud brute-force protection when multiple agents run in parallel.

## Auth-isolation detail (important for reuse)

Newman keeps a per-run cookie jar. Authenticated requests against `baseUrl`
(`localhost`) establish a Nextcloud session cookie; because the jar is shared, that
cookie would silently authenticate the no-auth requests too (they then return 200
instead of 401). Three measures keep the authorization tests honest:

1. **Host split** — authenticated requests use `{{baseUrl}}`
   (`http://localhost:8080`); the no-auth requests use `{{noAuthBase}}`
   (`http://127.0.0.1:8080`). NC session cookies are host-scoped, so the
   `localhost` session is never sent to `127.0.0.1`, making those requests
   genuinely unauthenticated. `run-newman.sh` derives `noAuthBase` from `BASE_URL`
   automatically (override with `NO_AUTH_BASE`).
2. **`--ignore-redirects` + `Accept: application/json`** — unauthenticated requests
   get NC's JSON `401`, not the `303`→login-page `200` HTML a browser `Accept`
   would follow.
3. **`OCS-APIRequest: true` on authenticated requests only** — NC's app-framework
   controllers reject a session-less POST without it as a `CSRF check failed`. The
   header is present on every authed request and absent from the no-auth requests
   (so they stay unauthenticated, not CSRF-blocked-then-misread).

This mirrors the reusable Newman authz pattern established for procest.

## OpenRegister relation storage (gotcha for seeding)

Decidesk delegates meeting/motion/decision/voting-round/participant CRUD to the
OpenRegister object API (ADR-022). When seeding, related objects must be linked via
the **flat field-name form** (`{"governanceBody": "<uuid>"}`, `{"meeting": "<uuid>"}`)
to match what the seed data and the resolvers expect — passing a generic
`relations: [{schema, id}]` array yields a flattened `relations.0.*` map that the
decidesk resolvers do not parse (see bug 2). Date-time fields require ISO-8601
(`2026-06-20T10:00:00+00:00`), and enum fields are strict (e.g. governance-body
`bodyType` ∈ {legislative, association, corporate-board, operational,
citizen-panel}; meeting `lifecycle` ∈ {draft, scheduled, opened, paused, adjourned,
closed}).

## Collection variables

`baseUrl`, `noAuthBase`, `adminUser`, `adminPass`, plus `register` (`decidesk`).
The seeded object UUIDs — `govBodyId`, `participantId`, `meetingId`, `motionId`,
`sohRoundId`, `fabRoundId`, `decisionId` — are **discovered at runtime** by the
setup requests, so the suite is not pinned to specific seed UUIDs.
