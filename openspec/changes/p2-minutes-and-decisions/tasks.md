## 1. Backend — Minutes Generation Service

- [x] 1.1 Create `lib/Service/MinutesGenerationService.php` — stateless service with `generateDraft(string $minutesId): string` that fetches the linked Meeting via OpenRegister relations, then retrieves its AgendaItems (ordered by `orderNumber`), Motions, VotingRounds, and Decisions, and renders them into a structured Dutch text template; annotate with `@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1`
- [x] 1.2 Create `lib/Controller/MinutesController.php` — thin controller with a single `generateDraft(string $minutesId): JSONResponse` action (POST); calls `MinutesGenerationService::generateDraft()`; returns `{ preview: '<generated text>' }`; annotate with `@spec` tags
- [x] 1.3 Register route in `appinfo/routes.php`: `POST /api/minutes/{minutesId}/generate-draft` → `MinutesController::generateDraft`; place this specific route BEFORE any wildcard `{slug}` route
- [x] 1.4 Register `MinutesGenerationService` and `MinutesController` in DI container (`lib/AppInfo/Application.php`)

## 2. Backend — Overdue Action Items Background Job

- [x] 2.1 Create `lib/BackgroundJob/OverdueActionItemsJob.php` — extends `\OCP\BackgroundJob\TimedJob`; runs daily; queries all ActionItems where `taskStatus` is `open` or `in-progress` and `dueDate < now()`; calls `ObjectService::saveObject()` to set `taskStatus: overdue` for each; annotate with `@spec` tags
- [x] 2.2 Register `OverdueActionItemsJob` in `appinfo/info.xml` under `<background-jobs>` so Nextcloud schedules it automatically
- [x] 2.3 Register `OverdueActionItemsJob` in DI container (`lib/AppInfo/Application.php`)

## 3. Backend — Settings Extension

- [x] 3.1 Extend `lib/Service/SettingsService.php::getSettings()` to include schema and register slugs for the three new entities (Minutes, Decision, ActionItem) so the frontend `initializeStores()` can register their object stores
- [x] 3.2 Verify `ConfigurationService::importFromApp('decidesk')` is called by the existing repair step and that the Minutes, Decision, and ActionItem seed data objects defined in `design.md` are present in `lib/Settings/decidesk_register.json` under `x-openregister.seedData`

## 4. Frontend — Store and Route Registration

