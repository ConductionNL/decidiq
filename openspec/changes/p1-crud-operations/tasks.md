## 1. Register Configuration

- [ ] 1.1 Create `lib/Settings/decidesk_register.json` in OpenAPI 3.0.0 format with `x-openregister` extensions, defining schemas for all 17 Decidesk entities (GovernanceBody, Meeting, Participant, AgendaItem, AgendaItem, Motion, Amendment, VotingRound, Vote, Decision, ActionItem, Minutes, DigitalDocument, MonetaryAmount, Offer, Order, Product, Report)
- [ ] 1.2 Add seed data objects to the register JSON under `x-openregister.seedData` for GovernanceBody (5 Dutch examples), Meeting (4), Participant (5), and AgendaItem (5) using the `@self` envelope with deterministic slugs
- [ ] 1.3 Verify all schema properties match ADR-000 exactly (types, required fields, no redefinition of OpenRegister built-ins)

## 2. Backend — Repair Step and Settings

- [ ] 2.1 Create `lib/Migration/DecideskRepairStep.php` implementing `IRepairStep`; call `ConfigurationService::importFromApp('decidesk')` in `run()` to import the register JSON
- [ ] 2.2 Register `DecideskRepairStep` in `appinfo/info.xml` under `<repair-steps><install>` and `<repair-steps><update>`
- [ ] 2.3 Create `lib/Service/SettingsService.php` — stateless service with `getSettings(): array` (returns `openRegisters` bool + `isAdmin` bool + register slugs) and `loadRegister(): void` (re-imports from JSON)
- [ ] 2.4 Create `lib/Controller/SettingsController.php` — thin controller with `index()` (GET, returns settings JSON) and `load()` (POST, calls `SettingsService::loadRegister()`); annotate with `@spec` tags linking to this tasks.md
- [ ] 2.5 Register routes in `appinfo/routes.php`: `GET /api/settings` → `SettingsController::index`, `POST /api/settings/load` → `SettingsController::load`
- [ ] 2.6 Register `SettingsService` and `SettingsController` in DI container (`lib/AppInfo/Application.php`)

## 3. Frontend — Scaffold and Store Initialization

