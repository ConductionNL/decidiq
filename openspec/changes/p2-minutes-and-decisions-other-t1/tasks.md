## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm no custom CRUD, export, search, notification, or audit code is needed: approval transitions use `ObjectService.saveObject()`, notifications use `NotificationService`, analytics use `CnDashboardPage` + `CnChartWidget`, audit trail is automatic via `AuditTrailService`
- [ ] 0.2 Confirm `Decision`, `Motion`, `ActionItem`, `Minutes` entities are used as-is from ADR-000 — no schema properties added or renamed; approval states extend the `lifecycle` string field with new values (non-breaking); reviewer tracking uses built-in `relations` + `notes`; outcome tracking uses built-in `tags`
- [ ] 0.3 Confirm `DecisionApprovalService`, `DecisionAutoRecordService`, and `DecisionDigestJob` are the only truly custom integrations — verify no overlap with OpenRegister `WorkflowEngineController` (workflow engine is for BPMN, not for simple linear governance approval); verify `IMailer` usage is not duplicated elsewhere in decidesk
- [ ] 0.4 Confirm `DecisionService::setOutcomeTag()` and `DecisionService::assignReviewer()` are added to the existing `DecisionService` from core-t1 — no duplicate service class; verify `MotionService.php` already exists from p2-motion-and-voting before adding the adoption hook
- [ ] 0.5 Confirm analytics `DecisionAnalyticsController` does not duplicate the standard `ObjectService.findAll()` list endpoint — analytics provides aggregated counts only, not paginated object lists; no overlap

## 1. Backend — DecisionApprovalService

- [ ] 1.1 Create `lib/Service/DecisionApprovalService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1` with the following public methods:
  - `transitionLifecycle(string $decisionId, string $toState, string $actorId, string $reason = ''): void` — fetches Decision via `ObjectService`; validates that the `toState` is a permitted next state from the current `lifecycle` (state machine map defined as a class constant); checks that `$actorId` has the required role for `$toState` via `AuthorizationService`; updates `lifecycle` via `ObjectService.saveObject()`; sends `NotificationService` notifications to the next reviewer group; logs to `AuditTrailService` with before/after snapshot; throws `\InvalidArgumentException` for invalid transitions
  - `submitReview(string $decisionId, string $personId, string $value, string $note = ''): void` — validates `$value` in `['approved', 'rejected']`; adds a structured note `[REVIEW] {personName}: {value} — {note} — {timestamp}` to the Decision via `ObjectService`; checks `allReviewsComplete($decisionId)` and if true sends notification to the secretary
  - `assignReviewer(string $decisionId, string $personId, string $actorId): void` — creates OpenRegister relation from Decision → Person (label: `reviewer`); sends `NotificationService` notification to the Person; validates actor has `chair` or `secretary` role
  - `allReviewsComplete(string $decisionId): bool` — fetches all Person relations with label `reviewer`; checks that each Person has a `[REVIEW]` note entry; returns true only when all assigned reviewers have submitted
  - `getApprovalStateMap(): array` — returns the state machine definition as a PHP constant array: `['draft' => ['legal-review'], 'legal-review' => ['committee-review', 'board-rejected'], 'committee-review' => ['board-approved', 'board-rejected'], 'board-approved' => ['published'], 'board-rejected' => ['draft']]`
- [ ] 1.2 Define `REQUIRED_ROLES` class constant mapping each target state to the required role: `['legal-review' => ['chair', 'secretary'], 'committee-review' => ['legal-counsel'], 'board-approved' => ['chair', 'secretary'], 'board-rejected' => ['chair', 'secretary', 'legal-counsel'], 'published' => ['chair', 'secretary']]`
- [ ] 1.3 Register `DecisionApprovalService` in DI container (`lib/AppInfo/Application.php`)
- [ ] 1.4 Write PHPUnit tests in `tests/Unit/Service/DecisionApprovalServiceTest.php` tagged `@spec` covering: `transitionLifecycle` from `draft` to `legal-review` succeeds for chair; `transitionLifecycle` from `draft` to `committee-review` throws (invalid transition); `transitionLifecycle` blocked when actor lacks required role; `submitReview` adds structured note; `allReviewsComplete` returns false when one reviewer has not signed off; minimum 5 test methods

