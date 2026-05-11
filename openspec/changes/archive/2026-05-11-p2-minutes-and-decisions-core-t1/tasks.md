## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm no custom CRUD, export, search, file upload, notification, or audit code is needed: all use `ObjectService`, `ExportService`, `IndexService`, `FileService`, `NotificationService`, `AuditTrailService` from OpenRegister platform
- [ ] 0.2 Confirm `Decision`, `Minutes`, `ActionItem`, and `DigitalDocument` entities are used as-is from ADR-000 — no schema properties added or renamed; `spoed` tag uses built-in `tags` array; document type uses existing `documentType` field on DigitalDocument
- [ ] 0.3 Confirm `DecisionDocumentService` (PDF template rendering), `StatutoryDeadlineService` (deadline calculation), and `DecisionService` (urgent flag + notifications) are the only truly custom integrations — no overlap with existing OpenRegister WebhookService or WorkflowEngineController for these specific use cases
- [ ] 0.4 Confirm `generateDecisionList()` is added as a method on the existing `MinutesGenerationService` from p2-minutes-and-decisions — no duplicate service class

## 1. Backend — DecisionDocumentService

- [ ] 1.1 Create `lib/Service/DecisionDocumentService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t1/tasks.md#task-1` with the following public methods:
  - `generatePermitDecision(string $decisionId): string` — fetches Decision via `ObjectService`; renders the Dutch permit decision PDF template with `title`, `text`, `decisionDate`, `legalBasis`, and the statutory deadline from `StatutoryDeadlineService`; uploads PDF via `FileService`; creates DigitalDocument with `documentType: permit-decision` and links to Decision; returns the DigitalDocument slug
  - `generateWooDisclosure(string $decisionId): string` — fetches Decision; renders Dutch Woo disclosure template; uploads PDF via `FileService`; creates DigitalDocument with `documentType: woo-disclosure` and links to Decision; returns DigitalDocument slug
  - `generateContract(string $decisionId): string` — fetches Decision; renders Dutch contract template; uploads PDF via `FileService`; creates DigitalDocument with `documentType: contract` and links to Decision; returns DigitalDocument slug
  - `generateAcknowledgement(string $decisionId, string $actorDisplayName): string` — fetches Decision; calls `StatutoryDeadlineService::calculate(legalBasis)` to get deadline date; renders Dutch acknowledgement template with deadline date inserted; uploads PDF; creates DigitalDocument with `documentType: case-decision`; creates ActionItem with `title: "Wettelijke beslistermijn [article]"`, `dueDate`, `taskStatus: open`, `assignee: $actorDisplayName`, linked to Decision; returns generated document preview text
- [ ] 1.2 Create Dutch PDF template strings as PHP constants in `lib/Service/DecisionDocumentService.php`:
  - `PERMIT_DECISION_TEMPLATE` — includes placeholders for `{title}`, `{text}`, `{decisionDate}`, `{legalBasis}`, `{deadline}`, `{rechtsmiddelenclausule}`
  - `WOO_DISCLOSURE_TEMPLATE` — includes placeholders for `{title}`, `{text}`, `{decisionDate}`, `{requester}`, `{scope}`
  - `CONTRACT_TEMPLATE` — includes placeholders for `{title}`, `{text}`, `{decisionDate}`, `{parties}`, `{legalBasis}`
  - `ACKNOWLEDGEMENT_TEMPLATE` — includes placeholders for `{title}`, `{decisionDate}`, `{deadline}`, `{legalArticle}`
- [ ] 1.3 Register `DecisionDocumentService` in DI container (`lib/AppInfo/Application.php`)
- [ ] 1.4 Write PHPUnit tests in `tests/Unit/Service/DecisionDocumentServiceTest.php` tagged `@spec` covering: `generatePermitDecision` happy path creates DigitalDocument with correct type; `generateAcknowledgement` creates ActionItem with correct dueDate; `generateAcknowledgement` with unknown legalBasis creates ActionItem without dueDate; `generateWooDisclosure` stores file attachment on Decision; minimum 4 test methods

## 2. Backend — StatutoryDeadlineService

