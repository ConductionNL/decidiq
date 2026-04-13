## Deduplication Check (ADR-012)

- [x] 0.1 Confirm no custom CRUD, export, search, file, notification, calendar, or audit code is needed: all use `ObjectService`, `ExportService`, `IndexService`, `FileService`, `NotificationService`, `CalendarEventService`, `ActivityService` from OpenRegister platform
- [x] 0.2 Confirm `Motion`, `Amendment`, `Vote`, and `VotingRound` entities are used as-is from ADR-000 — no schema properties added or renamed
- [x] 0.3 Confirm `OriPublicationService` (external HTTP to ORI endpoint) and `MailReplyHandler` (email reply parsing) are the only truly custom integrations — no overlap with existing OpenRegister WebhookService for these specific use cases

## 1. Backend — MotionService and MotionController

- [x] 1.1 Create `lib/Service/MotionService.php` — stateless service tagged `@spec openspec/changes/p2-motion-and-voting/tasks.md#task-1` with the following public methods:
  - `transitionLifecycle(string $objectId, string $objectType, string $newState, string $actorId): void` — validates allowed transition, calls `ObjectService.saveObject()`, logs to `ActivityService`; handles both Motion and Amendment objects
  - `requestCoSignature(string $motionId, array $participantIds): void` — sends Nextcloud notification to each Participant via `NotificationService` with motion title and a link
  - `addCoSigner(string $motionId, string $participantDisplayName): void` — fetches Motion, appends to `coSigners`, saves via `ObjectService.saveObject()`; idempotent (no duplicates)
  - `saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void` — creates/updates a structured note on the Motion with `title: "Budget impact"` and JSON body
  - `detectConflicts(string $motionId, string $newAmendmentId): void` — fetches all submitted/debating Amendments for the motion, performs naive text overlap check against the new amendment text, notifies secretary-role users via `NotificationService` if overlap found
  - `applyAmendment(string $motionId, string $amendmentId): void` — reads Amendment text, appends it as amendment annotation to Motion `text` field, saves Motion via `ObjectService.saveObject()`
- [x] 1.2 Create `lib/Controller/MotionController.php` — thin controller (< 10 lines/method) with `@spec` tags:
  - `POST /api/motions/{id}/transition` → `MotionService::transitionLifecycle()`
  - `POST /api/motions/{id}/co-sign-request` → `MotionService::requestCoSignature()`
  - `POST /api/motions/{id}/co-sign-confirm` → `MotionService::addCoSigner()`
  - `POST /api/motions/{id}/budget-impact` → `MotionService::saveBudgetImpact()`
  - `POST /api/amendments/{id}/transition` → `MotionService::transitionLifecycle()`
- [x] 1.3 Register routes in `appinfo/routes.php` — add all 5 routes above; specific routes before wildcard `{slug}` routes
- [x] 1.4 Register `MotionService` and `MotionController` in DI container (`lib/AppInfo/Application.php`)
- [x] 1.5 Write PHPUnit tests in `tests/Unit/Service/MotionServiceTest.php` covering: `transitionLifecycle` allowed and blocked transitions; `addCoSigner` idempotency; `detectConflicts` with overlapping and non-overlapping text; `applyAmendment` text update

## 2. Backend — VotingService and VotingController

- [x] 2.1 Create `lib/Service/VotingService.php` — stateless service tagged `@spec openspec/changes/p2-motion-and-voting/tasks.md#task-2` with the following public methods:
  - `checkQuorum(string $meetingId): bool` — counts active Participants (non-null `leftAt`) related to the GovernanceBody via `ObjectService.findAll()`, compares against `Meeting.quorumRequired`
  - `openVotingRound(string $motionId, string $votingMethod, bool $isSecret, ?string $closedAt): VotingRound` — calls `checkQuorum()`, blocks if quorum not met; creates VotingRound object; transitions Motion to `voting`; calls `CalendarEventService` if `closedAt` is set
  - `castVote(string $votingRoundId, string $participantId, string $value, bool $isProxy, ?string $delegatorId): Vote` — checks round is open; checks for existing vote (update if found); enforces one-proxy-per-round rule for proxy votes; saves Vote via `ObjectService.saveObject()`; logs to `ActivityService`
  - `closeVotingRound(string $votingRoundId): VotingRound` — calls `tallyResults()`, transitions Motion lifecycle; calls `OriPublicationService.publish()` if configured; calls `FileService.createFolder()` if result is adopted
  - `tallyResults(string $votingRoundId): array` — counts Vote objects by value using `ObjectService.findAll()`; determines result (adopted/rejected/tied/invalid); updates VotingRound fields via `ObjectService.saveObject()`
  - `grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void` — validates roles (no observer/guest as receiver), stores proxy relation, sends notification
  - `revokeProxy(string $votingRoundId, string $fromParticipantId): void` — verifies round is not yet open, removes proxy relation, notifies delegate