- [x] 4.1 Create Pinia object stores in `src/store/modules/` for Minutes, Decision, and ActionItem using `createObjectStore(name)` with `files`, `auditTrails`, and `relations` plugins
- [x] 4.2 Extend `src/store/store.js::initializeStores()` to call `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for Minutes, Decision, and ActionItem after fetching settings
- [x] 4.3 Add named routes to `src/router/index.js`: `Minutes` (`/minutes`), `MinutesDetail` (`/minutes/:id`), `Decisions` (`/decisions`), `DecisionDetail` (`/decisions/:id`), `ActionItems` (`/action-items`), `ActionItemDetail` (`/action-items/:id`)
- [x] 4.4 Add `NcAppNavigationItem` entries to `src/components/MainMenu.vue` for Notulen (Minutes), Besluiten (Decisions), and Actiepunten (Action Items) with MDI icons and `:to` route bindings; all labels use `t(appName, 'text')`

## 5. Frontend — Minutes Views

- [x] 5.1 Create `src/views/Minutes.vue` — `CnIndexPage` with `useListView('minutes', { sidebarState, objectStore: minutesStore })`; columns: title, lifecycle (with `CnStatusBadge`), version, approvedAt; row click → `MinutesDetail`; all strings via `t()`
- [x] 5.2 Create `src/views/MinutesDetail.vue` — `CnDetailPage` with `useDetailView`; property sections via `CnDetailCard` (title, lifecycle, version, approvedAt, signedBy); `CnTimelineStages` showing `draft → review → approved → signed → published` progression; "Concept genereren" button that calls `POST /api/minutes/:id/generate-draft`, shows a preview modal (`NcDialog`), and on confirmation updates the `content` field; Edit and Delete header actions; `CnObjectSidebar` with Files, Audit, Notes, Tasks tabs; all strings via `t()`
- [x] 5.3 Ensure lifecycle transition buttons ("Ter goedkeuring indienen", "Goedkeuren", "Ondertekenen", "Publiceren") are shown only when the current `lifecycle` allows that transition; each button calls `ObjectService::saveObject()` with the new lifecycle value; on `approved` transition the current user's display name is appended to `signedBy` and `approvedAt` is set; all button labels via `t()`

## 6. Frontend — Decision Views

- [x] 6.1 Create `src/views/Decisions.vue` — `CnIndexPage` with `useListView('decision', { sidebarState, objectStore: decisionStore })`; columns: title, outcome (with `CnStatusBadge`), decisionDate, isPublished; `CnFilterBar` with filters for `outcome` and `isPublished`; row click → `DecisionDetail`; all strings via `t()`
- [x] 6.2 Create `src/views/DecisionDetail.vue` — `CnDetailPage` with `useDetailView`; property sections via `CnDetailCard` (title, text, decisionDate, outcome, legalBasis, isPublished, publishedAt); related Motion section (link to Motion detail); related ActionItems section (table with title, assignee, dueDate, taskStatus; row click → ActionItemDetail); "Publiceren" button shown only when `outcome: adopted` and `isPublished: false` — clicking sets `isPublished: true` and `publishedAt: <now>` via `ObjectService::saveObject()`; Edit and Delete header actions; `CnObjectSidebar`; all strings via `t()`

## 7. Frontend — Action Item Views

- [x] 7.1 Create `src/views/ActionItems.vue` — `CnIndexPage` with `useListView('action-item', { sidebarState, objectStore: actionItemStore })`; columns: title, assignee, dueDate, taskStatus (with `CnStatusBadge`); overdue items highlighted using `CnStatusBadge` warning variant (Nextcloud CSS variables only, no hardcoded colours); `CnFilterBar` with filters for `taskStatus` and `assignee`; row click → `ActionItemDetail`; all strings via `t()`
- [x] 7.2 Create `src/views/ActionItemDetail.vue` — `CnDetailPage` with `useDetailView`; property sections via `CnDetailCard` (title, description, assignee, dueDate, taskStatus, completedAt); related Decision section; related Meeting section; status update buttons: "In behandeling" (open → in-progress), "Afgerond" (in-progress → completed, sets completedAt to now); Edit and Delete header actions; `CnObjectSidebar`; all strings via `t()`
- [x] 7.3 Implement client-side overdue visual indicator: in ActionItems.vue, compute `isOverdue` as `dueDate < today && taskStatus !== 'completed'`; render overdue `CnStatusBadge` using `--color-error` Nextcloud CSS variable; this is a display-only enhancement alongside the background job

## 8. Frontend — Dashboard Extensions

- [x] 8.1 Add three new `CnStatsBlock` KPI cards to `src/views/Dashboard.vue`: "Notulen ter goedkeuring" (count of Minutes with `lifecycle: review`), "Gepubliceerde besluiten" (count of Decisions with `isPublished: true`), "Open actiepunten" (count of ActionItems with `taskStatus: open` or `in-progress`)
- [x] 8.2 Fetch the three new KPI counts in parallel alongside existing counts using `Promise.all` in the Dashboard `created()` hook
- [x] 8.3 All new KPI card labels use `t(appName, 'text')`

## 9. Tests

- [x] 9.1 Write `tests/Unit/Service/MinutesGenerationServiceTest.php` — PHPUnit tests covering: (a) happy path generates correct Dutch template with agenda items, motions, and decisions; (b) meeting with no agenda items returns minimal template; (c) missing linked meeting throws descriptive exception; minimum 3 test methods; add `@spec` tag
- [x] 9.2 Write `tests/Unit/BackgroundJob/OverdueActionItemsJobTest.php` — PHPUnit tests covering: (a) action items past dueDate are set to overdue; (b) completed action items are not modified; (c) action items with no dueDate are not modified; minimum 3 test methods; add `@spec` tag
- [x] 9.3 Write `tests/Unit/Controller/MinutesControllerTest.php` — PHPUnit tests covering: (a) generateDraft returns preview JSON; (b) generateDraft with invalid minutesId returns 404; (c) unauthenticated request returns 401; add `@spec` tag

## 10. Seed Data

- [x] 10.1 Add seed data objects for Minutes (4 objects), Decision (5 objects), and ActionItem (5 objects) to `lib/Settings/decidesk_register.json` under `x-openregister.seedData` using the `@self` envelope (`register`, `schema`, `slug`) with Dutch values from `design.md`
- [x] 10.2 Verify the repair step imports these seed data objects on a fresh install without errors (slug-based upsert)

## 11. Verification

- [x] 11.1 Verify Minutes CRUD: create, read, update, delete via UI; confirm `lifecycle: draft` on creation
- [x] 11.2 Verify Minutes lifecycle: transition draft → review → approved → signed → published; confirm `signedBy` is updated on approve and sign; confirm `approvedAt` is set on approve; confirm audit trail entries are created for each transition
- [x] 11.3 Verify "Concept genereren": click button, confirm preview modal appears, confirm `content` is populated after confirmation, confirm `lifecycle` stays `draft`
- [x] 11.4 Verify Decision CRUD: create, read, update, delete; confirm linked Motion appears in detail view; confirm ActionItems table in detail view
- [x] 11.5 Verify Decision publication: "Publiceren" button visible only for `outcome: adopted` and `isPublished: false`; clicking sets `isPublished: true` and `publishedAt`; button replaced by timestamp after publish
- [x] 11.6 Verify Decision search: search by text, filter by outcome, filter by isPublished — correct results returned
- [x] 11.7 Verify ActionItem CRUD: create with assignee and dueDate, update status, complete with completedAt set
- [x] 11.8 Verify overdue detection: create an ActionItem with `dueDate` in the past; trigger background job manually (or set dueDate < today and refresh list); confirm `taskStatus` is `overdue` and badge is displayed
- [x] 11.9 Verify Dashboard KPI cards show correct counts; confirm counts update when objects are created or status changes
- [x] 11.10 Verify MainMenu shows Notulen, Besluiten, and Actiepunten navigation entries; confirm routes are navigable
- [x] 11.11 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded strings
- [x] 11.12 Verify no hardcoded CSS colours — only Nextcloud CSS variables used for status badges and highlights
- [x] 11.13 Confirm all `@spec` PHPDoc tags are present on new PHP classes and public methods linking to `openspec/changes/p2-minutes-and-decisions/tasks.md`
