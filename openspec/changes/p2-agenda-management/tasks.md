## Deduplication Check (ADR-012)

- [x] 0.1 Confirm no custom CRUD, export, file, notification, or calendar code is needed: all use `ObjectService`, `ExportService`, `FileService`, `NotificationService`, `CalendarEventService` from OpenRegister platform
- [x] 0.2 Confirm `AgendaItem` entity is used as-is from ADR-000 — no schema properties added or renamed

## 1. Backend — AgendaService and AgendaController

- [x] 1.1 Create `lib/Service/AgendaService.php` — stateless service with the following public methods (each tagged `@spec openspec/changes/p2-agenda-management/tasks.md#task-1`):
  - `publishAgenda(string $meetingId): void` — validates at least one item exists, calls `NotificationService` for all active Participants, calls `CalendarEventService` to update meeting event
  - `advanceBobPhase(string $agendaItemId): void` — reads current `status`, maps next phase (beeldvorming → oordeelsvorming → besluitvorming → afgerond), saves via `ObjectService`
  - `processHamerstukken(string $meetingId): void` — fetches all AgendaItems tagged `hamerstuk` for the meeting, bulk-updates `status` to `afgerond` via `ObjectService`
  - `reorderItems(string $meetingId, array $orderedIds): void` — accepts ordered array of AgendaItem IDs, assigns `orderNumber` 1..n atomically
- [x] 1.2 Create `lib/Controller/AgendaController.php` — thin controller (< 10 lines/method) with `@spec` tags:
  - `POST /api/agendas/{meetingId}/publish` → `AgendaService::publishAgenda()`
  - `PUT /api/agenda-items/{id}/bob-phase` → `AgendaService::advanceBobPhase()`
  - `POST /api/agendas/{meetingId}/hamerstukken` → `AgendaService::processHamerstukken()`
  - `PUT /api/agendas/{meetingId}/reorder` → `AgendaService::reorderItems()` (body: `{ ids: [...] }`)
- [x] 1.3 Register routes in `appinfo/routes.php` — add the 4 routes above; ensure specific routes appear before any wildcard `{slug}` routes
- [x] 1.4 Register `AgendaService` and `AgendaController` in DI container (`lib/AppInfo/Application.php`)
- [x] 1.5 Write PHPUnit tests in `tests/Unit/Service/AgendaServiceTest.php` covering: `publishAgenda` sends notifications to active participants only; `advanceBobPhase` cycles through phases correctly; `processHamerstukken` updates all tagged items; `reorderItems` assigns sequential numbers

## 2. Frontend — Agenda Builder Component

- [x] 2.1 Create `src/components/AgendaBuilder.vue` — drag-and-drop agenda item list rendered inside `MeetingDetail.vue`; uses `vuedraggable` (or equivalent) for reordering; on drag-end calls `AgendaService::reorderItems()` via `PUT /api/agendas/{meetingId}/reorder`; displays `orderNumber`, `title`, `itemType` badge (`CnStatusBadge`), `estimatedDuration`, spokesperson name, attachment count, and COI badge
- [x] 2.2 Add total estimated duration calculation to `AgendaBuilder.vue` — sum all `estimatedDuration` values and display "Totale duur: X min" in the builder header; exclude items without a duration value
- [x] 2.3 Add "Terugkerende agendapunten toevoegen" button to `AgendaBuilder.vue` — queries AgendaItems with `isRecurring: true` and shows a list; on selection, creates new AgendaItem objects for the current meeting via `ObjectService.saveObject()`
- [x] 2.4 Add "Agendapunt voorstellen" action for Participants — opens `CnFormDialog` creating an AgendaItem with `status: "voorstel"`; visible to all Participants; visible only in meetings with lifecycle `scheduled` or `opened`
- [x] 2.5 Add chair proposal inbox panel to `AgendaBuilder.vue` — lists AgendaItems with `status: "voorstel"`; shows Approve ("Goedkeuren") and Reject ("Afwijzen") actions; approve clears `status` and assigns next `orderNumber`; reject sets `status: "afgewezen"` and sends notification via `NotificationService`
- [x] 2.6 Add spokesperson assignment control to each agenda item row — "Spreker toewijzen" opens a Participant selector; saves OpenRegister relation `spokesperson` from AgendaItem → Participant; displays chosen name inline; "Spreker verwijderen" removes the relation
- [x] 2.7 Ensure keyboard accessibility of drag-drop: up/down keyboard controls move an item one position and call `reorderItems()`; all interactive elements are reachable via Tab and have ARIA labels (ADR-010)

## 3. Frontend — Agenda Publication

- [x] 3.1 Add "Agenda publiceren" button to `MeetingDetail.vue` — visible to chair/secretary only; calls `POST /api/agendas/{meetingId}/publish`; shows validation error if no AgendaItems exist; on success updates Meeting status badge
- [x] 3.2 Add publication state guard — if agenda is already published, replace "Agenda publiceren" with "Agenda herzien" button; "Herzien" sets status back to draft (clears publication) and allows further editing
- [x] 3.3 Add "Exporteren" button to the agenda section of `MeetingDetail.vue` using `CnMassExportDialog` — columns: Nummer, Titel, Type, Duur (min), Spreker, Bijlagen; title column exported without `orderNumber` prefix (REQ-PUB-005)

## 4. Frontend — Live Meeting Agenda View