- [x] 2.2 Create `lib/Controller/VotingController.php` — thin controller (< 10 lines/method) with `@spec` tags:
  - `POST /api/voting-rounds` → `VotingService::openVotingRound()` (body: `{ motionId, votingMethod, isSecret, closedAt }`)
  - `POST /api/voting-rounds/{id}/cast` → `VotingService::castVote()` (body: `{ participantId, value, isProxy, delegatorId }`)
  - `POST /api/voting-rounds/{id}/close` → `VotingService::closeVotingRound()`
  - `POST /api/voting-rounds/{id}/publish` → `OriPublicationService::publish()`
  - `POST /api/voting-rounds/{id}/proxy` → `VotingService::grantProxy()`
  - `DELETE /api/voting-rounds/{id}/proxy` → `VotingService::revokeProxy()`
- [x] 2.3 Register all 6 routes in `appinfo/routes.php`; specific routes before wildcard `{slug}` routes
- [x] 2.4 Register `VotingService` and `VotingController` in DI container
- [x] 2.5 Write PHPUnit tests in `tests/Unit/Service/VotingServiceTest.php` covering: `checkQuorum` met and not met; `openVotingRound` quorum block; `castVote` duplicate update; `castVote` proxy one-per-round enforcement; `tallyResults` adopted/rejected/tied; `closeVotingRound` lifecycle transition; `grantProxy` observer rejection

## 3. Backend — OriPublicationService and MailReplyHandler

- [x] 3.1 Create `lib/Service/OriPublicationService.php` tagged `@spec openspec/changes/p2-motion-and-voting/tasks.md#task-3`:
  - `publish(string $votingRoundId): void` — reads `OriEndpoint` from `IAppConfig`; if not configured, returns silently; builds JSON-LD payload following ORI 1.0 format; sends `POST` with retry on failure via a queued `IJob`
  - `getPublicationStatus(string $votingRoundId): string` — returns `pending`, `published`, or `not_configured`
- [ ] 3.2 [DEFERRED to p3] Create `lib/BackgroundJob/MailReplyHandler.php` — Nextcloud `IJob` background job tagged `@spec openspec/changes/p2-motion-and-voting/tasks.md#task-3`:
  - Polls for email replies addressed to voting notification threads (via `_mail` metadata on open VotingRounds)
  - Parses first non-empty line of reply for vote keywords ("Voor", "Tegen", "Onthouding"), case-insensitive
  - Calls `VotingService::castVote()` on match; sends confirmation email via `NotificationService`
  - On unrecognised reply: sends re-prompt email; after 3 failures per round per Participant, marks email voting as exhausted and sends final fallback notification
- [x] 3.3 Register `OriPublicationService` in DI container; MailReplyHandler background job registration removed from `appinfo/info.xml` (deferred to p3)

## 4. Frontend — Motion Views