- [ ] 2.1 Create `lib/Service/StatutoryDeadlineService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t1/tasks.md#task-2` with:
  - `calculate(string $legalBasis): ?\DateTimeInterface` — parses `legalBasis` string for known article identifiers (e.g., "Awb art. 4:13", "Awb art. 3.9 Wabo", "Woo art. 4.1"); looks up duration in the configurable article-to-duration map from `IAppConfig`; returns `now() + duration` as a `DateTime` object; returns `null` if article is not in the map
  - `getArticleMap(): array` — reads the JSON mapping from `IAppConfig` key `statutory_deadline_map`; falls back to a built-in default mapping with common Dutch administrative law articles and their deadline durations in days
- [ ] 2.2 Add default article-to-duration mapping as a PHP constant in `StatutoryDeadlineService.php`:
  - `"Awb art. 4:13"` → 56 days (8 weeks)
  - `"Awb art. 4:14"` → 56 days (8 weeks, extended)
  - `"Awb art. 7:10"` → 42 days (6 weeks, bezwaar)
  - `"Awb art. 3.9 Wabo"` → 56 days (8 weeks, omgevingsvergunning regulier)
  - `"Woo art. 4.1"` → 28 days (4 weeks)
- [ ] 2.3 Register `StatutoryDeadlineService` in DI container
- [ ] 2.4 Write PHPUnit tests in `tests/Unit/Service/StatutoryDeadlineServiceTest.php` tagged `@spec` covering: known article returns correct deadline date; unknown article returns null; custom map from IAppConfig overrides default; empty legalBasis returns null; minimum 4 test methods

## 3. Backend — DecisionService (Urgent Flag)

- [ ] 3.1 Create `lib/Service/DecisionService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t1/tasks.md#task-3` with:
  - `flagUrgent(string $decisionId, string $actorId, ?string $reason): void` — fetches Decision via `ObjectService`; verifies actor has role `chair` or `secretary` via `AuthorizationService`; adds tag `spoed` to `tags` array if not already present; saves via `ObjectService.saveObject()`; sends priority `NotificationService` notifications to all chair/secretary/legal-counsel users of the linked GovernanceBody; logs action with reason to `ActivityService`
  - `unflagUrgent(string $decisionId, string $actorId, string $reason): void` — fetches Decision; verifies role; removes `spoed` tag from `tags` array; adds a note to Decision with the reason; saves; logs to `ActivityService`; validates `reason` is non-empty (returns 400 if empty)
- [ ] 3.2 Create `lib/Controller/DecisionController.php` — thin controller (< 10 lines/method) tagged `@spec` with:
  - `POST /api/decisions/{id}/documents/permit` → `DecisionDocumentService::generatePermitDecision()`
  - `POST /api/decisions/{id}/documents/woo-disclosure` → `DecisionDocumentService::generateWooDisclosure()`
  - `POST /api/decisions/{id}/documents/contract` → `DecisionDocumentService::generateContract()`
  - `POST /api/decisions/{id}/documents/acknowledgement` → `DecisionDocumentService::generateAcknowledgement()`
  - `POST /api/decisions/{id}/urgent` → `DecisionService::flagUrgent()` (body: `{ reason? }`)
  - `DELETE /api/decisions/{id}/urgent` → `DecisionService::unflagUrgent()` (body: `{ reason }`)
- [ ] 3.3 Register all 6 routes in `appinfo/routes.php`; specific routes before wildcard `{slug}` routes
- [ ] 3.4 Register `DecisionService` and `DecisionController` in DI container
- [ ] 3.5 Write PHPUnit tests in `tests/Unit/Service/DecisionServiceTest.php` tagged `@spec` covering: `flagUrgent` adds spoed tag and sends notifications; `flagUrgent` blocked for member role; `unflagUrgent` removes tag and adds reason note; `unflagUrgent` blocked with empty reason; minimum 4 test methods

## 4. Backend — MinutesGenerationService Extension

- [ ] 4.1 Extend `lib/Service/MinutesGenerationService.php` from p2-minutes-and-decisions — add public method `generateDecisionList(string $minutesId): array` tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t1/tasks.md#task-4`:
  - Fetches Minutes via `ObjectService`; resolves linked Meeting via relation
  - Fetches all VotingRounds for the Meeting via `ObjectService.findAll()`, ordered by `closedAt`
  - For each closed VotingRound, fetches the linked Decision (if any) via OpenRegister relation
  - Builds a formatted Dutch decision list with: sequential number, decision title, outcome (Aangenomen / Verworpen), vote totals (Voor: X, Tegen: Y, Onthouding: Z), legalBasis
  - Returns `[ 'content' => '<formatted decision list>', 'warnings' => ['VotingRound {id} has no linked Decision'] ]`