## 2. Backend — DecisionApprovalController

- [ ] 2.1 Create `lib/Controller/DecisionApprovalController.php` — thin controller (< 10 lines/method) tagged `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2` with:
  - `POST /api/decisions/{id}/lifecycle` (body: `{ toState, reason? }`) → `DecisionApprovalService::transitionLifecycle()`
  - `POST /api/decisions/{id}/reviews` (body: `{ personId, value, note? }`) → `DecisionApprovalService::submitReview()`
  - `POST /api/decisions/{id}/reviewers` (body: `{ personId }`) → `DecisionApprovalService::assignReviewer()`
- [ ] 2.2 Register all 3 routes in `appinfo/routes.php`; specific routes before wildcard `{slug}` routes
- [ ] 2.3 Register `DecisionApprovalController` in DI container
- [ ] 2.4 Write Newman/Postman integration tests in `tests/integration/` for the 3 new endpoints covering happy path (200) and error paths (403 wrong role, 400 invalid transition, 400 empty reason)

## 3. Backend — DecisionAutoRecordService

- [ ] 3.1 Create `lib/Service/DecisionAutoRecordService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3` with:
  - `createFromAdoptedMotion(string $motionId): ?string` — fetches Motion via `ObjectService.findObject()`; checks for existing Decision linked via `ObjectService.findAll()` with relations filter `{label: 'source-motion', targetId: motionId}`; if found, logs idempotency skip at `INFO` level and returns existing UUID; if not found, creates Decision with fields from Motion (`title`, `decisionText`/`text`, today's date, `outcome: adopted`, `legalBasis`, `lifecycle: draft`); creates OpenRegister relation Decision → Motion with label `source-motion`; sends `NotificationService` notification to secretary; logs created UUID via `ActivityService`; returns new Decision UUID
- [ ] 3.2 Extend `lib/Service/MotionService.php` from p2-motion-and-voting — in the `transitionLifecycle()` method, add a branch: when `$toState === 'adopted'`, call `$this->decisionAutoRecordService->createFromAdoptedMotion($motionId)` after saving the Motion; inject `DecisionAutoRecordService` via constructor; tag the extended method with `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3`
- [ ] 3.3 Register `DecisionAutoRecordService` in DI container
- [ ] 3.4 Write PHPUnit tests in `tests/Unit/Service/DecisionAutoRecordServiceTest.php` tagged `@spec` covering: `createFromAdoptedMotion` creates Decision with correct fields from Motion; `createFromAdoptedMotion` returns existing UUID when Decision already linked (idempotency); `createFromAdoptedMotion` sends notification to secretary; `createFromAdoptedMotion` uses `decisionText` when non-empty, falls back to `text`; minimum 4 test methods

## 4. Backend — DecisionService Extension (Outcome Tags)

- [ ] 4.1 Extend `lib/Service/DecisionService.php` from p2-minutes-and-decisions-core-t1 — add public method `setOutcomeTag(string $decisionId, string $tag, string $actorId): void` tagged `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-4`:
  - Validates `$tag` is one of `geimplementeerd`, `implementatie-lopend`, `implementatie-uitgesteld`
  - Fetches Decision via `ObjectService.findObject()`
  - Removes any existing outcome tag from the `tags` array (mutual exclusivity)
  - Adds the new `$tag` to the `tags` array
  - Saves via `ObjectService.saveObject()`
  - Logs to `ActivityService`: `"{actor} changed implementation status of decision '{title}' to {tag}"`
- [ ] 4.2 Write PHPUnit tests in `tests/Unit/Service/DecisionServiceTest.php` tagged `@spec` covering: `setOutcomeTag` adds tag and removes existing outcome tag; `setOutcomeTag` with invalid tag throws `\InvalidArgumentException`; minimum 2 new test methods (in addition to existing core-t1 tests)

## 5. Backend — DecisionAnalyticsController

- [ ] 5.1 Create `lib/Controller/DecisionAnalyticsController.php` — thin controller (< 10 lines/method) tagged `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5` with:
  - `GET /api/decisions/analytics?governanceBodyId={id}` — runs four aggregate queries via `ObjectService.findAll()`:
    1. `decisionsPerMonth` — Decision count per month for last 12 months (filter: `decisionDate >= 12 months ago`; group by year-month of `decisionDate`)
    2. `outcomeDistribution` — Decision count grouped by `outcome` field
    3. `pendingApprovals` — count of Decisions with `lifecycle` in `['legal-review', 'committee-review']`
    4. `overdueActionItems` — count of ActionItems with `taskStatus: overdue`
  - Checks `ICache` with key `decidesk_analytics_{governanceBodyId|'all'}` before running queries; returns cached result if found (TTL 900 seconds)
  - Returns `Cache-Control: max-age=900` response header
  - Response shape: `{ decisionsPerMonth: [{month: 'YYYY-MM', count: N}], outcomeDistribution: [{outcome: string, count: N}], pendingApprovals: N, overdueActionItems: N }`
- [ ] 5.2 Register `GET /api/decisions/analytics` route in `appinfo/routes.php` — MUST appear before any `{slug}` wildcard routes
- [ ] 5.3 Register `DecisionAnalyticsController` in DI container
- [ ] 5.4 Write PHPUnit tests in `tests/Unit/Controller/DecisionAnalyticsControllerTest.php` tagged `@spec` covering: response contains all four keys; `governanceBodyId` param is passed to `ObjectService` filter; cache hit returns cached data without calling `ObjectService`; minimum 3 test methods

## 6. Backend — DecisionDigestJob

- [ ] 6.1 Create `lib/Job/DecisionDigestJob.php` — extends `TimedJob`, interval 604800 seconds, tagged `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6` with:
  - `run(array $argument): void` — iterates all GovernanceBodies via `ObjectService.findAll()`; checks `IAppConfig` key `digest_enabled_{bodyId}` (default true) to skip opted-out bodies; queries three data sets per body: upcoming ActionItems (dueDate within 14 days, taskStatus != completed), overdue ActionItems, Decisions in `legal-review` or `committee-review`; if all three are empty, skips sending; assembles plain-text + HTML email using `IMailer`; fetches recipient email addresses from Person records with `role: chair` or `role: secretary` in the body's Memberships; sends one email per recipient (not a single multi-recipient email); catches `\Throwable` per recipient, logs at `ERROR` with body ID and exception; logs successful send count at `INFO`
- [ ] 6.2 Register `DecisionDigestJob` in `appinfo/info.xml` under `<jobs>` element
- [ ] 6.3 Register `DecisionDigestJob` in DI container with injected `IMailer`, `IAppConfig`, `ObjectService`, `ILogger`
- [ ] 6.4 Write PHPUnit tests in `tests/Unit/Job/DecisionDigestJobTest.php` tagged `@spec` covering: digest skipped when `digest_enabled_{bodyId}` is false; digest skipped when all three sections are empty; email sent to chair and secretary when items exist; `IMailer` failure for one recipient does not stop job processing remaining bodies; minimum 4 test methods

## 7. Backend — Settings Extension (Digest Opt-Out)

- [ ] 7.1 Extend `lib/Service/SettingsService.php` — add `getDigestSettings(): array` method that reads `digest_enabled_{bodyId}` for all GovernanceBodies; add `setDigestEnabled(string $bodyId, bool $enabled): void` method saving via `IAppConfig`
- [ ] 7.2 Extend `lib/Controller/SettingsController.php` — add `GET /api/settings/digest` (returns digest opt-out settings per body) and `POST /api/settings/digest` (body: `{ governanceBodyId, enabled }`) calling `SettingsService::setDigestEnabled()`; register both routes in `appinfo/routes.php`

## 8. Frontend — DecisionApprovalPanel

- [ ] 8.1 Create `src/components/DecisionApprovalPanel.vue` — embedded in `DecisionDetail.vue`; displays the "Goedkeuringsproces" section with `CnTimelineStages` showing the four approval steps; action button for the permitted next transition is rendered based on current user role and current `lifecycle` state; clicking the advance button opens a confirmation `NcDialog`; rejection action requires a mandatory reason text input with client-side validation; all API calls to `POST /api/decisions/{id}/lifecycle` via `@nextcloud/axios`; all strings via `t()`; SPDX header required
- [ ] 8.2 Inject `DecisionApprovalPanel.vue` into `src/views/DecisionDetail.vue` as a new "Goedkeuringsproces" section, positioned below the Decision text and above the "Besluitdocumenten" panel (from core-t1); all component imports and `components: {}` registration required

## 9. Frontend — DecisionReviewerPanel

- [ ] 9.1 Create `src/components/DecisionReviewerPanel.vue` — embedded in `DecisionDetail.vue`; displays the "Beoordelaars" section; fetches Decision relations with label `reviewer` via `relationsPlugin.fetchRelations()`; for each reviewer relation, parses Decision notes to find `[REVIEW]` entries matching the reviewer's name; renders each reviewer row with: display name, role, sign-off status badge (`CnStatusBadge`), sign-off date, and review note; shows summary "N van M beoordelingen ontvangen"; adds a "Beoordelaar toevoegen" button (visible for chair/secretary only) that opens a Person relation picker; adds a "Herinnering sturen" button per unreviewed reviewer (chair/secretary only) calling `NotificationService` indirectly via `POST /api/decisions/{id}/reviewers/{personId}/remind` (returns 200 and sends notification); for assigned reviewers who are the current user, shows "Goedkeuren / Afwijzen" form with note field calling `POST /api/decisions/{id}/reviews`; all strings via `t()`; SPDX header required
- [ ] 9.2 Add `POST /api/decisions/{id}/reviewers/{personId}/remind` route and controller action in `DecisionApprovalController.php` — sends a reminder `NotificationService` notification to the Person; returns 200 or 404 if reviewer not found

## 10. Frontend — DecisionAnalyticsDashboard

- [ ] 10.1 Create `src/views/DecisionAnalyticsDashboard.vue` — uses `CnDashboardPage`; on `created()` calls `GET /api/decisions/analytics` via `@nextcloud/axios`; renders four `CnStatsBlock` cards in a `CnKpiGrid`: "Totaal besluiten", "Aangenomen", "In behandeling", "Achterstallige actiepunten"; renders a `CnChartWidget` with type `bar` for monthly trend using `decisionsPerMonth` response data; renders a `CnChartWidget` with type `donut` for outcome distribution; renders a "Mijn actiepunten" section using `ObjectService` fetched action items filtered by current user's display name; `CnFilterBar` allows GovernanceBody filter (updates analytics API call with `governanceBodyId` query param); all API calls wrapped in `try/catch` with user-facing error feedback; all strings via `t()`; SPDX header required
- [ ] 10.2 Add route to `src/router/index.js`: `{ path: '/decisions/analytics', name: 'DecisionAnalytics', component: DecisionAnalyticsDashboard }` — route MUST use path format (not hash format per ADR-004)
- [ ] 10.3 Add "Besluitanalyse" navigation item to `src/components/MainMenu.vue` with icon `mdiChartBar` linking to the `DecisionAnalytics` route

## 11. Frontend — Decision Index Extensions (Outcome Tags and Approval State Filter)

- [ ] 11.1 Extend `src/views/Decisions.vue` — add "Implementatiestatus" facet to `CnFacetSidebar` with options: "Geïmplementeerd" (`geimplementeerd`), "In uitvoering" (`implementatie-lopend`), "Uitgesteld" (`implementatie-uitgesteld`), "Geen status"; facet uses `IndexService` tag-based filtering
- [ ] 11.2 Extend `src/views/DecisionDetail.vue` — add "Implementatiestatus" dropdown in the Decision detail header (visible for chair/secretary only) with three options; on change calls `DecisionService::setOutcomeTag()` via `POST /api/decisions/{id}/outcome-tag` (new endpoint on `DecisionController`, calling `DecisionService::setOutcomeTag()`); add corresponding `POST /api/decisions/{id}/outcome-tag` route and controller action
- [ ] 11.3 Register `POST /api/decisions/{id}/outcome-tag` route in `appinfo/routes.php` before wildcard routes; add the controller action to `lib/Controller/DecisionController.php` from core-t1 (or create `DecisionController.php` if not yet present)

## 12. Frontend — Store and Route Updates

- [ ] 12.1 Ensure `Decision` object type is registered in `src/store/store.js` via `objectStore.registerObjectType('decision', 'decision', 'decidesk')` with `relationsPlugin`, `filesPlugin`, and `auditTrailsPlugin` plugins; if already registered from p2-minutes-and-decisions or core-t1, do not re-register
- [ ] 12.2 Ensure `Motion` object type is registered in `src/store/store.js` via `objectStore.registerObjectType('motion', 'motion', 'decidesk')` with `relationsPlugin`; verify single registration only
- [ ] 12.3 Verify that `DecisionAnalytics` route in `src/router/index.js` is named and uses path format consistent with the rest of the router (no hash format)

## 13. Translations (ADR-007)

- [ ] 13.1 Add Dutch (nl) translation keys in `l10n/nl.js` and `l10n/nl.json` for all new user-visible strings including: approval workflow step labels (Juridische toetsing, Commissiebehandeling, Bestuurlijke goedkeuring, Gepubliceerd), approval action button labels, reviewer panel labels (Beoordelaars, Beoordelaar toevoegen, Herinnering sturen, Goedkeuren, Afwijzen, N van M beoordelingen ontvangen), outcome tag labels (Geïmplementeerd, In uitvoering, Uitgesteld, Geen status), analytics dashboard labels (Besluitanalyse, Totaal besluiten, Aangenomen, In behandeling, Achterstallige actiepunten, Mijn actiepunten), auto-creation notification strings, digest email subject and section headers
- [ ] 13.2 Add English (en) translation keys in `l10n/en.js` and `l10n/en.json` matching all Dutch keys (key == English value for identity mapping)

## 14. Settings UI (ADR-006 / digest opt-out)

- [ ] 14.1 Add "Wekelijks overzicht" section to the admin settings page (`src/views/Settings.vue` or `src/views/AdminSettings.vue`) — renders a toggle per GovernanceBody showing current digest enabled/disabled state; fetches state via `GET /api/settings/digest`; on toggle change calls `POST /api/settings/digest` with `{ governanceBodyId, enabled }`; shows success/error feedback; all strings via `t()`

## 15. Seed Data

- [ ] 15.1 Add seed data for Decision (5 objects), ActionItem (3 objects), and Motion (3 objects) from `design.md` to `lib/Settings/decidesk_register.json` under the appropriate `components.objects[]` array using the `@self` envelope (`register`, `schema`, `slug`) with Dutch field values
- [ ] 15.2 Verify the repair step imports seed data on a fresh install without errors (slug-based upsert via `ObjectService.searchObjects` + `importFromApp`); ensure all slugs are globally unique across the register

## 16. Testing (ADR-008)

- [ ] 16.1 Write PHPUnit tests for `DecisionApprovalServiceTest.php` (task 1.4): 5 methods covering valid transition, invalid transition, role block, submitReview note structure, allReviewsComplete logic
- [ ] 16.2 Write PHPUnit tests for `DecisionAutoRecordServiceTest.php` (task 3.4): 4 methods covering creation, idempotency, notification, fallback to `text` field
- [ ] 16.3 Write PHPUnit tests for `DecisionDigestJobTest.php` (task 6.4): 4 methods covering skip on disabled, skip on empty sections, send to recipients, failure isolation
- [ ] 16.4 Write PHPUnit tests for `DecisionAnalyticsControllerTest.php` (task 5.4): 3 methods covering response shape, filter param, cache hit
- [ ] 16.5 Write Newman/Postman integration tests in `tests/integration/decisions-other-t1.json` for all new API endpoints: `POST /api/decisions/{id}/lifecycle`, `POST /api/decisions/{id}/reviews`, `POST /api/decisions/{id}/reviewers`, `GET /api/decisions/analytics`, `POST /api/decisions/{id}/outcome-tag`, `GET /api/settings/digest`, `POST /api/settings/digest`; cover 200 happy path and at least one error path per endpoint
- [ ] 16.6 Write Playwright browser tests for: REQ-DAW-001 (submit for legal review — visible for chair, hidden for member), REQ-DAW-004 (rejection requires non-empty reason), REQ-SRW-001 (assign reviewer — relation created), REQ-SRW-002 (reviewer submits sign-off), REQ-DAA-001 (analytics KPI cards load), REQ-DOT-001 (outcome tag set — badge appears in list and detail), REQ-ARC-001 (Motion adopted → Decision auto-created), REQ-ARC-002 (idempotency — no duplicate Decision on repeated adoption), REQ-WED-002 (digest toggle saved in admin settings)

## 17. Verification

- [ ] 17.1 Verify approval workflow: log in as chair; submit a Decision for legal review; confirm `lifecycle` changes to `legal-review` and notification appears; log in as legal-counsel; advance to `committee-review`; log in as member — confirm advance button not visible; log in as chair — advance to `board-approved`; confirm CnTimelineStages shows all steps completed
- [ ] 17.2 Verify rejection flow: log in as legal-counsel on a `legal-review` Decision; click "Besluit afwijzen"; try submitting without reason — confirm button stays disabled; enter reason and confirm; verify `lifecycle` is `board-rejected`, rejection reason appears as a note, audit trail entry is present
- [ ] 17.3 Verify reviewer panel: assign a Person as reviewer; confirm notification sent; log in as that Person; submit "Goedkeuren" with a note; confirm `[REVIEW]` note appears on the Decision; confirm sign-off panel shows "Goedgekeurd" status; add a second reviewer and verify "1 van 2 beoordelingen ontvangen" summary
- [ ] 17.4 Verify analytics dashboard: navigate to `/decisions/analytics`; confirm all 4 KPI cards load; confirm monthly bar chart shows last 12 months; confirm donut chart shows adopted/rejected breakdown; apply GovernanceBody filter and confirm counts change
- [ ] 17.5 Verify outcome tracking: open a `board-approved` Decision as secretary; select "In uitvoering" from the dropdown; confirm `implementatie-lopend` tag appears in `tags` array; open Decision index; select "In uitvoering" facet; confirm only tagged Decisions are shown; change tag to "Geïmplementeerd"; confirm old tag is removed
- [ ] 17.6 Verify auto-record creation: advance a Motion to `adopted`; confirm a new Decision with `lifecycle: draft` and `outcome: adopted` is created and linked to the Motion; navigate to the Decision and confirm "Bronmotie" section shows the source Motion; advance the Motion to `adopted` a second time and confirm no second Decision is created (idempotency)
- [ ] 17.7 Verify enhanced action item tracking: create an ActionItem with `dueDate` in the past; run `OverdueActionItemsJob` (or wait for daily run); confirm `taskStatus` changes to `overdue`; confirm notification sent to assignee AND to the secretary of the linked governance body; confirm `escalation-sent` tag is added; confirm a second run does NOT send the escalation notification again
- [ ] 17.8 Verify weekly digest opt-out: open admin settings; disable digest for a specific governance body; confirm `IAppConfig` key `digest_enabled_{bodyId}` is `false`; confirm the toggle persists after page reload
- [ ] 17.9 Verify all `@spec` PHPDoc tags are present on all new and extended PHP classes and public methods linking to `openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md`
- [ ] 17.10 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded Dutch or English strings in Vue templates or JS
- [ ] 17.11 Verify no hardcoded CSS colours in new components — only Nextcloud CSS variables used for approval stages, outcome badges, overdue highlights, and chart colours
- [ ] 17.12 Verify `Decision`, `Motion`, and `ActionItem` schemas in OpenRegister still match ADR-000 exactly — no extra properties added; only `lifecycle`, `tags`, `relations`, and `notes` (built-in) used for new capabilities
