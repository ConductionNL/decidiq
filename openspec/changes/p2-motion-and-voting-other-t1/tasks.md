## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm no custom CRUD, export, search, file, notification, or audit code is needed: all use `ObjectService`, `NotificationService`, `ActivityService`, `AuthorizationService` from OpenRegister platform
- [ ] 0.2 Confirm `VotingRound`, `Vote`, `Motion`, and `GovernanceBody` entities are used as-is from ADR-000 — no schema properties added or renamed; `Vote.value` JSON-encoding is a valid use of the existing string field; `GovernanceBody.workflowTemplate` stores JSON in the existing string field
- [ ] 0.3 Confirm `SecretBallotGuard` does not duplicate `AuthorizationService` — the guard masks response data (not access control); `AuthorizationService` controls who can call the endpoint (orthogonal concerns)
- [ ] 0.4 Confirm `WorkflowConfigService` does not duplicate `WorkflowEngineController` from OpenRegister — OR workflow engine is a full BPMN engine; this service reads a lightweight JSON config from a single field; no overlap

## 1. Backend — SecretBallotGuard

- [ ] 1.1 Create `lib/Guard/SecretBallotGuard.php` tagged `@spec openspec/changes/p2-motion-and-voting-other-t1/tasks.md#task-1`:
  - Constructor injects `ObjectService` and `IAppConfig`
  - `maskVoteResponse(array $vote, string $votingRoundId): array` — fetches VotingRound via `ObjectService.findObject()`, checks `isSecret`; if true, replaces `vote['value']` with `"anonymous"` and removes the Participant relation from the response array; if false, returns unchanged
  - `maskVoteListResponse(array $votes, string $votingRoundId): array` — applies `maskVoteResponse()` to each item in the list
- [ ] 1.2 Inject `SecretBallotGuard` into `VotingController` (existing from p2-motion-and-voting); apply `maskVoteListResponse()` on `GET /api/votes` and `maskVoteResponse()` on `GET /api/votes/{id}` responses
- [ ] 1.3 Extend `VotingService::castVote()` to check `VotingRound.isSecret` and pass `includeValue: false` to the `ActivityService` call when true (audit trail logs participation without vote direction)
- [ ] 1.4 Register `SecretBallotGuard` in DI container (`lib/AppInfo/Application.php`)
- [ ] 1.5 Write PHPUnit tests in `tests/Unit/Guard/SecretBallotGuardTest.php` covering: `maskVoteResponse` on secret round hides value; `maskVoteResponse` on open round returns value unchanged; `maskVoteListResponse` applies masking to all items

## 2. Backend — WorkflowConfigService and MotionService extension

- [ ] 2.1 Create `lib/Service/WorkflowConfigService.php` tagged `@spec openspec/changes/p2-motion-and-voting-other-t1/tasks.md#task-2`:
  - `getConfig(string $governanceBodyId): array` — fetches GovernanceBody via `ObjectService.findObject()`, JSON-decodes `workflowTemplate`; returns decoded array or platform defaults if field is null/invalid
  - `getPermittedMotionTypes(string $governanceBodyId): array` — returns `config['permittedMotionTypes']` or `['motion','amendment','order','procedural']`
  - `getPermittedTransitions(string $governanceBodyId, string $fromState): array` — returns `config['transitions'][$fromState]` or platform defaults
  - `getMajorityRule(string $governanceBodyId): string` — returns `config['majorityRule']` or `'simple'`
  - `saveConfig(string $governanceBodyId, array $config): void` — JSON-encodes `$config`, saves to `GovernanceBody.workflowTemplate` via `ObjectService.saveObject()`
