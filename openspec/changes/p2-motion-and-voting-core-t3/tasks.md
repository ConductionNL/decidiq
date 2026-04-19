## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm no custom CRUD, export, search, file upload, notification, or audit code is needed: all use `ObjectService`, `ExportService`, `IndexService`, `FileService`, `NotificationService`, `ActivityService` from OpenRegister platform
- [ ] 0.2 Confirm `Motion`, `VotingRound`, `Vote`, and `ActionItem` entities are used as-is from ADR-000 — no schema properties added or renamed; execution lifecycle states are new allowed values on the existing `lifecycle` field; vote anonymisation uses built-in `tags` array and OpenRegister relation deletion
- [ ] 0.3 Confirm `VotingAnonymizationService` (vote anonymisation) and `QuorumCalculatorService` (quorum preview) are the only net-new PHP classes; `MotionService` and `VotingService` from p2-motion-and-voting are extended with new methods — no duplicate service class
- [ ] 0.4 Confirm `QuorumCalculatorService::calculate()` replaces the inline quorum logic in `VotingService::checkQuorum()` from p2-motion-and-voting — verify no duplication between the two, with `checkQuorum()` delegating to the new service

## 1. Backend — MotionService Extension (Execution Tracking)

- [ ] 1.1 Extend `lib/Service/MotionService.php` from p2-motion-and-voting — add the following public methods tagged `@spec openspec/changes/p2-motion-and-voting-core-t3/tasks.md#task-1`:
  - `transitionLifecycle()` extended to allow: `adopted → execution-pending`, `execution-pending → executing`, `executing → executed`; all other transitions to execution states return a `\RuntimeException` with message "Transition not allowed"
  - `updateExecutionNote(string $motionId, string $noteText): void` — fetches Motion via `ObjectService`; creates or replaces a note on the Motion object with `title: "Uitvoering"` and the provided text; saves via `ObjectService.saveObject()`
  - `createExecutionActionItem(string $motionId, string $motionTitle): string` — creates an ActionItem with `title: "Uitvoering motie: {motionTitle}"`, `taskStatus: open`, `dueDate: now() + IAppConfig('motion_execution_deadline_days', 90) days`; links it to the Motion via OpenRegister relation; returns the ActionItem's UUID
  - `completeExecutionActionItem(string $motionId): void` — fetches the linked execution ActionItem for the Motion; sets `taskStatus: completed` and `completedAt: now()`; saves via `ObjectService.saveObject()`
- [ ] 1.2 Wire execution lifecycle transitions in `transitionLifecycle()`: when transitioning `adopted → execution-pending`, automatically call `createExecutionActionItem()`; when transitioning `executing → executed`, automatically call `completeExecutionActionItem()`
- [ ] 1.3 Extend `lib/Controller/MotionController.php` from p2-motion-and-voting — add routes tagged `@spec`:
  - `POST /api/motions/{id}/execution-note` — body: `{ "text": "..." }` → `MotionService::updateExecutionNote()`
- [ ] 1.4 Register the new route in `appinfo/routes.php` before any wildcard `{slug}` routes
- [ ] 1.5 Write PHPUnit tests in `tests/Unit/Service/MotionServiceTest.php` tagged `@spec openspec/changes/p2-motion-and-voting-core-t3/tasks.md#task-1` covering: `transitionLifecycle` `adopted → execution-pending` creates ActionItem; `transitionLifecycle` `executing → executed` completes ActionItem; `transitionLifecycle` `submitted → execution-pending` throws RuntimeException; `updateExecutionNote` creates note; `updateExecutionNote` replaces existing note; minimum 5 new test methods

## 2. Backend — VotingAnonymizationService

