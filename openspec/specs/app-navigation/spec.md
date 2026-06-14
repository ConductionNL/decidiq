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
`MainMenu` SHALL render the navigation via `CnAppRoot`/`CnAppNav` from the
bundled manifest `menu`. The menu SHALL list ADR-004's six canonical working
items plus a Dashboard landing item: Dashboard (landing, route `Dashboard`),
Meetings (`Meetings`), Decisions (`Decisions`), Action items (`ActionItems`),
Motions (`Motions`), Bodies (`GovernanceBodies`), and Beheer (the Settings entry,
settings section). Minutes, Workspaces, and Engagement SHALL NOT appear as
top-level menu items (their routes remain reachable). A settings link (Beheer)
SHALL appear via the manifest `settings` section; Documentation and
Features-roadmap SHALL remain in the `footer` section.

#### Scenario: Six working items plus Dashboard are rendered
- WHEN the app is in the ready state
- THEN the main menu shows Dashboard, Meetings, Decisions, Action items, Motions, and Bodies
- AND Beheer (Settings) appears in the settings section
- AND Minutes, Workspaces, and Engagement are NOT shown as top-level items

#### Scenario: Bodies item is present and routes to GovernanceBodies
- WHEN the app is in the ready state
- THEN a Bodies navigation item is visible
- AND clicking it navigates to the `GovernanceBodies` route (`/governance-bodies`)

#### Scenario: Active route is highlighted
- WHEN the user is on the `/meetings` route
- THEN the Meetings navigation item is marked as active

### Requirement: REQ-NAV-003 Router uses history mode with flat named routes
The router SHALL operate in history mode with base `/index.php/apps/decidesk/`.
All routes SHALL be named and flat (no nesting). A catch-all `*` route SHALL
redirect to `/`. Routes for demoted surfaces (Minutes, Workspaces, Engagement)
SHALL remain registered even though they no longer appear as top-level menu items.

Required named routes (non-exhaustive; demotion does not remove routes):
- `Dashboard` → `/`
- `MeetingList` / `Meetings` → `/meetings`
- `MeetingDetail` → `/meetings/:id`
- `MotionList` / `Motions` → `/motions`
- `MotionDetail` → `/motions/:id`
- `DecisionList` / `Decisions` → `/decisions`
- `DecisionDetail` → `/decisions/:id`
- `ActionItems` → `/action-items`
- `GovernanceBodies` → `/governance-bodies`
- `GovernanceBodyDetail` → `/governance-bodies/:id`
- `Minutes` → `/minutes` (retained; demoted from menu)
- `Workspaces` → `/workspaces` (retained; demoted from menu)
- `Engagement` → `/engagement` (retained; demoted from menu)
- `Settings` → `/settings`

#### Scenario: Unknown route redirects to dashboard
- WHEN a user navigates to an undefined path (e.g. `/unknown`)
- THEN the router redirects to `/`

#### Scenario: Demoted surface remains reachable by deep link
- WHEN a user navigates directly to `/minutes`, `/workspaces`, or `/engagement`
- THEN the corresponding page renders even though the item is not in the top menu

#### Scenario: Detail route receives entity ID as prop
- WHEN the router matches `/meetings/abc-123`
- THEN the MeetingDetail component receives `entityId = 'abc-123'` as a prop

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

### Requirement: REQ-NAV-006 Mode-aware label resolution at the translate chokepoint
The app shell SHALL resolve every navigation label through a declarative
mode-keyed label map BEFORE applying the i18n translation. The label map
(`src/config/modeLabels.js`) SHALL be a static object keyed by
`organisatie-modus` (`gov` / `corp` / `assoc` / `ops` / `citizen`), each mapping a
canonical English label to a mode-specific label key. The `translate` function
passed to `CnAppRoot` (`translateForApp` in `App.vue`) SHALL look up the canonical
label in the map for the active mode, fall back to the canonical label when no
mapping exists, and pass the result to `t('decidesk', …)`. The navigation
structure SHALL NOT branch per mode — only the displayed label SHALL change.

#### Scenario: Bodies item relabels by mode
- GIVEN `organisatie_modus` is `gov`
- WHEN the navigation renders the Bodies item
- THEN its label resolves to "Fracties & Organen"
- AND WHEN `organisatie_modus` is `corp` THEN the same item's label resolves to "Board"
- AND WHEN `organisatie_modus` is `ops` THEN the same item's label resolves to "Teams"

#### Scenario: Unmapped label falls back to canonical
- GIVEN a menu item whose canonical label has no entry in the active mode's map
- WHEN the navigation renders that item
- THEN the canonical label is passed to `t('decidesk', …)` unchanged

#### Scenario: Default mode keeps governance labels
- GIVEN no `organisatie_modus` is configured
- WHEN the navigation renders
- THEN the default mode `gov` applies and existing Dutch governance labels are shown