- [ ] 2.2 Extend `MotionService::transitionLifecycle()` to call `WorkflowConfigService::getPermittedTransitions()` and throw a `400` error if the requested transition is not listed
- [ ] 2.3 Extend `VotingService::tallyResults()` to call `WorkflowConfigService::getMajorityRule()` and apply the correct majority calculation (`simple`, `absolute`, `qualified-two-thirds`)
- [ ] 2.4 Add `POST /api/governance-bodies/{id}/workflow-config` controller endpoint — calls `WorkflowConfigService::saveConfig()`; requires admin; registered in `appinfo/routes.php`
- [ ] 2.5 Add `GET /api/governance-bodies/{id}/workflow-config` endpoint — calls `WorkflowConfigService::getConfig()` and returns the decoded config object
- [ ] 2.6 Register `WorkflowConfigService` in DI container
- [ ] 2.7 Write PHPUnit tests in `tests/Unit/Service/WorkflowConfigServiceTest.php` covering: `getPermittedMotionTypes` with valid and empty template; `getPermittedTransitions` allowed and denied; `getMajorityRule` simple/absolute/two-thirds; `tallyResults` with each majority rule variant

## 3. Backend — VotingService recount extension

- [ ] 3.1 Add `recount(string $votingRoundId, string $actorId): array` to `VotingService` tagged `@spec openspec/changes/p2-motion-and-voting-other-t1/tasks.md#task-3`:
  - Fetches VotingRound; throws `400` if not closed; throws `409` if note with `title: "Hertelverzoek"` already exists
  - Fetches all Vote objects via `ObjectService.findAll()` and re-tallies by value
  - Compares to stored `votesFor`, `votesAgainst`, `votesAbstain`
  - If discrepancy: sets `VotingRound.result` to `"disputed"`, creates note `title: "Hertelverzoek"` with JSON body of original vs recount counts; saves via `ObjectService.saveObject()`
  - If no discrepancy: creates note `title: "Hertelverzoek"` with body `{"discrepancy": false}`
  - Logs to `ActivityService`; returns comparison array
- [ ] 3.2 Add `resolveRecount(string $votingRoundId, string $finalResult, int $votesFor, int $votesAgainst, int $votesAbstain, string $actorId): void` to `VotingService`:
  - Fetches VotingRound; throws `400` if not `"disputed"`
  - Updates `result`, `votesFor`, `votesAgainst`, `votesAbstain` via `ObjectService.saveObject()`
  - Logs resolution to `ActivityService`
- [ ] 3.3 Add controller endpoints in `VotingController`:
  - `POST /api/voting-rounds/{id}/recount` → `VotingService::recount()`; requires chair or secretary role
  - `POST /api/voting-rounds/{id}/recount-resolve` → `VotingService::resolveRecount()`; requires chair or secretary role
- [ ] 3.4 Register new routes in `appinfo/routes.php`
- [ ] 3.5 Write PHPUnit tests in `tests/Unit/Service/VotingServiceRecountTest.php` covering: `recount` on open round returns 400; `recount` detects discrepancy and sets disputed; `recount` no discrepancy leaves result unchanged; `recount` duplicate request returns 409; `resolveRecount` on non-disputed round returns 400; `resolveRecount` updates counts and result

## 4. Backend — Ballot distribution extension to VotingService

- [ ] 4.1 Extend `VotingService::openVotingRound()` to call `NotificationService` after the VotingRound is created tagged `@spec openspec/changes/p2-motion-and-voting-other-t1/tasks.md#task-4`:
  - Fetch active Participants via `ObjectService.findAll()` filtered by GovernanceBody relation and `endDate: null`
  - For each Participant: `NotificationService::notify($participantUserId, 'decidesk', 'vote-invitation', $motionTitle, $deepLinkUrl)`
  - Deep-link URL: `generateUrl('/apps/decidesk/motions/' . $motionId)` (path format per ADR-004)
- [ ] 4.2 Create `GET /api/voting-rounds/{id}/distribution` endpoint in `VotingController`:
  - Counts active Participants (invited): same query as 4.1
  - Counts cast Votes via `ObjectService.findAll()` with VotingRound relation
  - Returns `{ "invited": N, "voted": M }` — no vote values, no participant names
- [ ] 4.3 Add `POST /api/voting-rounds/{id}/remind` endpoint in `VotingController` → new `VotingService::sendReminders()` method:
  - Fetches active Participants; fetches cast Votes; computes non-voting Participants (set difference)
  - Calls `NotificationService::notify()` for each non-voter
  - Returns `{ "reminded": N }`; requires chair or secretary role
