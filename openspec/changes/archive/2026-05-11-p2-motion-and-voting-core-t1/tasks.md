## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm no custom CRUD, export, search, file, notification, calendar, or audit code is needed: all use `ObjectService`, `ExportService`, `IndexService`, `FileService`, `NotificationService`, `CalendarEventService`, `ActivityService` from OpenRegister platform
- [ ] 0.2 Confirm `Motion`, `Amendment`, `Vote`, and `VotingRound` entities are used as-is from ADR-000 — no schema properties added or renamed
- [ ] 0.3 Confirm `OriPublicationService` (external HTTP to ORI endpoint) and `MailReplyHandler` (email reply parsing) are the only truly custom integrations — verify no overlap with existing OpenRegister `WebhookService` for these specific use cases

## 1. Backend — MotionService and MotionController

- [ ] 1.1 Create `lib/Service/MotionService.php` — stateless service annotated `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-1` with the following public methods:
  - `transitionLifecycle(string $objectId, string $objectType, string $newState, string $actorId): void` — validates allowed transition per role, calls `ObjectService.saveObject()`, logs to `ActivityService`; handles both Motion and Amendment objects
  - `requestCoSignature(string $motionId, array $participantIds): void` — sends Nextcloud notification via `NotificationService` to each Participant with motion title and confirmation link
  - `addCoSigner(string $motionId, string $participantDisplayName): void` — fetches Motion, appends to `coSigners` if not already present (idempotent), saves via `ObjectService.saveObject()`
  - `saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void` — creates or updates a structured note on the Motion with `title: "Budget impact"` and JSON body
  - `detectConflicts(string $motionId, string $newAmendmentId): void` — fetches all `submitted`/`debating` Amendments for the Motion, performs naive text overlap check against the new amendment text, notifies all `secretary`-role Participants via `NotificationService` if overlap found
- [ ] 1.2 Create `lib/Controller/MotionController.php` — thin controller (<10 lines/method) annotated `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-1`:
  - `POST /api/motions/{id}/transition` → `MotionService::transitionLifecycle()`
  - `POST /api/motions/{id}/co-sign-request` → `MotionService::requestCoSignature()`
  - `POST /api/motions/{id}/co-sign-confirm` → `MotionService::addCoSigner()`
  - `POST /api/motions/{id}/budget-impact` → `MotionService::saveBudgetImpact()`
  - `POST /api/amendments/{id}/transition` → `MotionService::transitionLifecycle()`
- [ ] 1.3 Register all 5 routes in `appinfo/routes.php` — specific routes before any wildcard `{slug}` routes
- [ ] 1.4 Register `MotionService` and `MotionController` in DI container (`lib/AppInfo/Application.php`) using constructor injection with `private readonly`

## 2. Backend — VotingService and VotingController

- [ ] 2.1 Create `lib/Service/VotingService.php` — stateless service annotated `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-2` with the following public methods:
  - `checkQuorum(string $meetingId): bool` — counts active Participants (non-null `leftAt`) related to the GovernanceBody via `ObjectService.findAll()`, compares against `Meeting.quorumRequired`; returns false if quorum not met
  - `openVotingRound(string $motionId, string $votingMethod, bool $isSecret, ?string $closedAt): VotingRound` — calls `checkQuorum()`, blocks with `400` if quorum not met; creates VotingRound with `openedAt: now`; transitions Motion to `voting`; calls `CalendarEventService` if `closedAt` set; sends Nextcloud notification to all active Participants
  - `castVote(string $votingRoundId, string $participantId, string $value, bool $isProxy, ?string $delegatorId): Vote` — verifies round is open; checks for existing Vote from Participant (overwrites if found, no duplicate); enforces one-proxy-per-round for proxy votes; saves Vote via `ObjectService.saveObject()`; logs to `ActivityService`
  - `closeVotingRound(string $votingRoundId): VotingRound` — calls `tallyResults()`; transitions Motion lifecycle based on result; calls `OriPublicationService.publish()` if configured and result is adopted; logs to `ActivityService`
  - `tallyResults(string $votingRoundId): array` — counts Vote objects by `value` via `ObjectService.findAll()`; determines result (`adopted`/`rejected`/`tied`/`invalid`); updates VotingRound fields via `ObjectService.saveObject()`; returns `{ votesFor, votesAgainst, votesAbstain, result }`
  - `grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void` — validates round not yet open; validates delegate role is not `observer` or `guest`; validates no existing proxy from `fromParticipantId` in this round; creates OpenRegister relation; sends notification to delegate
  - `revokeProxy(string $votingRoundId, string $fromParticipantId): void` — validates round not yet open; removes proxy relation; sends revocation notification to delegate
