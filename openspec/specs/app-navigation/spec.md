# app-navigation Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- ia-six-item-nav (active) — restructures the menu to ADR-004's six-item, mode-aware IA

## Purpose
TBD - created by archiving change 2026-05-11-p1-dashboard-and-navigation. Update Purpose after archive.

## Requirements

### Requirement: REQ-NAV-001 App.vue provides root layout with three states
`App.vue` SHALL use `NcContent` as the root layout element and SHALL render one of three states: (1) loading — `NcLoadingIcon` while settings are fetched; (2) no-OpenRegister — `NcEmptyContent` if `openRegisters` is false; (3) ready — `MainMenu` + `NcAppContent` + `router-view` with optional `CnIndexSidebar`.

#### Scenario: Settings load successfully with OpenRegister
- **WHEN** `initializeStores()` completes and `openRegisters` is `true`
- **THEN** the full app layout with MainMenu and router-view is rendered

#### Scenario: Settings load without OpenRegister
- **WHEN** `initializeStores()` completes and `openRegisters` is `false`
- **THEN** `NcEmptyContent` is shown with a message to install OpenRegister

#### Scenario: Settings are loading
- **WHEN** the app is mounted and the settings fetch has not completed
- **THEN** only `NcLoadingIcon` is displayed

---

### Requirement: REQ-NAV-002 MainMenu lists primary entity routes
`MainMenu` SHALL use `NcAppNavigation` with one `NcAppNavigationItem` per primary route: Dashboard, Meetings (Vergaderingen), Motions (Moties), Decisions (Besluiten), Participants (Deelnemers), Governance Bodies (Bestuursorganen). A settings link SHALL appear in the navigation footer via `NcAppNavigationSettings`.

#### Scenario: Navigation items are rendered
- **WHEN** the app is in the ready state
- **THEN** all six navigation items are visible in the sidebar

#### Scenario: Active route is highlighted
- **WHEN** the user is on the `/meetings` route
- **THEN** the "Vergaderingen" navigation item is marked as active

#### Scenario: Settings link navigates to settings
- **WHEN** the user clicks the settings link in the navigation footer
- **THEN** the router navigates to `/settings`

---

### Requirement: REQ-NAV-003 Router uses history mode with flat named routes
The router SHALL operate in history mode with base `/index.php/apps/decidesk/`. All routes SHALL be named and flat (no nesting). A catch-all `*` route SHALL redirect to `/`.

Required named routes:
- `Dashboard` → `/`
- `MeetingList` → `/meetings`
- `MeetingDetail` → `/meetings/:id`
- `MotionList` → `/motions`
- `MotionDetail` → `/motions/:id`
- `DecisionList` → `/decisions`
- `DecisionDetail` → `/decisions/:id`
- `ParticipantList` → `/participants`
- `ParticipantDetail` → `/participants/:id`
- `GovernanceBodyList` → `/governance-bodies`
- `GovernanceBodyDetail` → `/governance-bodies/:id`
- `Settings` → `/settings`

#### Scenario: Unknown route redirects to dashboard
- **WHEN** a user navigates to an undefined path (e.g. `/unknown`)
- **THEN** the router redirects to `/`

#### Scenario: Detail route receives entity ID as prop
- **WHEN** the router matches `/meetings/abc-123`
- **THEN** the MeetingDetail component receives `entityId = 'abc-123'` as a prop

---

### Requirement: REQ-NAV-004 Store initialisation registers all 17 entity types
`store/store.js` SHALL export `initializeStores()` which fetches settings and calls `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each of the 17 entities defined in ADR-000: ActionItem, AgendaItem, Amendment, Decision, DigitalDocument, GovernanceBody, Meeting, Minutes, MonetaryAmount, Motion, Offer, Order, Participant, Product, Report, Vote, VotingRound.

#### Scenario: All stores registered on init
- **WHEN** `initializeStores()` is called
- **THEN** all 17 entity types are available in the object store without additional setup

#### Scenario: Settings fetch failure is handled
- **WHEN** the settings endpoint returns an error
- **THEN** `initializeStores()` surfaces the error to the UI via the settings store state

---

### Requirement: REQ-NAV-005 Settings page structure
The Settings page SHALL render in order: `CnVersionInfoCard` (first, always), `CnRegisterMapping`, then one `CnSettingsSection` per configurable feature area. A "Re-import register" button SHALL call `POST /api/settings/load`.

#### Scenario: Version card is always first
- **WHEN** the settings page is rendered
- **THEN** `CnVersionInfoCard` is the first visible element on the page

#### Scenario: Re-import button triggers register reload
- **WHEN** the user clicks the "Re-import register" button
- **THEN** a POST request is sent to `/apps/decidesk/api/settings/load` and a success notification is shown