- [x] 4.1 Create `src/views/MotionIndex.vue` — route `/motions`; uses `CnIndexPage` with `useListView("Motion", { sidebarState, objectStore })`; columns: title, motionType badge, proposer, lifecycle badge, submittedAt; filter by lifecycle and motionType via `CnFilterBar`
- [x] 4.2 Create `src/views/MotionDetail.vue` — route `/motions/:id`; two modes (new/edit vs view); view mode uses `CnDetailPage` with sections: Motion text, co-signers list, budget impact panel (if note present), Amendments list (`AmendmentList.vue` sub-component), VotingRound panel (`VotingRoundPanel.vue` sub-component); sidebar `CnObjectSidebar` with Files, Notes, Audit tabs
- [x] 4.3 Add `CnTimelineStages` to `MotionDetail.vue` header — stages: Ingediend → Debat → Stemming → Aangenomen / Verworpen / Ingetrokken; active stage highlighted; withdrawn renders as terminal warning stage
- [x] 4.4 Add lifecycle action buttons to `MotionDetail.vue` — "Debat openen" (chair/secretary, from submitted), "Stemronde openen" (chair/secretary, from debating), "Motie intrekken" (proposer only, before voting); each button calls `POST /api/motions/{id}/transition` with `newState`
- [x] 4.5 Add "Medeondertekenaars uitnodigen" section to `MotionDetail.vue` — Participant multi-select (filtered to GovernanceBody members); on submit calls `POST /api/motions/{id}/co-sign-request`; shows current `coSigners` list; shows "Ondersteunen" button for invited Participants not yet confirmed (calls `POST /api/motions/{id}/co-sign-confirm`)
- [x] 4.6 Add "Budget impact toevoegen" toggle to the MotionDetail edit form for `motionType: "amendment"` — shows three fields: budgetLine (text), amountDelta (number), rationale (text area); on save calls `POST /api/motions/{id}/budget-impact`; budget impact panel rendered in view mode below motion text
- [x] 4.7 Add "Motie koppelen" action to `AgendaItemDetail.vue` for `decision`-type items (extends p2-agenda-management) — search dialog listing Motions for the same Meeting; creates OpenRegister relation AgendaItem → Motion

## 5. Frontend — Amendment Views

- [x] 5.1 Create `src/components/AmendmentList.vue` — embedded in `MotionDetail.vue`; lists all Amendments for the motion with title, proposer, lifecycle badge, and link to AmendmentDetail; shows "N amendementen" count badge; "Amendement indienen" button (role: member/chair/secretary, motion lifecycle: submitted/debating)
- [x] 5.2 Create `src/views/AmendmentDetail.vue` — route `/amendments/:id`; view mode with `CnDetailPage`; shows amendment text, proposer, lifecycle timeline, parent motion link; lifecycle action buttons (chair): "Debat openen", "Stemronde openen"; sidebar `CnObjectSidebar` with Audit tab
- [x] 5.3 Add conflict detection notice to `AmendmentDetail.vue` — if a conflict notification exists for this amendment (fetched via notes with `title: "Conflict:"`), show a warning banner "Mogelijk conflict met ander amendement — raadpleeg de griffier"

## 6. Frontend — VotingRound Panel

- [x] 6.1 Create `src/components/VotingRoundPanel.vue` — embedded in `MotionDetail.vue` and `AmendmentDetail.vue`; shows: current open VotingRound (if any) OR most recent closed round; vote casting buttons (Voor / Tegen / Onthouding) for active members in open rounds; confirmation message after vote is cast
- [x] 6.2 Add live tally to `VotingRoundPanel.vue` — chair/secretary role sees "Uitgebracht: X / Y — Voor: A, Tegen: B, Onthouding: C" refreshed every 5 seconds via `objectStore.fetchObjects()` poll; member role sees "Uitgebracht: X / Y" only
- [x] 6.3 Add "Stemronde openen" dialog to `VotingRoundPanel.vue` — visible to chair/secretary when Motion lifecycle is `debating`; fields: votingMethod (dropdown), isSecret (toggle), closedAt (optional datetime picker); on submit calls `POST /api/voting-rounds`; shows quorum error inline if returned
- [x] 6.4 Add "Stemronde sluiten" button to `VotingRoundPanel.vue` — visible to chair/secretary when a round is open; shows confirmation dialog "Stemronde sluiten? X van Y leden hebben nog niet gestemd."; on confirm calls `POST /api/voting-rounds/{id}/close`; displays result immediately after close
- [x] 6.5 Add result display to `VotingRoundPanel.vue` — shows vote totals, result badge (Aangenomen / Verworpen / Gelijk / Ongeldig), majority threshold calculation, and (for non-secret rounds) per-Participant vote breakdown with proxy flags; "Publiceren naar ORI" button (chair/secretary) calling `POST /api/voting-rounds/{id}/publish`
- [x] 6.6 Add show-of-hands data entry to `VotingRoundPanel.vue` — visible when `votingMethod === "show-of-hands"` and round is open; three number inputs (Voor, Tegen, Onthouding); "Resultaat opslaan" button saves totals via `ObjectService.saveObject()`; individual vote buttons hidden

## 7. Frontend — Proxy Voting