- [ ] 2.2 Create `lib/Controller/VotingController.php` — thin controller (<10 lines/method) annotated `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-2`:
  - `POST /api/voting-rounds` → `VotingService::openVotingRound()` (body: `{ motionId, votingMethod, isSecret, closedAt }`)
  - `POST /api/voting-rounds/{id}/cast` → `VotingService::castVote()` (body: `{ participantId, value, isProxy, delegatorId }`)
  - `POST /api/voting-rounds/{id}/close` → `VotingService::closeVotingRound()`
  - `POST /api/voting-rounds/{id}/publish` → `OriPublicationService::publish()`
  - `POST /api/voting-rounds/{id}/proxy` → `VotingService::grantProxy()` (body: `{ fromParticipantId, toParticipantId }`)
  - `DELETE /api/voting-rounds/{id}/proxy` → `VotingService::revokeProxy()` (body: `{ fromParticipantId }`)
- [ ] 2.3 Register all 6 routes in `appinfo/routes.php` — specific routes before wildcard `{slug}` routes
- [ ] 2.4 Register `VotingService` and `VotingController` in DI container

## 3. Backend — OriPublicationService and MailReplyHandler

- [ ] 3.1 Create `lib/Service/OriPublicationService.php` annotated `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-3`:
  - `publish(string $votingRoundId): void` — reads `ori_endpoint` from `IAppConfig`; if not configured, returns silently without exception; builds JSON-LD payload following ORI 1.0 format with voting round result data; sends `POST` to endpoint; on failure queues a retry `IJob` with exponential backoff
  - `getPublicationStatus(string $votingRoundId): string` — returns `"not_configured"`, `"pending"`, or `"published"` based on config and VotingRound state
- [ ] 3.2 Create `lib/BackgroundJob/MailReplyHandler.php` — Nextcloud `IJob` annotated `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-3`:
  - Polls for email replies addressed to voting notification threads (via `_mail` metadata on open VotingRounds)
  - Reads first non-empty line of reply body; matches case-insensitively against "Voor", "Tegen", "Onthouding"
  - On match: calls `VotingService::castVote()`; sends confirmation email via `NotificationService`
  - On unrecognised reply: sends re-prompt notification; after 3 failures marks email voting exhausted for that Participant/round and sends final fallback notification directing them to the UI
- [ ] 3.3 Register `OriPublicationService` in DI container; register `MailReplyHandler` as a background job in `appinfo/info.xml` under `<background-jobs>`

## 4. Frontend — Motion Views

