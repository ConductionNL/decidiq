<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: p2-minutes-and-decisions (Minutes and Decisions)
     This spec extends the existing `p2-minutes-and-decisions` capability. Do NOT define new entities or build new CRUD — reuse what `p2-minutes-and-decisions` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm no custom CRUD, export, search, file, notification, or audit code is needed: all use `ObjectService`, `IndexService`, `FileService`, `NotificationService`, `AuditTrailService`, `WorkflowEngineController` from the OpenRegister platform
- [ ] 0.2 Confirm `Decision`, `Minutes`, `ActionItem`, and `Meeting` entities are used as-is from ADR-000 — no schema properties added or renamed; rationale uses the built-in `notes` array; no new entity introduced
- [ ] 0.3 Confirm `ActionItemAnalyticsService`, `ALVMinutesService`, `ActionItemExtractionService`, and `DecisionNotificationService` are the only truly custom integrations — no overlap with `ObjectService.findAll()` aggregation, `NotificationService`, or `WorkflowEngineController`
- [ ] 0.4 Confirm the ALV template is a new standalone service, not a duplicate of `MinutesGenerationService` from p2-minutes-and-decisions — separate class is justified by structural differences in the ALV template (quorum law, resolution language)
- [ ] 0.5 Confirm `DecisionNotificationService` does not duplicate the urgent notification logic from `DecisionService` (T1) — T1 notifies on urgent flag; T3 notifies on publication; different trigger, different recipients

## 1. Backend — ActionItemAnalyticsService

- [ ] 1.1 Create `lib/Service/ActionItemAnalyticsService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1` with the following public methods:
  - `getSummary(string $dateFrom, string $dateTo): array` — queries ActionItems via `ObjectService.findAll()` with filters `taskStatus` and `dueDate`; returns `[ 'totalOpen' => int, 'totalOverdue' => int, 'completedThisMonth' => int, 'avgDaysToClose' => float ]`; `avgDaysToClose` is the average of (`completedAt - createdAt`) in days for items completed within the date range
  - `getCompletionRates(int $limit = 6): array` — fetches the last `$limit` Meetings ordered by `scheduledDate` descending via `ObjectService.findAll()`; for each Meeting, queries linked ActionItems and computes `completed / total * 100`; returns array of `[ 'meetingTitle' => string, 'completionRate' => float, 'total' => int ]`
  - `getMyItems(string $userDisplayName): array` — queries ActionItems where `assignee == $userDisplayName` and `taskStatus != 'completed'`; returns grouped array `[ 'overdue' => [], 'thisWeek' => [], 'later' => [] ]`; overdue = `dueDate < today`; thisWeek = `dueDate <= today + 7 days`
- [ ] 1.2 Create `lib/Controller/AnalyticsController.php` — thin controller (< 10 lines/method) tagged `@spec`:
  - `GET /api/analytics/action-items` → calls `ActionItemAnalyticsService::getSummary()` with `dateFrom` and `dateTo` query params (default: current calendar year); returns summary JSON
  - `GET /api/analytics/action-items/completion-rates` → calls `ActionItemAnalyticsService::getCompletionRates(limit: 6)`; returns array of meeting completion rates
  - `GET /api/analytics/action-items/my-items` → calls `ActionItemAnalyticsService::getMyItems()` using `IUserSession::getUser()->getDisplayName()`; returns grouped items
- [ ] 1.3 Register all 3 routes in `appinfo/routes.php`; specific routes before any wildcard `{slug}` routes
- [ ] 1.4 Register `ActionItemAnalyticsService` and `AnalyticsController` in DI container (`lib/AppInfo/Application.php`)
- [ ] 1.5 Write PHPUnit tests in `tests/Unit/Service/ActionItemAnalyticsServiceTest.php` tagged `@spec` covering: `getSummary` returns correct overdue count; `getCompletionRates` returns 0% for meeting with no completed items; `getMyItems` groups overdue items correctly; `avgDaysToClose` calculates correct average; minimum 4 test methods