- [ ] 2.1 Create `lib/Service/VotingAnonymizationService.php` — stateless service tagged `@spec openspec/changes/p2-motion-and-voting-core-t3/tasks.md#task-2` with the following public methods:
  - `anonymize(string $votingRoundId, string $actorId): int` — fetches VotingRound via `ObjectService`; verifies round is closed (non-null `closedAt` and `result` is set); verifies round is not already tagged `votes-anonymized` (returns 409 if so); fetches all Vote objects for the round via `ObjectService.findAll()`; for each Vote, removes the person relation by calling `ObjectService.saveObject()` with the relation nullified; updates VotingRound `tags` to add `votes-anonymized` via `ObjectService.saveObject()`; logs to `ActivityService` with message "Vote anonymisation: {count} votes anonymised" using `$user->getUID()`; returns count of anonymised votes
  - `isAnonymized(string $votingRoundId): bool` — fetches VotingRound and checks if `votes-anonymized` is in the `tags` array; returns boolean
- [ ] 2.2 Extend `lib/Controller/VotingController.php` from p2-motion-and-voting — add route tagged `@spec`:
  - `POST /api/voting-rounds/{id}/anonymize` → `VotingAnonymizationService::anonymize()` — requires chair or secretary role; returns `{ "anonymizedCount": N }`
- [ ] 2.3 Register the new route in `appinfo/routes.php` before any wildcard `{slug}` routes
- [ ] 2.4 Register `VotingAnonymizationService` in DI container (`lib/AppInfo/Application.php`)
- [ ] 2.5 Write PHPUnit tests in `tests/Unit/Service/VotingAnonymizationServiceTest.php` tagged `@spec` covering: `anonymize` on closed round nullifies all Vote person relations; `anonymize` adds `votes-anonymized` tag to VotingRound; `anonymize` on open round throws `\RuntimeException`; `anonymize` on already-anonymised round throws `\RuntimeException`; `isAnonymized` returns true for tagged round; minimum 5 test methods

## 3. Backend — QuorumCalculatorService and QuorumController

- [ ] 3.1 Create `lib/Service/QuorumCalculatorService.php` — stateless service tagged `@spec openspec/changes/p2-motion-and-voting-core-t3/tasks.md#task-3` with:
  - `calculate(string $governanceBodyId, int $expectedAttendance): array` — fetches GovernanceBody via `ObjectService`; counts active members (Memberships with null `endDate` or future `endDate`) via `ObjectService.findAll()`; parses `quorumRule` via `parseRule()`; returns `[ 'memberCount' => N, 'requiredVotes' => M, 'requiredMajority' => K, 'isQuorumMet' => bool, 'warning' => null|string ]`
  - `parseRule(string $rule, int $memberCount): array` — supports rule formats: `"majority"` (floor(N/2)+1), `"two-thirds"` (ceil(N*2/3)), `"absolute:N"` (literal N); returns `[ 'requiredVotes' => int, 'requiredMajority' => int ]`; returns `[ 'requiredVotes' => null, 'requiredMajority' => null ]` with `warning: "Quorumregel niet ingesteld voor dit orgaan"` when rule is empty or null
- [ ] 3.2 Update `lib/Service/VotingService.php` from p2-motion-and-voting — refactor `checkQuorum()` to delegate to `QuorumCalculatorService::calculate()` instead of computing inline; inject `QuorumCalculatorService` via constructor
- [ ] 3.3 Create `lib/Controller/QuorumController.php` — thin controller tagged `@spec` with:
  - `GET /api/governance-bodies/{id}/quorum-preview` — query param `expectedAttendance` (int, optional, default 0); calls `QuorumCalculatorService::calculate()`; annotated `#[NoCSRFRequired]`; requires user to be an active member of the GovernanceBody (403 otherwise); returns JSON response with quorum preview data
- [ ] 3.4 Register route in `appinfo/routes.php` before any wildcard `{slug}` routes
- [ ] 3.5 Register `QuorumCalculatorService` and `QuorumController` in DI container
- [ ] 3.6 Write PHPUnit tests in `tests/Unit/Service/QuorumCalculatorServiceTest.php` tagged `@spec` covering: `parseRule` with `majority` rule returns correct threshold; `parseRule` with `two-thirds` rule returns correct threshold; `parseRule` with `absolute:8` rule returns 8; `parseRule` with empty rule returns null with warning; `calculate` with zero expected attendance returns `isQuorumMet: false`; minimum 5 test methods

