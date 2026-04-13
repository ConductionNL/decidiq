## Why

Decidesk has no working frontend or backend scaffold yet. Before any governance feature can be built, the app needs its register configuration, store initialization, routing, and CRUD views for the four foundational entities: GovernanceBody, Meeting, Participant, and AgendaItem. These entities underpin every subsequent spec (motions, voting, minutes, decisions) and must exist as navigable, editable objects before any other work can proceed.

## What Changes

- **New**: OpenRegister register definition (`lib/Settings/decidesk_register.json`) with schemas for all 17 Decidesk entities
- **New**: Repair step (`IRepairStep`) that imports the register on first install and on upgrades
- **New**: `SettingsController` + `SettingsService` for register configuration and admin settings page
- **New**: Vue 2 app scaffold — `App.vue`, `MainMenu`, Pinia store initialization, Vue Router with history mode
- **New**: Dashboard page with KPI stats blocks (meetings, governance bodies, participants) and a status distribution chart
- **New**: Index + Detail pages for GovernanceBody, Meeting, Participant, and AgendaItem using `CnIndexPage` / `CnDetailPage`
- **New**: Pinia object stores created via `createObjectStore` with `files`, `auditTrails`, and `relations` plugins
- **New**: Admin settings page with `CnVersionInfoCard` and `CnRegisterMapping`
- **New**: Seed data (3–5 Dutch example objects per entity) loaded on install

## Capabilities

### New Capabilities

- `app-foundation`: Register import, store initialization, Vue Router setup, App.vue scaffold, MainMenu, admin settings page, and Dashboard with KPI widgets
- `meeting-crud`: Full CRUD for the Meeting entity — list, create, edit, delete, detail view with related AgendaItems
- `governance-body-crud`: Full CRUD for the GovernanceBody entity — list, create, edit, delete, detail view with related Meetings and Participants
- `participant-crud`: Full CRUD for the Participant entity — list, create, edit, delete, detail view
- `agenda-item-crud`: Full CRUD for the AgendaItem entity — list, create, edit, delete, detail view with ordering support

### Modified Capabilities

<!-- No existing specs to modify — this is the first change -->

## Impact

- Creates `lib/Settings/decidesk_register.json` (new file, no migration needed — first install)
- Creates `lib/Migration/` repair step for register import
- Creates `src/` frontend tree: `App.vue`, `main.js`, `router/index.js`, `store/store.js`, `store/modules/`, `views/`
- No breaking changes — greenfield implementation
- Downstream changes (p2+) can extend the register JSON with additional schemas and add new pages to the router