## 2. Backend — LiveDecisionService

- [ ] 2.1 Create `lib/Service/LiveDecisionService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2` with:
  - `recordDecision(string $meetingId, array $decisionData, string $actorId): string` — fetches Meeting via `ObjectService.findObject()`; verifies `lifecycle == 'opened'` (throws 409 if not); creates Decision via `ObjectService.saveObject()` with relation to Meeting; calls `ensureDraftMinutes($meetingId)` and links Decision to Minutes; returns the new Decision's slug
  - `ensureDraftMinutes(string $meetingId): string` — checks if a Minutes object linked to the Meeting exists via `ObjectService.findAll()`; if not, creates a draft Minutes with `title: "Concept notulen — {meeting.title}"`, `lifecycle: draft`, `version: 1`, and a relation to the Meeting; returns the Minutes slug
- [ ] 2.2 Create `lib/Controller/LiveMeetingController.php` — thin controller (< 10 lines/method) tagged `@spec`:
  - `POST /api/meetings/{meetingId}/live-decisions` → `LiveDecisionService::recordDecision()` with body `{ title, text, outcome, legalBasis? }`; requires meeting `lifecycle: opened` (verified on backend); returns created Decision
- [ ] 2.3 Register the route in `appinfo/routes.php` before wildcard `{slug}` routes
- [ ] 2.4 Register `LiveDecisionService` and `LiveMeetingController` in DI container
- [ ] 2.5 Write PHPUnit tests in `tests/Unit/Service/LiveDecisionServiceTest.php` tagged `@spec` covering: `recordDecision` creates Decision and links to Meeting; `recordDecision` throws 409 when Meeting not opened; `ensureDraftMinutes` creates draft Minutes when none exists; `ensureDraftMinutes` returns existing Minutes slug when one exists; minimum 4 test methods

## 3. Backend — ALVMinutesService

- [ ] 3.1 Create `lib/Service/ALVMinutesService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3` with:
  - `generateALVDraft(string $minutesId): array` — fetches Minutes and linked Meeting via `ObjectService`; validates `meetingType` contains `alv` (case-insensitive); fetches AgendaItems, Participants of linked GovernanceBody (leftAt == null), and linked Decisions; renders the ALV Dutch template string (quorum statement, agenda sections, resolutions with vote totals, rondvraag); returns `[ 'content' => '<generated ALV minutes>', 'recipientCount' => int ]`
  - `distribute(string $minutesId): int` — fetches Minutes (must be `lifecycle: approved` or `signed`); fetches active Participants of the linked GovernanceBody; sends a Nextcloud notification to each via `NotificationService` with minutes title, lifecycle, and deep link; returns the count of notifications sent
  - PHP constant `ALV_TEMPLATE` — Dutch ALV minutes template string with placeholders: `{title}`, `{date}`, `{location}`, `{presentCount}`, `{totalCount}`, `{quorumStatus}`, `{agendaItems}`, `{resolutions}`, `{aob}`
- [ ] 3.2 Create route actions in `lib/Controller/MinutesController.php` (extending from p2-minutes-and-decisions) tagged `@spec`:
  - `POST /api/minutes/{minutesId}/generate-alv` → `ALVMinutesService::generateALVDraft()`; returns `{ preview: '<generated content>' }`
  - `POST /api/minutes/{minutesId}/distribute` → `ALVMinutesService::distribute()`; validates lifecycle is `approved` or `signed` on backend; returns `{ notified: N }`
- [ ] 3.3 Register both routes in `appinfo/routes.php`
- [ ] 3.4 Register `ALVMinutesService` in DI container
- [ ] 3.5 Write PHPUnit tests in `tests/Unit/Service/ALVMinutesServiceTest.php` tagged `@spec` covering: `generateALVDraft` produces correct quorum statement for quorum met; `generateALVDraft` returns validation error for non-ALV meeting; `distribute` returns 0 when no active participants; `distribute` throws 403 when lifecycle is `draft`; minimum 4 test methods