- [ ] 3.1 Create `src/main.js` — bootstraps Vue 2 app, registers Pinia, mounts to `#content`
- [ ] 3.2 Create `src/store/store.js` — defines `initializeStores()` which fetches `GET /api/settings`, then calls `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for GovernanceBody, Meeting, Participant, and AgendaItem
- [ ] 3.3 Create Pinia object stores in `src/store/modules/` for GovernanceBody, Meeting, Participant, and AgendaItem using `createObjectStore(name)` with `files`, `auditTrails`, and `relations` plugins
- [ ] 3.4 Create `src/router/index.js` — Vue Router in history mode, base `/index.php/apps/decidesk/`; define named routes: `Dashboard` (`/`), `GovernanceBodies` (`/governance-bodies`), `GovernanceBodyDetail` (`/governance-bodies/:id`), `Meetings` (`/meetings`), `MeetingDetail` (`/meetings/:id`), `Participants` (`/participants`), `ParticipantDetail` (`/participants/:id`), `AgendaItems` (`/agenda-items`), `AgendaItemDetail` (`/agenda-items/:id`), `Settings` (`/settings`); catch-all `*` → redirect to `/`
- [ ] 3.5 Create `src/App.vue` — `NcContent` root with 3 states: loading (`NcLoadingIcon`), OpenRegister missing (`NcEmptyContent` with install message), ready (`MainMenu` + `NcAppContent` + `router-view`); `created()` calls `initializeStores()`

## 4. Frontend — Navigation

- [ ] 4.1 Create `src/components/MainMenu.vue` — `NcAppNavigation` with `NcAppNavigationItem` entries for Dashboard, Governance Bodies, Meetings, Participants, Agenda Items; `NcAppNavigationSettings` footer link to Settings route

## 5. Frontend — Dashboard

- [ ] 5.1 Create `src/views/Dashboard.vue` — `CnDashboardPage` with 4 `CnStatsBlock` KPI cards: total GovernanceBody count, total Meeting count, total Participant count, count of meetings with lifecycle `scheduled`
- [ ] 5.2 Add a `CnChartWidget` (donut) to Dashboard showing count of meetings per lifecycle state (draft, scheduled, opened, paused, adjourned, closed)
- [ ] 5.3 Ensure all entity count requests are issued in parallel using `Promise.all` in the Dashboard `created()` hook

## 6. Frontend — Governance Body Views

- [ ] 6.1 Create `src/views/GovernanceBodies.vue` — `CnIndexPage` with `useListView('governance-body', { sidebarState, objectStore: governanceBodyStore })`; columns: name, bodyType, domain, termEnd; row click → `GovernanceBodyDetail`
- [ ] 6.2 Create `src/views/GovernanceBodyDetail.vue` — `CnDetailPage` with `useDetailView`; property sections via `CnDetailCard`; related Meetings section; related Participants section; `CnObjectSidebar`; Edit and Delete header actions using `CnFormDialog` and `CnDeleteDialog`

## 7. Frontend — Meeting Views

- [ ] 7.1 Create `src/views/Meetings.vue` — `CnIndexPage` with `useListView('meeting', { sidebarState, objectStore: meetingStore })`; columns: title, meetingType, scheduledDate, meetingMode, lifecycle; row click → `MeetingDetail`
- [ ] 7.2 Create `src/views/MeetingDetail.vue` — `CnDetailPage` with `useDetailView`; property sections via `CnDetailCard`; related AgendaItems section ordered by `orderNumber`; `CnObjectSidebar`; Edit and Delete header actions

## 8. Frontend — Participant Views

- [ ] 8.1 Create `src/views/Participants.vue` — `CnIndexPage` with `useListView('participant', { sidebarState, objectStore: participantStore })`; columns: displayName, role, party, email; row click → `ParticipantDetail`
- [ ] 8.2 Create `src/views/ParticipantDetail.vue` — `CnDetailPage` with `useDetailView`; property sections via `CnDetailCard`; related GovernanceBody section; `CnObjectSidebar`; Edit and Delete header actions

## 9. Frontend — Agenda Item Views

- [ ] 9.1 Create `src/views/AgendaItems.vue` — `CnIndexPage` with `useListView('agenda-item', { sidebarState, objectStore: agendaItemStore })`; columns: orderNumber, title, itemType, estimatedDuration, isRecurring; default sort by `orderNumber` ascending; row click → `AgendaItemDetail`
- [ ] 9.2 Create `src/views/AgendaItemDetail.vue` — `CnDetailPage` with `useDetailView`; property sections via `CnDetailCard`; linked Meeting section; `CnObjectSidebar`; Edit and Delete header actions

## 10. Frontend — Settings Page

- [ ] 10.1 Create `src/views/Settings.vue` — `CnVersionInfoCard` (first), then `CnRegisterMapping`, then a re-import button calling `POST /api/settings/load`; shown only when `isAdmin` is true (from settings response)

## 11. Verification

- [ ] 11.1 Verify register import by installing the app in a test environment and confirming all 17 schemas exist in OpenRegister
- [ ] 11.2 Verify seed data by checking that GovernanceBody, Meeting, Participant, and AgendaItem objects are present after install
- [ ] 11.3 Verify CRUD flows for all 4 entities: create, read, update, delete via UI
- [ ] 11.4 Verify Dashboard KPI cards show correct counts for each entity
- [ ] 11.5 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded strings
- [ ] 11.6 Verify no hardcoded CSS colors — only Nextcloud CSS variables used
- [ ] 11.7 Confirm all `@spec` PHPDoc tags are present on controllers and services linking to `openspec/changes/p1-crud-operations/tasks.md`
