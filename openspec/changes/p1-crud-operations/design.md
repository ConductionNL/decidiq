## Context

Decidesk is a Nextcloud app using the **thin-client** pattern: the app owns no database tables. All domain data is stored as JSON objects in OpenRegister. The frontend talks to the OpenRegister API directly via Pinia object stores. The backend is minimal — only a `SettingsController` for configuration and a repair step that imports the register schema.

The OpenRegister platform provides CRUD, pagination, filtering, file attachments, audit trails, and relation management for free. Decidesk must not rebuild any of these.

This change is the greenfield foundation. There are no prior Decidesk migrations to preserve.

## Goals / Non-Goals

**Goals:**
- Initialize the OpenRegister register with all 17 Decidesk schemas on first install
- Provide navigable index and detail views for the 4 foundational entities: GovernanceBody, Meeting, Participant, AgendaItem
- Set up the Vue 2 app scaffold (routing, stores, App.vue, MainMenu)
- Deliver a dashboard with KPI stats for key entities
- Load seed data (Dutch-language examples) so the app is usable immediately after install

**Non-Goals:**
- Workflow/lifecycle transitions (p2)
- Motion, voting, minutes, decision management (p2)
- ORI API publication (p3)
- Governance body domain configuration or committee structure (p3)

## Decisions

### 1. Register config in `lib/Settings/decidesk_register.json`
**Decision**: Use a single JSON file in OpenAPI 3.0.0 format with `x-openregister` extensions.
**Rationale**: Matches the company ADR-001 pattern used by all Conduction apps. Imported via `ConfigurationService::importFromApp()` in a repair step.
**Alternative considered**: Separate JSON per schema — rejected because a single file is easier to version and avoids import order issues.

### 2. Frontend uses `createObjectStore` with plugins
**Decision**: One Pinia store per entity, created with `createObjectStore(entityName)` and `files`, `auditTrails`, and `relations` plugins.
**Rationale**: ADR-004 and ADR-001 mandate this pattern. No custom CRUD stores.
**Alternative considered**: Custom fetch-based stores — rejected because the platform provides them for free and custom stores duplicate platform capabilities.

### 3. `CnIndexPage` + `CnDetailPage` for all entity views
**Decision**: Use `CnIndexPage` with `useListView` for list views and `CnDetailPage` with `CnObjectSidebar` for detail views.
**Rationale**: ADR-001 prohibits rebuilding what the platform provides. `CnIndexPage` gives search, filtering, pagination, and CRUD dialogs without custom code.
**Alternative considered**: Custom list components — rejected.

### 4. Dashboard with `CnDashboardPage` + `CnStatsBlock`
**Decision**: Dashboard shows 4 KPI cards (total meetings, governance bodies, participants, upcoming meetings) plus a meeting lifecycle distribution chart.
**Rationale**: Matches ADR-004 dashboard pattern. Data fetched in parallel via `Promise.all`.

### 5. Seed data included in register JSON
**Decision**: Seed data is embedded in the register JSON under `x-openregister.seedData` using the `@self` envelope.
**Rationale**: ADR-001 requires 3-5 realistic Dutch objects per schema in `design.md`; these are also loaded by the repair step.

## Seed Data (Dutch examples)

### GovernanceBody

```json
[
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "gemeenteraad-amsterdam" },
    "name": "Gemeenteraad Amsterdam",
    "bodyType": "legislative",
    "domain": "municipality",
    "votingDefault": "for-against-abstain",
    "termStart": "2022-03-16T00:00:00Z",
    "termEnd": "2026-03-15T00:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "rvc-waterschap-amstel" },
    "name": "Raad van Commissarissen Waterschap Amstel, Gooi en Vecht",
    "bodyType": "corporate-board",
    "domain": "water-authority",
    "votingDefault": "for-against-abstain",
    "termStart": "2023-01-01T00:00:00Z",
    "termEnd": "2026-12-31T00:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "directieteam-gemeente-utrecht" },
    "name": "Directieteam Gemeente Utrecht",
    "bodyType": "operational",
    "domain": "municipality",
    "votingDefault": "show-of-hands"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "ledenraad-vng" },
    "name": "Ledenraad VNG",
    "bodyType": "association",
    "domain": "association",
    "votingDefault": "for-against-abstain",
    "termStart": "2024-01-01T00:00:00Z",
    "termEnd": "2025-12-31T00:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "auditcommissie-provincie-nh" },
    "name": "Auditcommissie Provincie Noord-Holland",
    "bodyType": "legislative",
    "domain": "province",
    "votingDefault": "for-against-abstain"
  }
]
```