- [ ] 4.2 Extend `lib/Controller/MinutesController.php` from p2-minutes-and-decisions — add action tagged `@spec`:
  - `POST /api/minutes/{minutesId}/generate-decision-list` → calls `MinutesGenerationService::generateDecisionList()`; returns `{ preview: '<formatted list>', warnings: [] }`
- [ ] 4.3 Register the new route in `appinfo/routes.php` before any wildcard `{slug}` routes
- [ ] 4.4 Write PHPUnit tests in `tests/Unit/Service/MinutesGenerationServiceTest.php` tagged `@spec` covering: `generateDecisionList` with 3 closed VotingRounds returns formatted list; `generateDecisionList` with VotingRound missing Decision returns warning; `generateDecisionList` with no closed VotingRounds returns empty content and no-rounds warning; minimum 3 new test methods (in addition to existing tests)

## 5. Backend — Settings Extension

- [ ] 5.1 Add "Wettelijke beslistermijnen" configuration section to the admin settings service — expose `statutory_deadline_map` as a JSON-editable config key via `IAppConfig`; validate that the value is valid JSON and all values are positive integers (days); store via `IAppConfig` with sensitive flag `false`
- [ ] 5.2 Extend `lib/Service/SettingsService.php::getSettings()` to include `statutoryDeadlineMap` in the settings response so the frontend can display configured deadlines

## 6. Frontend — DecisionDocumentPanel

- [ ] 6.1 Create `src/components/DecisionDocumentPanel.vue` — embedded in `DecisionDetail.vue`; displays a "Besluitdocumenten" section; fetches all DigitalDocuments linked to the Decision via `relationsPlugin`; renders each document with: name, `documentType` badge (`CnStatusBadge`), encodingFormat, contentSize, download button, and "Loskoppelen" action to remove the relation; groups documents by `documentType`; empty state shows "Geen besluitdocumenten gekoppeld"; all strings via `t()`
- [ ] 6.2 Add document generation action buttons to `DecisionDocumentPanel.vue`:
  - "Vergunningsbesluit genereren" — visible when `legalBasis` contains a permit regulation reference; calls `POST /api/decisions/{id}/documents/permit`; shows loading state; adds generated document to the panel on success
  - "Woo-openbaarmakingsbesluit genereren" — always visible; calls `POST /api/decisions/{id}/documents/woo-disclosure`
  - "Contract genereren" — visible when the Decision text or notes reference a procurement award; calls `POST /api/decisions/{id}/documents/contract`
  - "Ontvangstbevestiging genereren" — always visible; calls `POST /api/decisions/{id}/documents/acknowledgement`; on success shows the statutory deadline that was inserted and the ActionItem that was created
- [ ] 6.3 Inject `DecisionDocumentPanel.vue` into `src/views/DecisionDetail.vue` as a new section below the Decision text and above the linked ActionItems section

## 7. Frontend — Statutory Deadline Display

- [ ] 7.1 Add statutory deadline section to `src/views/DecisionDetail.vue` — below the `legalBasis` field, compute `deadlineDate` by calling the statutory deadline calculation API or deriving from linked ActionItem with title containing "Wettelijke beslistermijn"; display deadline as "Wettelijke beslistermijn: {date} ({N} dagen resterend)" or "{N} dagen overschreden" if past; use Nextcloud CSS variable `--color-warning` for approaching (<7 days) and `--color-error` for overdue; no hardcoded colours
- [ ] 7.2 Add client-side overdue deadline warning to `src/views/ActionItems.vue` — compute `isStatutoryDeadline` as `title.startsWith("Wettelijke beslistermijn")`; render these items with a distinct `CnStatusBadge` variant "Beslistermijn" in addition to the existing overdue badge; all display logic uses Nextcloud CSS variables

## 8. Frontend — Urgent Decision UI