## 4. Backend — ActionItemExtractionService

- [ ] 4.1 Create `lib/Service/ActionItemExtractionService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4` with:
  - `extractFromContent(string $content, array $knownParticipants = []): array` — splits content into lines; applies regex patterns to detect action items: `^(Actie|AI|Taak|Actiepunt):`, `wordt verzocht`, `zal worden`, `is toegezegd`; for each match, extracts the title (text after the marker), attempts to match a name from `$knownParticipants` against the line text; returns array of candidate objects `[ 'title' => string, 'suggestedAssignee' => string|null ]`
  - `saveExtracted(string $minutesId, array $confirmed): int` — for each confirmed candidate, creates an ActionItem via `ObjectService.saveObject()` with `title`, `assignee` (if provided), `taskStatus: open`; links each ActionItem to the Minutes via OpenRegister relation; returns the count of saved items
- [ ] 4.2 Extend `lib/Controller/MinutesController.php` with:
  - `POST /api/minutes/{minutesId}/extract-action-items` → `ActionItemExtractionService::extractFromContent()` using Minutes `content`; returns `{ candidates: [...] }`
  - `POST /api/minutes/{minutesId}/save-extracted-action-items` → `ActionItemExtractionService::saveExtracted()` with body `{ confirmed: [...] }`; validates minutes `lifecycle` is not `published`; returns `{ saved: N }`
- [ ] 4.3 Register both routes in `appinfo/routes.php`
- [ ] 4.4 Register `ActionItemExtractionService` in DI container
- [ ] 4.5 Write PHPUnit tests in `tests/Unit/Service/ActionItemExtractionServiceTest.php` tagged `@spec` covering: `extractFromContent` detects `Actie:` marker; `extractFromContent` detects `wordt verzocht` phrase; `extractFromContent` returns empty for content with no markers; `extractFromContent` matches known participant name in line; minimum 4 test methods

## 5. Backend — DecisionNotificationService

- [ ] 5.1 Create `lib/Service/DecisionNotificationService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5` with:
  - `notifyOnPublish(string $decisionId): int` — fetches Decision via `ObjectService.findObject()`; reads `decision_notify_roles` from `IAppConfig` (default: `['chair', 'secretary', 'member']`); resolves recipients from Memberships of the linked GovernanceBody with matching roles via `ObjectService.findAll()`; sends notification via `NotificationService` for each recipient with title, outcome, decisionDate, and deep link; returns count of notifications sent
  - `resolveRecipients(string $decisionId, array $roles): array` — queries Memberships for the linked GovernanceBody filtered by roles; returns array of user display names
- [ ] 5.2 Extend `DecisionService.php` (from T1) with a call to `DecisionNotificationService::notifyOnPublish()` in the publication flow — after `isPublished: true` is saved by `ObjectService.saveObject()`; inject `DecisionNotificationService` via constructor; tag the modification with `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5`
- [ ] 5.3 Register `DecisionNotificationService` in DI container
- [ ] 5.4 Write PHPUnit tests in `tests/Unit/Service/DecisionNotificationServiceTest.php` tagged `@spec` covering: `notifyOnPublish` sends notifications to chair and secretary by default; `notifyOnPublish` sends zero notifications when no Memberships found; `resolveRecipients` filters by correct roles; `notifyOnPublish` uses configured roles from IAppConfig; minimum 4 test methods

## 6. Backend — Minutes Approval Notification Hook