- [ ] 4.1 Create `src/views/MotionIndex.vue` — route `/motions`; uses `CnIndexPage` with `useListView("Motion", { sidebarState, objectStore: motionStore })`; columns: title, motionType (`CnStatusBadge`), proposer, lifecycle badge, submittedAt; `CnFilterBar` with filters on `lifecycle` and `motionType`; row click → `/motions/:id`; "Motie indienen" add button; all strings via `t()`
- [ ] 4.2 Create `src/views/MotionDetail.vue` — route `/motions/:id`; two modes (new/edit via `CnFormDialog` / view via `CnDetailPage`); view mode sections via `CnDetailCard`: motion text, co-signers list, budget impact panel (if note present), Amendments panel (`AmendmentList.vue` sub-component), VotingRound panel (`VotingRoundPanel.vue` sub-component); Edit + Delete header actions; `CnObjectSidebar` with Files, Notes, Tasks, Audit tabs; all strings via `t()`
- [ ] 4.3 Add `CnTimelineStages` to `MotionDetail.vue` header — stages: Ingediend → Debat → Stemming → Aangenomen / Verworpen / Ingetrokken; active stage highlighted; withdrawn renders as terminal warning stage using `--color-warning` Nextcloud CSS variable
- [ ] 4.4 Add role-enforced lifecycle action buttons to `MotionDetail.vue`:
  - "Debat openen" (chair/secretary, from `submitted`) → `POST /api/motions/{id}/transition` with `{ newState: "debating" }`
  - "Stemronde openen" (chair/secretary, from `debating`) → opens `VotingRoundPanel` dialog
  - "Motie intrekken" (proposer only, lifecycle not `voting`) → `POST /api/motions/{id}/transition` with `{ newState: "withdrawn" }`; show inline error if lifecycle is `voting`
  - All buttons hidden from `observer` and `guest` roles; all labels via `t()`
- [ ] 4.5 Add "Medeondertekenaars uitnodigen" section to `MotionDetail.vue` (visible to proposer) — Participant multi-select (filtered to active GovernanceBody members); on submit calls `POST /api/motions/{id}/co-sign-request`; shows current `coSigners` list; shows "Ondersteunen" button for invited Participants not yet confirmed (calls `POST /api/motions/{id}/co-sign-confirm`)
- [ ] 4.6 Add "Budget impact toevoegen" toggle for `motionType: "amendment"` in `MotionDetail.vue` edit form — three fields: budgetLine (text input), amountDelta (number input, formatted as euros), rationale (textarea); on save calls `POST /api/motions/{id}/budget-impact`; budget impact panel rendered in view mode below motion text
- [ ] 4.7 Extend `AgendaItemDetail.vue` for `decision`-type items — add "Moties" panel showing linked motions with title, lifecycle badge, and "Motie indienen" action button; action creates new Motion linked to the AgendaItem and routes to `/motions/new`

## 5. Frontend — Amendment Views

- [ ] 5.1 Create `src/components/AmendmentList.vue` — embedded in `MotionDetail.vue`; lists all Amendments for the motion with: title, proposer, lifecycle badge, link to AmendmentDetail; shows "N amendementen" count badge; "Amendement indienen" button (role: member/chair/secretary, motion lifecycle: submitted/debating)
- [ ] 5.2 Create `src/views/AmendmentDetail.vue` — route `/amendments/:id`; view mode with `CnDetailPage`; sections: amendment text, proposer, parent motion link; `CnTimelineStages` for amendment lifecycle (Ingediend → Debat → Stemming → Aangenomen / Verworpen); lifecycle action buttons for chair (same pattern as MotionDetail); `CnObjectSidebar` with Audit tab; all strings via `t()`
- [ ] 5.3 Add conflict detection warning banner to `AmendmentDetail.vue` — if a conflict notification note exists on the Amendment (title: "Conflict:"), show a `NcEmptyContent`-style warning banner "Mogelijk conflict met ander amendement — raadpleeg de griffier" using `--color-warning` Nextcloud CSS variable

## 6. Frontend — VotingRound Panel