- [ ] 8.1 Add "Als spoedbesluit markeren" action to `src/views/DecisionDetail.vue` — visible only when current user role is `chair` or `secretary` (read from settings/store) and `tags` does not include `spoed`; clicking opens a confirmation `NcDialog` with an optional reason text input; on confirm calls `POST /api/decisions/{id}/urgent` with `{ reason }`; on success, refreshes the decision and shows the urgent badge; button is hidden for member/observer/guest roles
- [ ] 8.2 Add "Spoedbesluit verwijderen" action to `src/views/DecisionDetail.vue` — visible only when `tags` includes `spoed` and current user is chair or secretary; clicking opens a dialog with a mandatory reason input; on confirm calls `DELETE /api/decisions/{id}/urgent` with `{ reason }`; validates that reason is non-empty before enabling the confirm button
- [ ] 8.3 Add urgent badge rendering to `src/views/Decisions.vue` — in the Decision list, when `tags` includes `spoed`, display a "Spoed" `CnStatusBadge` in the list row; add a "Spoedbesluit" toggle to the `CnFilterBar` that filters by `tags: spoed`; sort urgent decisions above non-urgent decisions by default (secondary sort key)
- [ ] 8.4 Add "Besluitenlijst genereren" button to `src/views/MinutesDetail.vue` — visible when Minutes `lifecycle` is `draft` or `review`; clicking calls `POST /api/minutes/{id}/generate-decision-list`; shows the preview and any warnings in a `NcDialog` with two action buttons: "Toevoegen aan notulen" (append) and "Vervangen" (replace); on confirm, updates the Minutes `content` field via `objectStore.saveObject()` with the generated decision list text; on "Geen stemrondes" response, shows an inline notice

## 9. Frontend — Store and Route Updates

- [ ] 9.1 Ensure `DigitalDocument` object type is registered in `src/store/store.js` via `objectStore.registerObjectType("DigitalDocument", "digital-document", "decidesk")` with `relationsPlugin` and `filesPlugin`; if not already registered from p1-schemas-and-data-model, add it now
- [ ] 9.2 Extend `DecisionDetail` route store logic to fetch linked DigitalDocuments via `relationsPlugin.fetchRelations()` when the Decision detail page loads; expose them to `DecisionDocumentPanel.vue` via props or store

## 10. Translations (ADR-007)

- [ ] 10.1 Add Dutch (nl) translation keys in `l10n/nl.js` and `l10n/nl.json` for all new user-visible strings including: document type labels (Besluitdocument, Vergunningsbesluit, Woo-openbaarmakingsbesluit, Contract, Ontvangstbevestiging), document generation button labels, urgent flag dialog labels, statutory deadline labels (Wettelijke beslistermijn, N dagen resterend, N dagen overschreden), decision list generation button and dialog labels, warning messages
- [ ] 10.2 Add English (en) translation keys in `l10n/en.js` and `l10n/en.json` matching all Dutch keys

## 11. Settings (ADR-006)

- [ ] 11.1 Add "Wettelijke beslistermijnen" section to the admin settings page (`src/views/Settings.vue`) — JSON editor field for the `statutory_deadline_map` config key showing article identifiers and deadline durations in days; save via `POST /api/settings`; validate JSON structure client-side before saving; show validation error inline if invalid
- [ ] 11.2 Add "Besluitdocumenten" section to the admin settings page — informational card explaining the configured document templates and a "Sjablonen herladen" button that calls `POST /api/settings/load` to re-import default templates

## 12. Testing (ADR-008)

- [ ] 12.1 Write PHPUnit tests for `DecisionDocumentServiceTest.php` (task 1.4): permit decision PDF creates DigitalDocument with correct type and relation; acknowledgement creates ActionItem with correct dueDate; unknown legalBasis produces null dueDate; Woo disclosure creates DigitalDocument attachment; minimum 4 test methods
- [ ] 12.2 Write PHPUnit tests for `StatutoryDeadlineServiceTest.php` (task 2.4): known Awb art. 4:13 returns 56-day deadline; known Woo art. 4.1 returns 28-day deadline; unknown article returns null; custom IAppConfig map overrides default; minimum 4 test methods
- [ ] 12.3 Write PHPUnit tests for `DecisionServiceTest.php` (task 3.5): flagUrgent adds spoed tag; flagUrgent blocked for member; unflagUrgent removes tag and creates note; unflagUrgent blocked with empty reason; minimum 4 test methods
- [ ] 12.4 Write PHPUnit tests for `MinutesGenerationServiceTest.php` extension (task 4.4): generateDecisionList with 3 VotingRounds produces correct formatted list; missing Decision on VotingRound produces warning; no closed VotingRounds produces no-rounds warning; minimum 3 new test methods
- [ ] 12.5 Write Newman/Postman integration tests in `tests/integration/minutes-decisions-t1.json` for all 6 new API endpoints: `POST /api/decisions/{id}/documents/permit`, `POST /api/decisions/{id}/documents/woo-disclosure`, `POST /api/decisions/{id}/documents/contract`, `POST /api/decisions/{id}/documents/acknowledgement`, `POST /api/decisions/{id}/urgent`, `DELETE /api/decisions/{id}/urgent`, `POST /api/minutes/{id}/generate-decision-list`
- [ ] 12.6 Write Playwright browser tests for: REQ-CDM-001 (mark document as case-decision), REQ-PPD-001 (permit decision PDF generation), REQ-SDL-001 (statutory deadline displayed on Decision detail), REQ-SDL-002 (acknowledgement creates ActionItem), REQ-URG-001 (urgent flag visible for chair, hidden for member), REQ-URG-003 (urgent decisions sorted first in list), REQ-ADL-001 (decision list generated and appended to Minutes), REQ-ATR-001 (audit trail entry on publish action)

