## Context

Decidesk is a thin-client Nextcloud app. It owns no database tables; all domain data lives in OpenRegister as JSON objects. The frontend (Vue 2.7 + Pinia) communicates directly with the OpenRegister REST API. The backend is minimal: `SettingsController` + `SettingsService` handle register setup and a repair step imports the register template.

This change establishes the app scaffolding: routing, store initialisation, the main navigation menu, the dashboard page, global search, and accessibility/theming foundations. All subsequent p1 specs (meetings, motions, decisions, governance bodies) depend on the router and stores introduced here.

**Current state:** The app directory exists but has no working Vue scaffolding — no `App.vue`, no router, no Pinia stores, no navigation.

**Constraints:**
- Vue 2.7 Options API only (no Composition API, no Vue 3)
- Pinia for state, NOT Vuex
- All UI via `@nextcloud/vue` + `@conduction/nextcloud-vue` — do not build custom UI primitives
- CSS: Nextcloud CSS variables only — no hardcoded colours, no `--nldesign-*` references
- Translations: every user-visible string via `t(appName, '...')` — no hardcoded strings
- `fetch()` for HTTP — not axios

**Stakeholders needing this:** Board Secretary, Council Clerk, Supervisory Board Chair, CFO (dashboard KPIs), Province IT Security Officer (single-point entry).

## Goals / Non-Goals

**Goals:**
- Working app scaffold: App.vue, router, store init, MainMenu
- Dashboard page showing 4 KPI cards + status distribution chart + recent-items list
- Global search across core governance entities
- NL Design System CSS token wiring
- WCAG 2.1 AA baseline on all new components

**Non-Goals:**
- Entity-specific list/detail pages (those belong to later p1 specs)
- Voting, minutes, or document workflows
- ORI API publication
- Mobile/offline PWA capability
- Custom authentication — use Nextcloud session

## Decisions

### D1 — Use `CnDashboardPage` for the dashboard layout
`@conduction/nextcloud-vue` provides `CnDashboardPage` (GridStack drag-drop), `CnStatsBlock` (KPI cards), `CnChartWidget` (ApexCharts), and `CnTileWidget`. Building a custom layout would duplicate this.
**Rationale:** Consistency with other Conduction apps; free drag-drop and responsive layout; no custom CSS grid required.
**Alternative considered:** Plain `NcAppContent` with CSS grid — rejected because it loses drag-drop and widget persistence.

### D2 — Single flat router (no nested routes)
All routes are top-level named routes: `/` (Dashboard), `/meetings` (list), `/meetings/:id` (detail), `/motions`, `/motions/:id`, etc. A catch-all `*` redirects to `/`.
**Rationale:** ADR-004 (frontend) mandates flat routes. Nested routes add complexity without benefit given Nextcloud's own app frame handles the outer layout.

### D3 — Store initialisation in `store/store.js` via `initializeStores()`
`App.vue`'s `created()` hook calls `initializeStores()` which: (1) fetches settings, (2) calls `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each of the 17 entities.
**Rationale:** Central registration avoids per-component store setup and makes it easy to add entities later without touching App.vue.

### D4 — Global search via OpenRegister `IndexService` (no custom endpoint)
The search bar in the navigation header calls the OpenRegister full-text search API with a `_search` query parameter across the relevant schemas. Results are displayed in a floating dropdown.
**Rationale:** ADR-001 prohibits custom search endpoints. IndexService already indexes all OpenRegister objects.

### D5 — NL Design System theming via CSS custom property overrides
A `src/assets/nl-design.css` file maps `--color-primary-*`, `--color-main-background`, etc. to the Nextcloud CSS variable equivalents. No NLDES tokens are referenced directly in components.
**Rationale:** ADR-004 prohibits `--nldesign-*` in components. A single mapping file keeps theming centralised and auditable.

### D6 — Seed data included in register template
`lib/Settings/decidesk_register.json` will include 3–5 seed objects per primary entity (Meeting, GovernanceBody, Participant, Motion, Decision) using Dutch values. Seed objects use the `@self` envelope with `register`, `schema`, and `slug` fields.