- [ ] 6.1 Create `src/components/VotingRoundPanel.vue` — embedded in `MotionDetail.vue` and `AmendmentDetail.vue`; shows: current open VotingRound (if any) OR most recent closed round; vote casting section for active members in open rounds with Voor / Tegen / Onthouding buttons; confirmation message on vote cast; proxy indication "U stemt namens: [name]" when delegate has active proxy
- [ ] 6.2 Add "Stemronde openen" dialog to `VotingRoundPanel.vue` — visible to chair/secretary when Motion lifecycle is `debating`; fields: votingMethod (dropdown: voor-tegen-onthouding / gewogen / handopsteken), isSecret (toggle), closedAt (optional datetime picker); on submit calls `POST /api/voting-rounds`; shows quorum error inline if returned from backend
- [ ] 6.3 Add live tally to `VotingRoundPanel.vue` for open rounds — chair/secretary sees "Uitgebracht: X / Y — Voor: A, Tegen: B, Onthouding: C" refreshed every 5 seconds via `objectStore.fetchObjects()` poll; member role sees "Uitgebracht: X / Y" count only (totals hidden until close)
- [ ] 6.4 Add "Stemronde sluiten" button to `VotingRoundPanel.vue` — visible to chair/secretary when a round is open; shows `NcDialog` confirmation "Stemronde sluiten? X van Y leden hebben nog niet gestemd."; on confirm calls `POST /api/voting-rounds/{id}/close`; displays result badge immediately after close
- [ ] 6.5 Add result display to `VotingRoundPanel.vue` after close — shows vote totals, result badge (Aangenomen / Verworpen / Gelijk / Ongeldig) using `CnStatusBadge` with appropriate Nextcloud CSS variable colour, majority threshold calculation text, per-Participant vote table (if `isSecret: false`) with proxy flags, per-faction aggregation section (if Participants have `party` values); "Publiceren naar ORI" button (chair/secretary, result: adopted) calling `POST /api/voting-rounds/{id}/publish`; button hidden if ORI endpoint not configured (use `getPublicationStatus()`)
- [ ] 6.6 Add show-of-hands manual entry to `VotingRoundPanel.vue` — visible when `votingMethod === "show-of-hands"` and round is open; three number inputs (Voor, Tegen, Onthouding); "Resultaat opslaan" button saves totals via `ObjectService.saveObject()`; individual cast buttons hidden

## 7. Frontend — Proxy Voting

- [ ] 7.1 Add "Volmacht verlenen" action to `VotingRoundPanel.vue` — visible to active members (role: member, vice-chair) before round opens; `NcSelect` filtered to active GovernanceBody members excluding observers and guests; on submit calls `POST /api/voting-rounds/{id}/proxy` with `{ fromParticipantId, toParticipantId }`; shows error inline if round already open or delegate ineligible
- [ ] 7.2 Add "Volmacht intrekken" button — visible to the delegating Participant before round opens; calls `DELETE /api/voting-rounds/{id}/proxy`; shows inline error "Stemronde is al geopend" if round is already open
- [ ] 7.3 Display proxy indicator in `VotingRoundPanel.vue` for the delegate — "U stemt namens: [A]" shown above vote buttons when delegate has an active proxy; proxy vote is cast automatically alongside the delegate's own vote when they submit (handled in `VotingService::castVote()` backend)

## 8. Frontend — Stores, Routes, and Navigation

- [ ] 8.1 Register `Motion` object type in `src/store/store.js` via `objectStore.registerObjectType("Motion", "motion", "decidesk")` with plugins: `auditTrailsPlugin`, `filesPlugin`, `relationsPlugin`
- [ ] 8.2 Register `Amendment`, `VotingRound`, and `Vote` object types in `src/store/store.js` with `relationsPlugin`
- [ ] 8.3 Add named routes to `src/router/index.js`: `Motions` (`/motions`), `MotionDetail` (`/motions/:id`), `AmendmentDetail` (`/amendments/:id`)
- [ ] 8.4 Add "Moties" `NcAppNavigationItem` to `src/components/MainMenu.vue` with `:to="{ name: 'Motions' }"` and appropriate MDI icon; all labels via `t()`
- [ ] 8.5 Add 2 new `CnStatsBlock` KPI cards to `src/views/Dashboard.vue`: "Open moties" (count of Motions with `lifecycle: submitted` or `debating`), "Actieve stemrondes" (count of VotingRounds with `openedAt` set and `closedAt` null); fetch in parallel via `Promise.all`; all labels via `t()`

