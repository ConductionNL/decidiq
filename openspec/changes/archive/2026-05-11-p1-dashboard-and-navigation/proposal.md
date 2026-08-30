## Why

Decidesk requires a functional entry point that orients users across all governance domains — municipalities, water boards, associations, and corporate boards alike. Without a dashboard and consistent navigation, users cannot discover upcoming meetings, track pending motions, or locate recent decisions quickly. This is the highest-demand capability cluster (814 demand score for search alone) and is the prerequisite for all other p1 specs.

## What Changes

- Add a **Dashboard page** (`/`) with KPI cards (upcoming meetings, pending motions, open action items, recent decisions) and quick-access navigation tiles
- Add **MainMenu navigation** with routes to all primary entities (Dashboard, Meetings, Motions, Decisions, Participants, Governance Bodies)
- Add **global full-text search** across Meetings, Motions, Decisions, AgendaItems, and Participants via OpenRegister IndexService
- Add **NL Design System theming** support via Nextcloud CSS custom properties (no hardcoded colours)
- Add **accessibility baseline**: H1 heading structure, skip-navigation link, keyboard navigation for all interactive elements (WCAG 2.1 AA)
- Add **App.vue** with router, store initialisation, and OpenRegister availability guard
- Add **Router** with named flat routes for all entity list and detail views, history mode
- Add **store initialisation** (`store/store.js`) registering all 17 entity object stores via `createObjectStore`
- Add **Settings page** with `CnVersionInfoCard`, `CnRegisterMapping`, and register import button

## Capabilities

### New Capabilities

- `app-dashboard`: Overview page with KPI cards, meeting status chart, and quick-access tiles for all governance domains
- `app-navigation`: MainMenu (NcAppNavigation), router (history mode), App.vue lifecycle, store initialisation
- `global-search`: Full-text search bar in navigation header across Meetings, Motions, Decisions, AgendaItems, Participants
- `nl-design-theming`: CSS custom property token mapping to Nextcloud variables; no hardcoded colours
- `accessibility-baseline`: H1 structure, skip-nav, ARIA landmarks, keyboard focus management across all pages

### Modified Capabilities

*(none — all capabilities are new)*

## Impact

- **New files**: `src/App.vue`, `src/router/index.js`, `src/store/store.js`, `src/views/DashboardView.vue`, `src/views/SettingsView.vue`, `src/components/MainMenu.vue`
- **Register config**: `lib/Settings/decidesk_register.json` updated to expose all 17 schemas
- **No backend CRUD controllers** — all data via OpenRegister ObjectService
- **Dependencies**: `@conduction/nextcloud-vue` (CnDashboardPage, CnStatsBlock, CnTileWidget, CnChartWidget), `@nextcloud/vue` (NcAppNavigation, NcContent, NcAppContent)
- **WCAG 2.1 AA** compliance required on all new pages/components