**Seed objects:**

```json
[
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "gemeenteraad-amsterdam" },
    "name": "Gemeenteraad Amsterdam",
    "bodyType": "legislative",
    "domain": "municipal",
    "quorumRule": "majority",
    "votingDefault": "for-against-abstain",
    "termStart": "2022-03-30T00:00:00Z",
    "termEnd": "2026-03-29T23:59:59Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "raad-van-commissarissen-nvb" },
    "name": "Raad van Commissarissen NVB BV",
    "bodyType": "corporate-board",
    "domain": "corporate-governance",
    "quorumRule": "two-thirds",
    "votingDefault": "for-against-abstain"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "raadsvergadering-2025-09-10" },
    "title": "Raadsvergadering 10 september 2025",
    "meetingType": "regular",
    "scheduledDate": "2025-09-10T19:30:00Z",
    "endDate": "2025-09-10T23:00:00Z",
    "location": "Stadhuis Amsterdam, Raadzaal",
    "meetingMode": "in-person",
    "lifecycle": "scheduled",
    "quorumRequired": 23
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "commissie-ruimte-2025-09-03" },
    "title": "Commissievergadering Ruimte & Wonen 3 september 2025",
    "meetingType": "committee",
    "scheduledDate": "2025-09-03T19:00:00Z",
    "location": "Stadhuis Amsterdam, Commissiezaal 1",
    "meetingMode": "hybrid",
    "lifecycle": "draft"
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "j-de-vries-voorzitter" },
    "displayName": "J. de Vries",
    "role": "chair",
    "party": "GroenLinks",
    "email": "j.devries@amsterdam.nl",
    "joinedAt": "2022-03-30T00:00:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "a-bakker-secretaris" },
    "displayName": "A. Bakker",
    "role": "secretary",
    "email": "a.bakker@amsterdam.nl",
    "joinedAt": "2022-03-30T00:00:00Z",
    "votingWeight": 0
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-klimaatplan-2025" },
    "title": "Motie: Versneld klimaatplan 2025–2030",
    "text": "De raad verzoekt het college een versneld klimaatplan op te stellen voor de periode 2025–2030.",
    "motionType": "motion",
    "proposer": "Fractie GroenLinks",
    "lifecycle": "submitted",
    "submittedAt": "2025-09-05T10:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-begroting-2026" },
    "title": "Vaststelling Begroting 2026",
    "text": "De gemeenteraad stelt de programmabegroting 2026 vast zoals voorgelegd.",
    "decisionDate": "2025-09-10T21:45:00Z",
    "outcome": "adopted",
    "isPublished": false,
    "legalBasis": "Gemeentewet art. 191"
  }
]
```

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| `CnDashboardPage` GridStack not available in installed `@conduction/nextcloud-vue` version | Pin to minimum required version; fall back to `CnIndexPage` layout if component missing |
| OpenRegister not installed — dashboard has no data | App.vue guard: show `NcEmptyContent` with install instructions when `openRegisters` flag is false |
| 17 entity stores initialised on every load increases startup latency | Use `Promise.all` for parallel registration; stores are lazy-loaded, so only active routes hydrate data |
| CSS custom property mapping may drift if Nextcloud changes variable names | Keep `nl-design.css` minimal and covered by a visual regression test |
| WCAG 2.1 AA compliance on drag-drop widgets (GridStack) may be limited | Dashboard is editor-mode keyboard-navigable; view mode is fully keyboard accessible; document known limitations |

## Migration Plan

1. Run repair step — `ConfigurationService::importFromApp()` imports `decidesk_register.json` and creates 17 schemas + seed objects in OpenRegister.
2. Deploy frontend assets — `npm run build` outputs to `js/` and `css/`.
3. Activate app in Nextcloud app store.
4. No data migration required (app is new; no existing objects).
5. Rollback: disable app in Nextcloud; no database changes to reverse.

## Open Questions

- Should the dashboard search bar also search `DigitalDocument` attachments (full-text via TextExtractionService)? Deferred to p2-council-document-publication.
- NL Design System token set version to pin — depends on which municipality is first to deploy.