### Meeting

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "raadsvergadering-2025-01-15" },
    "title": "Raadsvergadering 15 januari 2025",
    "meetingType": "regular",
    "scheduledDate": "2025-01-15T19:30:00Z",
    "endDate": "2025-01-15T23:00:00Z",
    "location": "Stadhuis Amsterdam, Raadzaal",
    "meetingMode": "hybrid",
    "lifecycle": "closed"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "rvc-vergadering-q1-2025" },
    "title": "RvC Vergadering Q1 2025 — Waterschap",
    "meetingType": "regular",
    "scheduledDate": "2025-02-05T10:00:00Z",
    "endDate": "2025-02-05T13:00:00Z",
    "location": "Waternet Kantoor, Amstel 1",
    "meetingMode": "in-person",
    "lifecycle": "closed"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "directieoverleg-2025-04-14" },
    "title": "Directieoverleg week 16 — Gemeente Utrecht",
    "meetingType": "regular",
    "scheduledDate": "2025-04-14T09:00:00Z",
    "endDate": "2025-04-14T11:00:00Z",
    "location": "Stadskantoor Utrecht, Kamer 3.12",
    "meetingMode": "hybrid",
    "lifecycle": "draft"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "ledenvergadering-vng-voorjaar-2025" },
    "title": "Algemene Ledenvergadering VNG — Voorjaar 2025",
    "meetingType": "public hearing",
    "scheduledDate": "2025-06-18T13:00:00Z",
    "endDate": "2025-06-18T17:00:00Z",
    "location": "World Forum Den Haag",
    "meetingMode": "in-person",
    "lifecycle": "scheduled",
    "quorumRequired": 50
  }
]
```

### Participant

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "femke-halsema" },
    "displayName": "Femke Halsema",
    "role": "chair",
    "email": "f.halsema@amsterdam.nl",
    "joinedAt": "2018-05-30T00:00:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "jan-de-vries" },
    "displayName": "Jan de Vries",
    "role": "secretary",
    "email": "j.devries@waterschap.nl",
    "joinedAt": "2021-01-15T00:00:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "marie-janssen" },
    "displayName": "Marie Janssen",
    "role": "member",
    "party": "D66",
    "email": "m.janssen@amsterdam.nl",
    "joinedAt": "2022-03-16T00:00:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "pieter-bakker" },
    "displayName": "Pieter Bakker",
    "role": "chair",
    "email": "p.bakker@utrecht.nl",
    "joinedAt": "2020-06-01T00:00:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "ans-rutten" },
    "displayName": "Ans Rutten",
    "role": "observer",
    "email": "a.rutten@vng.nl",
    "joinedAt": "2024-01-10T00:00:00Z",
    "votingWeight": 0
  }
]
```

### AgendaItem

```json
[
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "opening-raad-2025-01-15" },
    "title": "Opening vergadering",
    "itemType": "informational",
    "orderNumber": 1,
    "estimatedDuration": 5
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "notulen-2024-12-18" },
    "title": "Vaststellen notulen vergadering 18 december 2024",
    "itemType": "decision",
    "orderNumber": 2,
    "estimatedDuration": 10,
    "isRecurring": true
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "jaarrekening-2024" },
    "title": "Jaarrekening 2024 — ter goedkeuring",
    "itemType": "decision",
    "orderNumber": 3,
    "estimatedDuration": 45,
    "description": "Bespreking en vaststelling van de jaarrekening over het boekjaar 2024"
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "begroting-2026-bespreking" },
    "title": "Kadernota begroting 2026",
    "itemType": "discussion",
    "orderNumber": 4,
    "estimatedDuration": 60,
    "description": "Eerste bespreking van de financiële kaders voor begrotingsjaar 2026"
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "rondvraag" },
    "title": "Rondvraag",
    "itemType": "informational",
    "orderNumber": 99,
    "estimatedDuration": 10,
    "isRecurring": true
  }
]
```

## Risks / Trade-offs

- **[Risk] Register import fails on upgrade if schema changes** → Mitigation: the repair step checks for schema existence before creating; destructive changes require a new named migration.
- **[Risk] `createObjectStore` plugin composition may conflict on simultaneous plugin use** → Mitigation: use platform-recommended plugin list (files, auditTrails, relations); no custom plugins in p1.
- **[Risk] Seed data clashes with existing objects on reinstall** → Mitigation: use deterministic `slug` values; OpenRegister upserts on slug conflict.
- **[Trade-off] No optimistic UI updates** — `useListView` and `useDetailView` refetch after each save. Acceptable for governance workflows where correctness > speed.

## Migration Plan

1. Ship `lib/Settings/decidesk_register.json` with all schemas
2. On install/upgrade, `DecideskRepairStep::run()` calls `ConfigurationService::importFromApp('decidesk')`
3. Seed data objects are created via `ObjectService::saveObjects()` if they do not exist (slug-based check)
4. No rollback needed — objects can be deleted manually; schemas are non-destructive

## Open Questions

- Should the Dashboard fetch counts from all 17 entities or only the 4 p1 entities? (Recommendation: only p1 entities for now; extend in later phases)
- Should AgendaItem list be a standalone page or only accessible from the Meeting detail view? (Recommendation: both — standalone for admin use, embedded in Meeting detail for day-to-day use)

## Status

status: pr-created