- [ ] 4.4 Register new routes in `appinfo/routes.php`
- [ ] 4.5 Write PHPUnit tests in `tests/Unit/Service/VotingServiceDistributionTest.php` covering: `openVotingRound` calls NotificationService for each active Participant; inactive Participants excluded; `sendReminders` notifies only non-voters; `sendReminders` by member returns 403

## 5. Backend — Preferential ballot extension to VotingService

- [ ] 5.1 Extend `VotingService::tallyResults()` with ranked-choice branch tagged `@spec openspec/changes/p2-motion-and-voting-other-t1/tasks.md#task-5`:
  - If `VotingRound.votingMethod === "ranked-choice"`: fetch all Vote objects; JSON-decode each `vote.value` as ordered array of candidates; apply Borda count (N-1 points for rank 1, ..., 0 for last); sum points per candidate; set `VotingRound.result` to winner identifier or `"tied"` if top scores equal; create note with full point table as JSON
  - If `votingMethod !== "ranked-choice"`: existing for/against/abstain path unchanged
- [ ] 5.2 Extend `VotingService::castVote()` to validate ranked-choice vote format: if `VotingRound.votingMethod === "ranked-choice"`, decode `value` as JSON array; verify all candidates are present exactly once; throw `400` if partial or duplicate
- [ ] 5.3 Write PHPUnit tests in `tests/Unit/Service/VotingServiceRankedTest.php` covering: Borda count with known votes; tie detection; winner stored in result; partial ranking rejected; non-ranked method unchanged

## 6. Frontend — Secret ballot UI

- [ ] 6.1 Extend `VotingRoundPanel.vue` (from p2-motion-and-voting): when `votingRound.isSecret === true`, replace per-participant vote table with a neutral message `t('decidesk', 'Your vote has been recorded anonymously')` after casting; display `mdi-lock` icon next to round title; render `CnStatusBadge` "Geheime stemming"
- [ ] 6.2 Ensure `VotingRoundPanel` polls for tally but only displays aggregate counts when `isSecret: true` — no per-voter breakdown rendered even if the API accidentally returns data (defence in depth)
- [ ] 6.3 Add SPDX header `<!-- SPDX-License-Identifier: EUPL-1.2 -->` to any modified/new Vue files

## 7. Frontend — Vote review and recount UI

- [ ] 7.1 Add "Hertelverzoek indienen" button to `VotingRoundPanel.vue` — visible to chair/secretary only when `VotingRound.closedAt` is set and `result !== "disputed"`; on click shows `NcDialog` confirmation; on confirm calls `POST /api/voting-rounds/{id}/recount`; shows result toast
- [ ] 7.2 Add orange `NcNoteCard` warning banner to `VotingRoundPanel.vue` — rendered when `votingRound.result === "disputed"` with text `t('decidesk', 'Vote result disputed — recount in progress')`; shows "Hertelresultaat vaststellen" button for chair/secretary that opens a resolve dialog
- [ ] 7.3 Create `RecountResolveDialog.vue` — dialog with fields: `finalResult` (dropdown: adopted/rejected/tied), `votesFor` (number), `votesAgainst` (number), `votesAbstain` (number); on confirm calls `POST /api/voting-rounds/{id}/recount-resolve`; import `NcDialog` from `@conduction/nextcloud-vue`
- [ ] 7.4 Add SPDX headers to all new Vue files

## 8. Frontend — Real-time ballot distribution UI

- [ ] 8.1 Extend `VotingRoundPanel.vue` to poll `GET /api/voting-rounds/{id}/distribution` every 5 seconds for open rounds and display "Uitgenodigd: X / Gestemd: Y" counter using `CnStatsBlock` or inline text beneath the tally
- [ ] 8.2 Add "Herinnering sturen" button to `VotingRoundPanel.vue` — visible to chair/secretary for open rounds; calls `POST /api/voting-rounds/{id}/remind`; disabled for 60 seconds after click; shows toast with "Herinnering gestuurd aan X deelnemers"
- [ ] 8.3 Add "Deelname" column to the VotingRound list (`VotingRoundIndex.vue` or embedded list) showing `voted / invited` for open rounds and "gesloten" for closed rounds; data fetched from `/api/voting-rounds/{id}/distribution` per row