- [x] 4.1 Create `src/views/LiveMeeting.vue` — route `/meetings/:id/live`; shows the agenda builder in live-amendment mode with chair-only controls for add/remove/reorder; shows read-only agenda for non-chair roles; auto-refreshes every 30 seconds using `useListView` poll or manual `objectStore.fetchObjects()` call
- [x] 4.2 Add "Activeer agendapunt" action to live agenda item rows — chair can activate one item at a time; active item is highlighted; activation stores active item ID in component state (not persisted)
- [x] 4.3 Add BOB phase panel to each `discussion` and `decision` item in the live view — `CnTimelineStages` with three stages (Beeldvorming, Oordeelsvorming, Besluitvorming); "Volgende fase" button calls `PUT /api/agenda-items/{id}/bob-phase`; informational items show no BOB panel
- [x] 4.4 Add "Hamerstukken" section at the top of the live agenda — lists AgendaItems with `tags` containing `hamerstuk`; shows "Hamerstukken vaststellen" button calling `POST /api/agendas/{meetingId}/hamerstukken` with confirmation dialog; "Uit hamerstukken halen" removes the tag via `ObjectService.saveObject()`
- [x] 4.5 Add live meeting route to router: `{ name: "LiveMeeting", path: "/meetings/:id/live", component: LiveMeeting }`; add "Live vergadering" link button on `MeetingDetail.vue` visible when lifecycle is `opened`

## 5. Frontend — Conflict of Interest

- [x] 5.1 Add "Belangenverstrengeling melden" button to `AgendaItemDetail.vue` — opens a dialog with a required "Reden voor ontheffing" text area; on submit creates a note on the AgendaItem via OpenRegister built-in notes API with title `COI: [displayName]`
- [x] 5.2 Add COI badge to agenda item rows in `AgendaBuilder.vue` — counts notes with title prefix `COI:` and shows "COI (N)" badge if N > 0
- [x] 5.3 Add "Verklaringen belangenverstrengeling" section to `MeetingDetail.vue` for chair/secretary — queries all AgendaItems for the meeting and lists those with COI notes, grouped by item, showing declarant names

## 6. Frontend — Motion Linking

- [x] 6.1 Add "Motie koppelen" action to `AgendaItemDetail.vue` for `decision`-type items — opens a search dialog listing Motions in the same Meeting; on selection creates OpenRegister relation AgendaItem → Motion
- [x] 6.2 Add "Gekoppelde moties" section to `AgendaItemDetail.vue` — shows linked Motion titles with links to Motion detail; hidden for non-decision items

## 7. Frontend — AgendaItem Detail Extensions (extends p1-crud-operations)

- [x] 7.1 Extend `src/views/AgendaItemDetail.vue` to show: BOB phase `CnTimelineStages` (for discussion/decision items), COI note count, spokesperson name from relation, linked Motions list
- [x] 7.2 Add `CnStatusBadge` for `itemType` to `AgendaItemDetail.vue` header: "Informatief" (neutral), "Discussie" (info), "Besluit" (warning)
- [x] 7.3 Ensure `CnObjectSidebar` on `AgendaItemDetail.vue` shows Files tab (attachments), Notes tab (COI declarations visible), Audit Trail tab — all provided by platform; no custom implementation

## 8. Translations (ADR-007)

- [x] 8.1 Add Dutch (nl) translation keys for all new user-visible strings in `l10n/nl.js` and `l10n/nl.json`: agenda builder labels, BOB phase names (Beeldvorming, Oordeelsvorming, Besluitvorming), hamerstuk labels, COI dialog copy, publish/revise button labels, notification messages
- [x] 8.2 Add English (en) translation keys matching all Dutch keys

## 9. Testing (ADR-008)

- [x] 9.1 Write PHPUnit tests for `AgendaServiceTest`: `publishAgenda` — missing items validation; notification dispatch; `advanceBobPhase` — phase transition sequence; informational item guard; `processHamerstukken` — batch update; `reorderItems` — sequential numbering
- [x] 9.2 Write Newman/Postman integration tests in `tests/integration/agenda.json` for all 4 new API endpoints (publish, bob-phase, hamerstukken, reorder)
- [ ] 9.3 Write Playwright browser tests for REQ-BLD-002 (drag-drop reorder persisted), REQ-PUB-001 (publish triggers notification), REQ-LIV-002 (BOB phase advances), REQ-LIV-003 (hamerstukken batch adopt), REQ-COI-001 (COI declaration saved as note), REQ-COI-003 (motion linked to item) — tracked in https://github.com/ConductionNL/decidesk/issues/27 (blocks next PR)

## 10. Verification

- [x] 10.1 Verify all new PHP classes and public methods have `@spec openspec/changes/p2-agenda-management/tasks.md#task-N` PHPDoc tags
- [x] 10.2 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded Dutch or English strings in templates or JS
- [x] 10.3 Verify no hardcoded CSS colors — only Nextcloud CSS variables (ADR-010)
- [x] 10.4 Verify WCAG 2.1 AA: keyboard navigation in drag-drop builder, ARIA labels on all interactive controls, color not the sole indicator of BOB phase status
- [x] 10.5 Verify `AgendaItem` schema in OpenRegister still matches ADR-000 exactly after implementation — no extra properties added
- [x] 10.6 Verify seed data (5 AgendaItem objects) is present after fresh install