## 4. Backend — Written Resolution Extension to VotingService

- [ ] 4.1 Extend `lib/Service/VotingService.php` from p2-motion-and-voting — extend `openVotingRound()` to handle `votingMethod: written-resolution` tagged `@spec openspec/changes/p2-motion-and-voting-core-t3/tasks.md#task-4`:
  - After creating the VotingRound, fetch all active Memberships for the GovernanceBody via `ObjectService.findAll()`
  - For each active member's linked Person, send a Nextcloud notification via `NotificationService` with: Motion title, Motion text (first 300 chars), vote URL (`/apps/decidesk/voting-rounds/{id}/cast`), and deadline date
  - Log notification dispatch count to `ActivityService`; log individual failures at ERROR level via `ILogger` without aborting — partial failure is not fatal
  - Create an ActionItem with `title: "Termijn schriftelijke stemming: {motionTitle}"`, `taskStatus: open`, `dueDate: closedAt`; link to the Motion
  - Add validation: `written-resolution` rounds MUST have a `closedAt` timestamp; return 400 if missing
- [ ] 4.2 Add `written-resolution` as an accepted `votingMethod` value in the `VotingController.php` input validation from p2-motion-and-voting
- [ ] 4.3 Write PHPUnit tests in `tests/Unit/Service/VotingServiceTest.php` tagged `@spec openspec/changes/p2-motion-and-voting-core-t3/tasks.md#task-4` covering: `openVotingRound` with `written-resolution` dispatches N notifications for N active members; `openVotingRound` with `written-resolution` and no `closedAt` returns 400; `openVotingRound` with partial notification failure still creates VotingRound; minimum 3 new test methods (in addition to existing tests from p2-motion-and-voting)

## 5. Backend — Settings Extension

- [ ] 5.1 Add `motion_execution_deadline_days` configuration key to the admin settings service — expose it as an integer field (default: 90) in `lib/Service/SettingsService.php`; validate value is a positive integer; store via `IAppConfig` with sensitive flag `false`
- [ ] 5.2 Add "Uitvoeringstermijn moties" configuration section to the admin settings page; label: "Standaard uitvoeringstermijn (dagen)"; help text: "Aantal dagen na aanname van een motie waarbinnen uitvoering moet zijn gestart."
- [ ] 5.3 Extend `SettingsService::getSettings()` to include `motionExecutionDeadlineDays` in the settings response so the frontend can display the configured deadline in the execution panel

## 6. Frontend — MotionExecutionPanel

- [ ] 6.1 Create `src/components/MotionExecutionPanel.vue` — embedded in `src/views/MotionDetail.vue`; visible only when Motion `lifecycle` is `adopted`, `execution-pending`, `executing`, or `executed`; displays:
  - `CnTimelineStages` with stages: Aangenomen → Uitvoering gepland → In uitvoering → Uitgevoerd; current stage derived from `lifecycle`
  - Status badge (`CnStatusBadge`) showing human-readable execution state
  - "Uitvoeringsnotitie" textarea pre-populated with the existing execution note (if any); "Opslaan" button calls `POST /api/motions/{id}/execution-note`
  - Linked execution ActionItem table (using `relationsPlugin`) showing title, dueDate, taskStatus; empty state: "Geen uitvoeringsacties"
  - Action buttons in `header-actions` slot (ADR-018): "Markeer voor uitvoering" (adopted → execution-pending), "Start uitvoering" (execution-pending → executing), "Markeer als uitgevoerd" (executing → executed); each calls `POST /api/motions/{id}/transition` and refreshes the Motion object from the store
- [ ] 6.2 Add `SPDX-License-Identifier: EUPL-1.2` as first line of `MotionExecutionPanel.vue`
- [ ] 6.3 All user-visible strings in `MotionExecutionPanel.vue` MUST use `this.t('decidesk', 'key')` — no hardcoded Dutch or English strings in templates
- [ ] 6.4 Inject `MotionExecutionPanel.vue` into `src/views/MotionDetail.vue` as a new section below the Motion text and above the linked VotingRounds section; import and register the component in `MotionDetail.vue`
- [ ] 6.5 EVERY `await store.action()` call in `MotionExecutionPanel.vue` MUST be wrapped in `try/catch` with user-facing error feedback via `NcDialog` or notification