## 13. Seed Data

- [ ] 13.1 Add seed data for DigitalDocument (5 objects) and statutory deadline ActionItem (3 objects) from `design.md` to `lib/Settings/decidesk_register.json` under `x-openregister.seedData` using the `@self` envelope (`register`, `schema`, `slug`) with Dutch values
- [ ] 13.2 Verify the repair step imports seed data on a fresh install without errors (slug-based upsert)

## 14. Verification

- [ ] 14.1 Verify case decision document marking: mark a DigitalDocument as `case-decision`, confirm it appears in the "Besluitdocumenten" panel of the linked Decision with the correct badge; confirm "Loskoppelen" removes the relation without deleting the document
- [ ] 14.2 Verify permit decision PDF generation: click "Vergunningsbesluit genereren" on a Decision with a permit legalBasis; confirm a PDF file is attached to the Decision; confirm a DigitalDocument with `documentType: permit-decision` is created and appears in the panel
- [ ] 14.3 Verify Woo disclosure generation: click "Woo-openbaarmakingsbesluit genereren"; confirm DigitalDocument with `documentType: woo-disclosure` is created and linked
- [ ] 14.4 Verify contract generation: click "Contract genereren"; confirm DigitalDocument with `documentType: contract` is created and linked
- [ ] 14.5 Verify statutory deadline: open a Decision with `legalBasis: "Awb art. 4:13"`; confirm the deadline date (56 days from decisionDate) is displayed; click "Ontvangstbevestiging genereren"; confirm an ActionItem with `title: "Wettelijke beslistermijn Awb art. 4:13"` and correct `dueDate` is created and linked
- [ ] 14.6 Verify urgent flag: log in as chair; open a Decision; click "Als spoedbesluit markeren"; confirm `spoed` tag is added; confirm urgent badge appears in list and detail; confirm notifications sent (check Nextcloud notifications); log in as member; confirm "Als spoedbesluit markeren" button is not visible
- [ ] 14.7 Verify urgent removal: log in as chair; click "Spoedbesluit verwijderen"; try submitting with empty reason — confirm blocked; enter reason and confirm; verify `spoed` tag is removed and badge disappears; verify reason note is on the Decision object; verify audit trail entry is present
- [ ] 14.8 Verify decision list generation: open a Minutes in draft linked to a Meeting with closed VotingRounds; click "Besluitenlijst genereren"; confirm preview dialog appears with formatted decision list; click "Toevoegen aan notulen"; confirm the Minutes `content` field now contains the decision list; verify no content is lost from existing content
- [ ] 14.9 Verify audit trail: perform publish, urgent flag, and document generation actions on a Decision; open the Audit tab in the sidebar; confirm entries are present with actor, timestamp, before/after values for each action; click "Exporteren" and confirm CSV export contains all entries
- [ ] 14.10 Verify all `@spec` PHPDoc tags are present on new and extended PHP classes and public methods linking to `openspec/changes/p2-minutes-and-decisions-core-t1/tasks.md`
- [ ] 14.11 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded Dutch or English strings in templates or JS
- [ ] 14.12 Verify no hardcoded CSS colours — only Nextcloud CSS variables used for urgent badges, deadline countdowns, and overdue indicators
- [ ] 14.13 Verify `Decision`, `Minutes`, `ActionItem`, and `DigitalDocument` schemas in OpenRegister still match ADR-000 exactly after implementation — no extra properties added; only `tags` and existing fields used