- [x] 7.1 Add "Volmacht verlenen" action to `VotingRoundPanel.vue` — visible to active members before round opens; Participant selector filtered to active members in GovernanceBody excluding observers and guests; on submit calls `POST /api/voting-rounds/{id}/proxy`
- [x] 7.2 Add "Volmacht intrekken" button — visible to the delegating Participant before round opens; calls `DELETE /api/voting-rounds/{id}/proxy`; shows error if round already opened
- [x] 7.3 Display received proxy in `VotingRoundPanel.vue` for the delegate — "U stemt namens: [A]" shown above vote buttons when delegate has an active proxy; proxy vote is cast automatically alongside the delegate's own vote when they submit

## 8. Frontend — Motion and Vote Stores

- [x] 8.1 Register `Motion` object type in `store/store.js` via `objectStore.registerObjectType("Motion", "motion", "decidesk")` with plugins: `auditTrailsPlugin`, `filesPlugin`, `relationsPlugin`
- [x] 8.2 Register `Amendment`, `VotingRound`, and `Vote` object types in `store/store.js` with `relationsPlugin`
- [x] 8.3 Add `MotionIndex` and `MotionDetail` routes to `src/router/index.js`: `/motions` and `/motions/:id`; `AmendmentDetail`: `/amendments/:id`
- [x] 8.4 Add "Moties" navigation item to `MainMenu.vue` with route to MotionIndex and appropriate MDI icon

## 9. Translations (ADR-007)

- [x] 9.1 Add Dutch (nl) translation keys in `l10n/nl.js` and `l10n/nl.json` for all new user-visible strings: motion lifecycle labels (Ingediend, Debat, Stemronde, Aangenomen, Verworpen, Ingetrokken), voting method labels, quorum error messages, co-signatory dialog copy, proxy delegation labels, ORI publication labels, email vote keywords and confirmation messages, budget impact panel labels
- [x] 9.2 Add English (en) translation keys matching all Dutch keys

## 10. Settings (ADR-006)

- [x] 10.1 Add "ORI-eindpunt" configuration field to the admin settings page — URL input stored via `IAppConfig` under key `ori_endpoint`; validated as a valid URL; used by `OriPublicationService`
- [x] 10.2 Add "E-mail stemmen" toggle to admin settings — enables/disables `MailReplyHandler` background job; stored via `IAppConfig`; off by default

## 11. Testing (ADR-008)

- [x] 11.1 Write PHPUnit tests for `MotionServiceTest`: lifecycle transition allowed and blocked; addCoSigner idempotency; detectConflicts with and without overlap; applyAmendment text update
- [x] 11.2 Write PHPUnit tests for `VotingServiceTest`: checkQuorum met and not met; openVotingRound quorum block; castVote update on duplicate; proxy one-per-round enforcement; tallyResults adopted/rejected/tied; closeVotingRound lifecycle; grantProxy observer rejection
- [ ] 11.3 Write Newman/Postman integration tests in `tests/integration/motion-voting.json` for all 11 new API endpoints (motion transition, co-sign-request, co-sign-confirm, budget-impact, amendment transition, voting-round open, cast, close, publish, proxy grant, proxy revoke)
- [ ] 11.4 Write Playwright browser tests for: REQ-MOT-001 (motion submission), REQ-MOT-004 (co-signature collection), REQ-AMD-003 (conflict detection notification), REQ-VRM-002 (quorum block), REQ-VCT-001 (vote cast and overwrite), REQ-VCT-005 (keyboard accessible voting), REQ-RES-001 (result display after close), REQ-RES-003 (dossier folder created), REQ-PRX-001 (proxy delegation), REQ-PRX-003 (proxy revocation before open)

## 12. Verification

- [x] 12.1 Verify all new PHP classes and public methods have `@spec openspec/changes/p2-motion-and-voting/tasks.md#task-N` PHPDoc tags
- [x] 12.2 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded Dutch or English strings in templates or JS
- [x] 12.3 Verify no hardcoded CSS colors — only Nextcloud CSS variables (ADR-010)
- [x] 12.4 Verify WCAG 2.1 AA: keyboard navigation in vote casting, ARIA labels on all interactive controls, colour not the sole indicator of vote selection or lifecycle state (REQ-VCT-005)
- [x] 12.5 Verify `Motion`, `Amendment`, `Vote`, and `VotingRound` schemas in OpenRegister still match ADR-000 exactly after implementation — no extra properties added
- [ ] 12.6 Verify seed data (5 Motion, 3 Amendment, 4 VotingRound, 4 Vote objects) is present after fresh install
- [x] 12.7 Verify `OriPublicationService` gracefully handles missing config (no ORI endpoint set) without throwing an exception