## 7. Frontend — Vote Anonymisation UI

- [ ] 7.1 Add an "Anonimiseren" action button to `src/views/VotingRoundDetail.vue` — visible only when: round is closed (`result` is set) AND round is NOT already tagged `votes-anonymized` AND the logged-in user has role `chair` or `secretary`; place in the detail page header actions
- [ ] 7.2 Implement a confirmation dialog before anonymisation: use `NcDialog` (NOT `window.confirm()`); dialog text: "Weet u zeker dat u de stemmen wilt anonimiseren? Deze actie is onomkeerbaar."; confirm button calls `POST /api/voting-rounds/{id}/anonymize`
- [ ] 7.3 After successful anonymisation, refresh the VotingRound from the store; display a `CnStatusBadge` with label "Anoniem" and the anonymisation date (from the audit trail) in the VotingRound detail header
- [ ] 7.4 All strings in the confirmation dialog and status badge MUST use `this.t('decidesk', 'key')`; add corresponding keys to `l10n/en.json` and `l10n/nl.json`

## 8. Frontend — QuorumCalculatorPanel

- [ ] 8.1 Create `src/components/QuorumCalculatorPanel.vue` — a standalone panel component tagged `@spec openspec/changes/p2-motion-and-voting-core-t3/tasks.md#task-8` with:
  - `governanceBodyId` prop (string, required)
  - `initialExpectedAttendance` prop (integer, optional, default 0)
  - A number input "Verwacht aanwezig" that triggers a debounced GET to `/api/governance-bodies/{id}/quorum-preview?expectedAttendance=N`
  - Displays (using `CnStatsBlock` or `CnDetailGrid`): Totaal leden, Vereist quorum, Vereiste meerderheid, Quorum gehaald (green check / red cross)
  - Warning message displayed when `warning` field is present in the API response
  - Empty state when `governanceBodyId` is null or undefined
- [ ] 8.2 Add `SPDX-License-Identifier: EUPL-1.2` as first line of `QuorumCalculatorPanel.vue`
- [ ] 8.3 Inject `QuorumCalculatorPanel.vue` into `src/views/GovernanceBodyDetail.vue` as a standalone panel below the membership list; import and register the component
- [ ] 8.4 Inject `QuorumCalculatorPanel.vue` into the VotingRound creation dialog (`src/components/VotingRoundForm.vue` or equivalent); pass the `governanceBodyId` from the linked Meeting/GovernanceBody; the "Stemronde openen" submit button is disabled with tooltip "Quorum niet bereikt" when `isQuorumMet: false` and `expectedAttendance > 0`
- [ ] 8.5 All user-visible strings MUST use `this.t('decidesk', 'key')`; add keys to `l10n/en.json` and `l10n/nl.json`

## 9. Frontend — Written Resolution Flow

- [ ] 9.1 Add `written-resolution` as an option in the `votingMethod` select in the VotingRound creation dialog; label: "Schriftelijke stemming (buiten vergadering)"
- [ ] 9.2 When `written-resolution` is selected, make the `closedAt` date-time field required (validated in the form before submit) with label "Uiterste stemmingsdatum" and help text: "BW 2:238: alle leden moeten vóór deze datum hun stem hebben uitgebracht."
- [ ] 9.3 After a `written-resolution` VotingRound is created, show a success notification listing the number of members notified: "Schriftelijke stemronde geopend — {N} leden genotificeerd"
- [ ] 9.4 On the VotingRound detail page, when `votingMethod === 'written-resolution'`, display a "Schriftelijke stemming" badge in the header and show the `closedAt` deadline prominently with a countdown (days remaining)

## 10. Seed Data