## 9. Frontend — Preferential ballot UI

- [ ] 9.1 Create `src/components/RankInput.vue` — a draggable ranked list component; accepts `:candidates` array prop; emits `:value` as JSON-encoded ordered array; uses CSS drag-and-drop (no external library); keyboard-navigable per WCAG AA (ADR-010); SPDX header required
- [ ] 9.2 Extend vote-casting section of `VotingRoundPanel.vue`: when `votingRound.votingMethod === "ranked-choice"`, replace Voor/Tegen/Onthouding buttons with `<RankInput :candidates="roundCandidates" @update:value="rankedValue = $event" />` and a single "Stem uitbrengen" button; validate all candidates ranked before submission
- [ ] 9.3 Create `src/components/RankedResultsCard.vue` — `CnDetailCard` wrapping a ranking table (Rank | Candidate | Points); winner row shows `CnStatusBadge` "Verkozen"; data read from VotingRound note with `title: "Borda resultaat"`; SPDX header required
- [ ] 9.4 Show "Voorkeursstemming (Borda)" in votingMethod dropdown of "Stemronde openen" dialog in `VotingRoundPanel.vue`; when selected, display a candidate entry textarea (comma-separated names) with label "Kandidaten (kommagescheiden)"

## 10. Frontend — Motion status management settings

- [ ] 10.1 Create `src/components/WorkflowConfigSection.vue` — a `CnSettingsCard` rendered in the GovernanceBody detail page (for admin users); displays current config as form: motion type checklist (motion, amendment, order, procedural, resolution), transition pairs editor (from-state → allowed-to-states), majority rule selector (simple, absolute, qualified-two-thirds); saves via `POST /api/governance-bodies/{id}/workflow-config`; loads via `GET /api/governance-bodies/{id}/workflow-config`; SPDX header required
- [ ] 10.2 Add `WorkflowConfigSection` to `GovernanceBodyDetail.vue` (or wherever the GovernanceBody detail is rendered) for admin users only; check `isAdmin` from backend settings (never from frontend-only OC.isAdmin)
- [ ] 10.3 Extend motion creation form (or `CnFormDialog` schema) to filter `motionType` options using `GET /api/governance-bodies/{id}/workflow-config` for the current GovernanceBody; show only permitted types in the dropdown

## 11. Seed data

- [ ] 11.1 Add seed VotingRound objects (secret, ranked-choice, disputed) to `lib/Settings/decidesk_register.json` following the `@self` envelope pattern; slugs must be unique; use realistic Dutch governance context
- [ ] 11.2 Add seed Vote objects (open ballot, ranked-choice JSON value) to `lib/Settings/decidesk_register.json`; seed votes must reference seed VotingRound slugs via OpenRegister relation
- [ ] 11.3 Add seed GovernanceBody objects with realistic `workflowTemplate` JSON to `lib/Settings/decidesk_register.json` (municipality, water board, corporate board configurations as shown in design.md)
- [ ] 11.4 Verify idempotency: re-importing seed data with `force: false` does not create duplicates (matched by slug via `ObjectService::searchObjects`)

## 12. Tests and integration

- [ ] 12.1 Write Newman/Postman collection entries in `tests/integration/` for: `GET /api/votes?votingRound={secret-id}` (verify anonymisation); `POST /api/voting-rounds/{id}/recount` (happy path and 409 duplicate); `POST /api/voting-rounds/{id}/remind` (happy path and 403 member); `GET /api/voting-rounds/{id}/distribution` (verify no vote values)
- [ ] 12.2 Write browser test (Playwright) for secret ballot: open secret round → cast vote → verify "anoniem" message → verify no vote table shown
- [ ] 12.3 Write browser test for ranked-choice: open ranked-choice round → rank candidates → submit → close round → verify ranking table displayed
- [ ] 12.4 Write browser test for recount: close round → request recount → verify disputed state → resolve → verify final result
- [ ] 12.5 Ensure all tests pass in `composer check:strict`