- [ ] 6.1 Extend `lib/Service/MinutesService.php` (or create if not present) with `notifyApproversOnSubmit(string $minutesId, string $actorId): int` tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6`:
  - Resolves the linked GovernanceBody via Meeting relation
  - Fetches all active Memberships with `role: chair` or `role: secretary`
  - Sends a Nextcloud notification via `NotificationService` to each user: title "Notulen ter goedkeuring: {minutes.title}", body includes deep link to Minutes detail
  - Returns count of notifications sent
- [ ] 6.2 Extend `lib/Controller/MinutesController.php` with `POST /api/minutes/{minutesId}/submit-for-approval`:
  - Verifies Minutes `lifecycle == 'draft'` on backend
  - Calls `WorkflowEngineController` to transition `draft → review`
  - Calls `MinutesService::notifyApproversOnSubmit()`
  - Returns `{ lifecycle: 'review', notified: N }`
- [ ] 6.3 Register the route in `appinfo/routes.php`
- [ ] 6.4 Write PHPUnit tests in `tests/Unit/Service/MinutesServiceTest.php` tagged `@spec` covering: `notifyApproversOnSubmit` sends notifications to chair and secretary; `notifyApproversOnSubmit` returns 0 when no GovernanceBody linked; endpoint returns 409 when Minutes not in `draft`; minimum 3 test methods

## 7. Frontend — ActionItemAnalyticsWidget

- [ ] 7.1 Create `src/components/ActionItemAnalyticsWidget.vue` — dashboard widget that replaces or extends the existing open-count KPI card; on `created()` calls `GET /api/analytics/action-items` (current year), `GET /api/analytics/action-items/completion-rates`, and `GET /api/analytics/action-items/my-items` in `Promise.all`; all errors caught in `try/catch` with user-facing error message using `t()`
- [ ] 7.2 Render KPI cards in `ActionItemAnalyticsWidget.vue` using `CnStatsBlock` (4 cards: total open, total overdue, completed this month, average days-to-close); use `CnChartWidget` (bar type) for the per-meeting completion rate chart with translated axis labels; use a `CnDataTable` for the "My Action Items" grouped list with `CnStatusBadge` per row; group headers use `--color-error` (overdue) and `--color-warning` (this week) CSS variables — no hardcoded hex values
- [ ] 7.3 Add row click handler to the "My Action Items" list: clicking navigates to `ActionItemDetail` route with the item's id; use `$router.push({ name: 'ActionItemDetail', params: { id: item.id } })`
- [ ] 7.4 Register `ActionItemAnalyticsWidget.vue` in the Dashboard page (`src/views/Dashboard.vue`) replacing or augmenting the existing simple KPI cards
- [ ] 7.5 All strings in `ActionItemAnalyticsWidget.vue` MUST use `t(appName, '...')`; no hardcoded Dutch or English strings in template

## 8. Frontend — LiveDecisionPanel

- [ ] 8.1 Create `src/components/LiveDecisionPanel.vue` — embedded in `MeetingDetail.vue` as a "Besluiten" tab; displays two modes: live-entry form (when `meeting.lifecycle == 'opened'`) and read-only list (all other states)
- [ ] 8.2 Live-entry form in `LiveDecisionPanel.vue`:
  - Fields: `title` (required text input), `text` (required textarea), `outcome` (required `NcSelect` with options `adopted`/`rejected`), `legalBasis` (optional text input)
  - "Opslaan" button calls `POST /api/meetings/{meetingId}/live-decisions` wrapped in `try/catch`; on success, appends the new Decision to the panel list; clears the form
  - Inline validation: "Titel is verplicht" shown below the title input when empty on submit
  - All field labels and validation messages use `t()`
- [ ] 8.3 Read-only mode in `LiveDecisionPanel.vue`: shows the list of Decisions linked to the Meeting fetched via `relationsPlugin.fetchRelations()`; displays title, outcome badge (`CnStatusBadge`), and `decisionDate`; shows status notice "Live invoer beschikbaar wanneer de vergadering geopend is" using `t()`
- [ ] 8.4 Inject `LiveDecisionPanel.vue` into `src/views/MeetingDetail.vue` as a new tab, registered in the `components: {}` section; import from relative path

## 9. Frontend — ALV Minutes Actions

- [ ] 9.1 Create `src/components/ALVMinutesActions.vue` — embedded in `MinutesDetail.vue`; contains two action buttons: "Genereer ALV-notulen" and "Distribueren aan leden"
- [ ] 9.2 "Genereer ALV-notulen" in `ALVMinutesActions.vue`:
  - Only visible when linked Meeting `meetingType` matches `alv` (case-insensitive check)
  - On click: calls `POST /api/minutes/{id}/generate-alv`; shows preview in `NcDialog` with "Toepassen" and "Annuleren" buttons
  - On "Toepassen": updates the Minutes `content` via `objectStore.saveObject()`
  - Validation notice shown if `meetingType` does not match ALV; all strings via `t()`
- [ ] 9.3 "Distribueren aan leden" in `ALVMinutesActions.vue`:
  - Only visible when `minutes.lifecycle` is `approved` or `signed`
  - On click: calls `POST /api/minutes/{id}/distribute`; shows preview dialog with recipient count
  - Displays success notification with "{N} leden geïnformeerd" or warning when N == 0; all strings via `t()`
- [ ] 9.4 Inject `ALVMinutesActions.vue` into `src/views/MinutesDetail.vue`; import and register in `components: {}`

## 10. Frontend — Minutes Approval Submission Button

- [ ] 10.1 Add "Ter goedkeuring indienen" button to `src/views/MinutesDetail.vue`:
  - Visible only when `minutes.lifecycle == 'draft'`
  - On click: calls `POST /api/minutes/{id}/submit-for-approval` wrapped in `try/catch`; on success, refreshes the Minutes lifecycle display and shows success notification "Notulen ter goedkeuring ingediend — goedkeurers zijn gewaarschuwd"
  - After successful submission, button label changes to "In behandeling" (disabled); use `v-if`/`v-else` on the lifecycle state
  - All strings via `t()`
- [ ] 10.2 Show a role-restricted approval banner: when `minutes.lifecycle == 'review'` and the current user does NOT have role `chair` or `secretary` (read from settings store), display a `CnStatusBadge` banner "In behandeling — wacht op goedkeuring" using `--color-warning`

## 11. Frontend — Action Item Extraction Modal

- [ ] 11.1 Create `src/components/ActionItemExtractionModal.vue` — modal dialog for the action item extraction preview:
  - On open: calls `POST /api/minutes/{id}/extract-action-items`; displays a list of candidate items each with: checkbox (checked by default), editable title input, optional assignee text input, optional dueDate date input
  - Empty state: notice "Geen actiepunten gevonden — voeg handmatig toe"
  - "Geselecteerde actiepunten opslaan" button: calls `POST /api/minutes/{id}/save-extracted-action-items` with `{ confirmed: [...checked candidates] }`; on success, shows "{N} actiepunten aangemaakt"; closes modal
  - "Annuleren" closes without saving; wrapped in `try/catch`; all strings via `t()`
- [ ] 11.2 Add "Actiepunten extraheren" button to `src/views/MinutesDetail.vue`:
  - Visible when `minutes.lifecycle` is `draft`, `review`, or `approved`
  - On click: opens `ActionItemExtractionModal.vue`
  - Register modal in `components: {}`; import from relative path

## 12. Frontend — Decision Rationale Section

- [ ] 12.1 Add "Overwegingen en motivering" section to `src/views/DecisionDetail.vue`:
  - View mode: reads the Decision's `notes` array for the entry with `label: "overwegingen"`; if found, renders text in a `CnDetailCard` titled "Overwegingen en motivering"; if not found, shows an empty state "Geen overwegingen vastgelegd"
  - Edit mode: renders a `<textarea>` labelled "Overwegingen" bound to the `overwegingen` note value; on save, constructs the notes array entry `{ label: 'overwegingen', content: <value> }` and saves via `objectStore.saveObject()` wrapped in `try/catch`
  - The section is collapsible if empty using Nextcloud `NcCollapsible` or equivalent
  - All field labels via `t()`

## 13. Frontend — Store and Route Updates

- [ ] 13.1 Ensure `AnalyticsController` API is consumed via `axios` from `@nextcloud/axios` in `ActionItemAnalyticsWidget.vue` — never raw `fetch()` (CSRF); no new Pinia store is needed for analytics (non-persisted data)
- [ ] 13.2 Ensure `Meeting` object type is registered in `src/store/store.js` with `relationsPlugin` so `MeetingDetail.vue` can call `fetchRelations()` for linked Decisions and Minutes; if already registered from p2-meeting-management, verify `relationsPlugin` is included

## 14. Translations (ADR-007)

- [ ] 14.1 Add Dutch (nl) translation keys in `l10n/nl.js` and `l10n/nl.json` for all new user-visible strings including: analytics KPI labels (Openstaand, Achterstallig, Afgerond deze maand, Gem. doorlooptijd), group headers (Achterstallig, Deze week, Later), live panel labels (Besluit opslaan, Vergadering moet geopend zijn voor live invoer), ALV action labels (Genereer ALV-notulen, Distribueren aan leden, Notulen distributiepreviev), approval labels (Ter goedkeuring indienen, In behandeling, Wacht op goedkeuring), extraction modal labels (Actiepunten extraheren, Geselecteerde actiepunten opslaan, Geen actiepunten gevonden), rationale labels (Overwegingen en motivering, Geen overwegingen vastgelegd), notification labels
- [ ] 14.2 Add English (en) translation keys in `l10n/en.js` and `l10n/en.json` matching all Dutch keys; verify zero gaps between nl.json and en.json

## 15. Settings Extension (ADR-006)

- [ ] 15.1 Add "Besluitnotificaties" section to the admin settings page (`src/views/Settings.vue`) — a multi-select or checkbox group for `decision_notify_roles` (options: chair, secretary, member, observer); default selection: chair + secretary + member; save via `POST /api/settings`
- [ ] 15.2 Extend `lib/Service/SettingsService.php::getSettings()` to include `decisionNotifyRoles: array` in the settings response; read from `IAppConfig` with default fallback `['chair', 'secretary', 'member']`

## 16. Testing (ADR-008)

- [ ] 16.1 Write PHPUnit tests for `ActionItemAnalyticsServiceTest.php` (task 1.5): summary returns correct overdue count; completion rates return 0% for zero completed items; my-items groups overdue correctly; average days-to-close calculation; minimum 4 test methods
- [ ] 16.2 Write PHPUnit tests for `LiveDecisionServiceTest.php` (task 2.5): records decision and links to meeting; throws 409 for non-opened meeting; creates draft minutes when none exist; returns existing minutes slug; minimum 4 test methods
- [ ] 16.3 Write PHPUnit tests for `ALVMinutesServiceTest.php` (task 3.5): generates quorum statement for valid ALV meeting; returns validation error for non-ALV meeting; distributes returns 0 for no participants; throws when lifecycle not approved; minimum 4 test methods
- [ ] 16.4 Write PHPUnit tests for `ActionItemExtractionServiceTest.php` (task 4.5): detects `Actie:` marker; detects `wordt verzocht`; returns empty for unmatched content; matches participant name; minimum 4 test methods
- [ ] 16.5 Write PHPUnit tests for `DecisionNotificationServiceTest.php` (task 5.4): sends notifications to chair and secretary; returns 0 for no memberships; resolveRecipients filters by roles; uses configured roles from IAppConfig; minimum 4 test methods
- [ ] 16.6 Write Newman/Postman integration tests in `tests/integration/minutes-decisions-t3.json` covering all new endpoints: `GET /api/analytics/action-items`, `GET /api/analytics/action-items/completion-rates`, `GET /api/analytics/action-items/my-items`, `POST /api/meetings/{id}/live-decisions`, `POST /api/minutes/{id}/generate-alv`, `POST /api/minutes/{id}/distribute`, `POST /api/minutes/{id}/submit-for-approval`, `POST /api/minutes/{id}/extract-action-items`, `POST /api/minutes/{id}/save-extracted-action-items`
- [ ] 16.7 Write Playwright browser tests for: REQ-MAA-001 (analytics KPIs display), REQ-LDR-001 (live decision form saves and appears in list), REQ-LDR-003 (panel disabled for non-opened meeting), REQ-ALV-001 (ALV template generated with quorum statement), REQ-ALV-003 (distribute blocked for draft minutes), REQ-MAW-001 (submit-for-approval transitions lifecycle and shows button change), REQ-AAI-001 (extraction modal opens with candidates), REQ-DNT-001 (notification triggered on publish), REQ-DRT-001 (rationale saved and displayed)

## 17. Seed Data

- [ ] 17.1 Add seed data for ALV Minutes (3 objects) and extracted ActionItems (5 objects) from `design.md` to `lib/Settings/decidesk_register.json` under `x-openregister.seedData` using the `@self` envelope (`register`, `schema`, `slug`) with Dutch values
- [ ] 17.2 Verify the repair step imports seed data on a fresh install without errors (slug-based upsert, idempotent)

## 18. Verification

- [ ] 18.1 Verify analytics: open Dashboard; confirm KPI cards show open, overdue, completed-this-month, avg-days-to-close values; confirm bar chart renders with last 6 meetings; confirm "My Action Items" groups items correctly (create a test item with past dueDate and verify it appears in "Achterstallig")
- [ ] 18.2 Verify live decision panel: navigate to a Meeting in `lifecycle: opened`; open "Besluiten" tab; submit a decision with title and text; confirm it appears in the panel list; confirm a draft Minutes is auto-created if none existed; navigate to a Meeting in `lifecycle: scheduled`; confirm the entry form is replaced by read-only list
- [ ] 18.3 Verify ALV template: open a Minutes linked to a Meeting with `meetingType: alv`; click "Genereer ALV-notulen"; confirm preview dialog shows quorum statement and agenda sections; click "Toepassen"; confirm content is updated; open a Minutes linked to a non-ALV meeting; confirm the validation notice appears and no dialog opens
- [ ] 18.4 Verify ALV distribution: set a Minutes to `lifecycle: approved`; click "Distribueren aan leden"; confirm recipient count in preview; confirm notifications appear in Nextcloud for active GovernanceBody participants; try with `lifecycle: draft` and confirm button is not visible
- [ ] 18.5 Verify minutes approval submission: open a Minutes in `draft`; click "Ter goedkeuring indienen"; confirm lifecycle transitions to `review`; confirm Nextcloud notifications appear for chair/secretary users; confirm button shows "In behandeling" after submission
- [ ] 18.6 Verify action item extraction: paste minutes content with "Actie: griffier verstuurt notulen" into a Minutes draft; click "Actiepunten extraheren"; confirm modal opens with the candidate checked; edit the title and assign a user; click "Geselecteerde actiepunten opslaan"; confirm the ActionItem appears linked to the Minutes
- [ ] 18.7 Verify decision rationale: open a Decision in edit mode; enter text in "Overwegingen"; save; confirm the text appears in view mode under "Overwegingen en motivering"; open the notes in the sidebar and confirm a note with `label: overwegingen` exists
- [ ] 18.8 Verify decision notification: publish a Decision linked to a GovernanceBody with active Memberships; confirm Nextcloud notifications appear for chair, secretary, and member users; change `decision_notify_roles` to `chair` only in settings; publish another decision; confirm only chair receives notification
- [ ] 18.9 Verify all `@spec` PHPDoc tags are present on new and extended PHP classes and public methods linking to `openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md`
- [ ] 18.10 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded Dutch or English strings in templates or JS
- [ ] 18.11 Verify no hardcoded CSS colours — only Nextcloud CSS variables used for overdue group headers, overdue badges, and analytics indicators
- [ ] 18.12 Verify `Decision`, `Minutes`, `ActionItem`, and `Meeting` schemas in OpenRegister still match ADR-000 exactly after implementation — no extra properties added; rationale uses built-in `notes` array only