- [ ] 10.1 Add the seed data objects from `design.md` to `lib/Settings/decidesk_register.json` under `components.objects[]`:
  - 5 Motion objects with slugs: `motion-woningbouwplan-oost-adopted`, `motion-duurzaamheid-executing`, `motion-parkeerbeleid-executed`, `motion-written-resolution-ava`, `motion-amendement-jeugdzorg`
  - 4 VotingRound objects with slugs: `votinground-woningbouw-nominaal`, `votinground-duurzaamheid-geanonimiseerd`, `votinground-written-resolution-ava`, `votinground-parkeerbeleid-open`
  - 4 Vote objects with slugs: `vote-woningbouw-vdb-voor`, `vote-woningbouw-smits-tegen`, `vote-ava-vanhouten-voor`, `vote-duurzaamheid-proxy-voor`
  - 3 ActionItem objects with slugs: `actionitem-uitvoering-woningbouw`, `actionitem-uitvoering-duurzaamheid`, `actionitem-termijn-written-resolution`
- [ ] 10.2 Verify all seed objects use the `@self` envelope format with `register: "decidesk"`, `schema` matching ADR-000 entity names, and a unique `slug` following kebab-case convention
- [ ] 10.3 Verify re-import idempotency: run `importFromApp()` twice and confirm no duplicate objects are created (slug-based matching)

## 11. Integration Tests and Smoke Testing

- [ ] 11.1 Add Newman/Postman collection in `tests/integration/` for the new endpoints:
  - `POST /api/motions/{id}/transition` — happy path `execution-pending`; invalid transition `submitted → execution-pending` (400); unauthenticated (401)
  - `POST /api/motions/{id}/execution-note` — create note; update note; unauthenticated (401)
  - `POST /api/voting-rounds/{id}/anonymize` — happy path; open round (400); already anonymised (409); non-chair/secretary role (403)
  - `GET /api/governance-bodies/{id}/quorum-preview` — with attendance param; missing quorumRule (200 with warning); non-member (403)
  - `POST /api/voting-rounds` with `votingMethod: written-resolution` — happy path; missing closedAt (400)
- [ ] 11.2 Smoke test (before PR): call each endpoint with `curl` and verify response shape matches specs; test at least one error path per endpoint (400, 401, 403)
- [ ] 11.3 Verify deferred features are NOT registered: no written-resolution notification cron job, no bulk anonymisation batch — these are interactive operations only

## 12. Translation Keys

- [ ] 12.1 Add all new user-visible strings from `MotionExecutionPanel.vue`, `QuorumCalculatorPanel.vue`, and `VotingRoundDetail.vue` changes to `l10n/en.json` (English, key == value)
- [ ] 12.2 Add corresponding Dutch translations for all new keys in `l10n/nl.json`
- [ ] 12.3 Verify zero gaps between `en.json` and `nl.json` — both files MUST contain exactly the same keys

## 13. Pre-commit Verification

- [ ] 13.1 SPDX headers: run `grep -rL 'SPDX-License-Identifier' src/ lib/ --include='*.php' --include='*.vue' --include='*.js'` — add headers to EVERY file missing one
- [ ] 13.2 ObjectService calls: run `grep -rn 'findObject\|saveObject\|findObjects' lib/ --include='*.php'` — verify every call has 3 positional args
- [ ] 13.3 Error responses: run `grep -rn 'getMessage()' lib/Controller/ --include='*.php'` — replace any `$e->getMessage()` in JSONResponse with a static error string
- [ ] 13.4 Auth checks: for every POST/DELETE controller method added in tasks 1–4, verify `IGroupManager::isAdmin()` or role check is present on the backend
- [ ] 13.5 Vue imports: for every `<NcFoo>` or `<CnFoo>` in new templates, verify the component is imported from `@conduction/nextcloud-vue` (NOT `@nextcloud/vue`) AND listed in `components: {}`
- [ ] 13.6 try/catch: run `grep -rn 'await.*Store\.' src/ --include='*.vue'` — verify every store call in new components is wrapped in try/catch with user feedback
- [ ] 13.7 Type slug consistency: run `grep -rn "motion\|votingRound\|voteEvent" src/ --include='*.vue' --include='*.js'` — verify all entity type strings use kebab-case slugs: `motion`, `voting-round`, `vote`, `action-item`