## 9. Settings (ADR-006)

- [ ] 9.1 Add "ORI-eindpunt" configuration field to the admin settings page (`src/views/Settings.vue`) — URL text input stored via `IAppConfig` under key `ori_endpoint`; validated as a valid URL on backend; used by `OriPublicationService`; shown under a "Publicatie" `CnSettingsSection`; all labels via `t()`
- [ ] 9.2 Add "E-mail stemmen inschakelen" toggle to admin settings — enables/disables `MailReplyHandler` background job; stored via `IAppConfig` under key `email_voting_enabled`; off by default; shown under a "Stemmen op afstand" `CnSettingsSection`; all labels via `t()`
- [ ] 9.3 Extend `lib/Service/SettingsService.php::getSettings()` to include `ori_endpoint` and `email_voting_enabled` so the frontend can hide/show ORI publish buttons and email voting UI accordingly

## 10. Translations (ADR-007)

- [ ] 10.1 Add Dutch (nl) translation keys in `l10n/nl.js` and `l10n/nl.json` for all new user-visible strings: motion lifecycle labels (Ingediend, Debat, Stemronde, Aangenomen, Verworpen, Ingetrokken), motionType labels (Motie, Amendement, Order, Procedureel), voting method labels (Voor-Tegen-Onthouding, Gewogen, Handopsteken), quorum error message, co-signatory dialog labels, proxy delegation labels (Volmacht verlenen, Volmacht intrekken, U stemt namens), ORI publication labels, email vote confirmation messages, result display labels (Aangenomen, Verworpen, Gelijk, Ongeldig), budget impact panel labels, amendment conflict warning text
- [ ] 10.2 Add English (en) translation keys matching all Dutch keys added in task 10.1

## 11. Testing (ADR-008)

- [ ] 11.1 Write `tests/Unit/Service/MotionServiceTest.php` — PHPUnit tests covering: (a) `transitionLifecycle` allowed transitions (submitted→debating, debating→voting, voting→adopted); (b) `transitionLifecycle` blocked transition (voting→withdrawn returns error); (c) `addCoSigner` idempotency (no duplicates in coSigners); (d) `detectConflicts` with overlapping text triggers notification; (e) `detectConflicts` with non-overlapping text sends no notification; annotate with `@spec` tag; minimum 5 test methods
- [ ] 11.2 Write `tests/Unit/Service/VotingServiceTest.php` — PHPUnit tests covering: (a) `checkQuorum` returns true when met; (b) `checkQuorum` returns false when not met; (c) `openVotingRound` blocks when quorum not met; (d) `castVote` overwrites existing vote rather than duplicating; (e) `castVote` proxy one-per-round enforcement throws on second grant; (f) `tallyResults` computes adopted correctly; (g) `tallyResults` computes rejected correctly; (h) `tallyResults` computes tied correctly; (i) `grantProxy` rejects observer as delegate; (j) `revokeProxy` fails when round is open; annotate with `@spec` tag; minimum 10 test methods
- [ ] 11.3 Write `tests/Unit/Service/OriPublicationServiceTest.php` — PHPUnit tests covering: (a) `publish` returns silently when no endpoint configured; (b) `publish` sends correct JSON-LD payload; (c) `getPublicationStatus` returns `not_configured` when no endpoint set; annotate with `@spec` tag; minimum 3 test methods
- [ ] 11.4 Write Newman/Postman integration tests in `tests/integration/motion-voting-core-t1.json` for all new API endpoints: motion transition (5), co-sign-request, co-sign-confirm, budget-impact, amendment transition, voting-round open, cast, close, publish, proxy grant, proxy revoke (11 endpoints total)
- [ ] 11.5 Write Playwright browser tests for: REQ-MOT-001 (submit motion), REQ-MOT-004 (co-signature collection), REQ-AMD-003 (conflict detection notification), REQ-VRM-002 (quorum block), REQ-VCT-001 (vote cast and overwrite), REQ-VCT-005 (keyboard accessible voting), REQ-RES-001 (result display after close), REQ-PRX-001 (proxy delegation), REQ-PRX-003 (proxy revocation before open)

## 12. Seed Data

- [ ] 12.1 Add seed data objects for Motion (5), Amendment (4), VotingRound (4), and Vote (5) to `lib/Settings/decidesk_register.json` under `x-openregister.seedData` using the `@self` envelope (`register`, `schema`, `slug`) with Dutch values from `design.md`
- [ ] 12.2 Verify the repair step imports these seed data objects on a fresh install without errors (slug-based upsert); confirm `ConfigurationService::importFromApp()` is idempotent

## 13. Verification

- [ ] 13.1 Verify Motion CRUD: create, read, update, delete via UI; confirm `lifecycle: "submitted"` on creation; confirm proposer is set to the logged-in user's display name
- [ ] 13.2 Verify Motion lifecycle: transition submitted → debating → voting → adopted; confirm audit trail entry per transition; confirm chair-only and secretary-only restrictions; confirm proposer can withdraw in submitted/debating but not voting
- [ ] 13.3 Verify co-signatory workflow: proposer invites two Participants; both receive Nextcloud notification; both confirm; `coSigners` array contains both names; no duplicates on repeat confirmation
- [ ] 13.4 Verify budget impact: toggle "Budget impact toevoegen" on an amendment-type Motion, enter values, save; confirm budget impact panel appears in view mode; confirm note exists on the Motion with `title: "Budget impact"`
- [ ] 13.5 Verify amendment submission: submit Amendment against an open Motion; confirm `lifecycle: "submitted"`; confirm it appears in the MotionDetail "Amendementen" section; confirm conflict detection notification fires when two amendments overlap
- [ ] 13.6 Verify proxy voting: grant proxy from Participant A to Participant B before round opens; confirm delegate notification; confirm Participant B sees "U stemt namens: A" in VotingRoundPanel; cast vote as B; confirm two Votes created (B's own + proxy for A); revoke proxy before open and confirm removal
- [ ] 13.7 Verify quorum check: attempt to open VotingRound with insufficient Participants; confirm `400` error with "Quorum niet bereikt" message; confirm round is not created
- [ ] 13.8 Verify vote casting: cast Voor; confirm Vote created; cast Tegen on same round as same user; confirm existing Vote is overwritten not duplicated; confirm tally reflects updated vote
- [ ] 13.9 Verify show-of-hands: open round with `votingMethod: "show-of-hands"`; confirm individual buttons hidden; enter manual counts; confirm VotingRound totals updated
- [ ] 13.10 Verify results display: close round; confirm result badge (Aangenomen / Verworpen) shown; confirm per-Participant table visible for non-secret rounds; confirm per-faction aggregation shown when Participants have party values
- [ ] 13.11 Verify ORI publication: configure ORI endpoint in settings; close an adopted round; click "Publiceren naar ORI"; confirm "Gepubliceerd" status shown; test with no endpoint configured — confirm button is hidden and service returns silently
- [ ] 13.12 Verify Dashboard KPIs: "Open moties" and "Actieve stemrondes" counts update when objects are created or status changes
- [ ] 13.13 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded Dutch or English strings in Vue templates or JS files
- [ ] 13.14 Verify no hardcoded CSS colours — only Nextcloud CSS variables used for all status badges, result displays, and warning indicators
- [ ] 13.15 Verify all new PHP classes and public methods have `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-N` PHPDoc tags
- [ ] 13.16 Verify seed data (5 Motion, 4 Amendment, 4 VotingRound, 5 Vote objects) is present after fresh install via the repair step
