# app-navigation Specification (delta for ia-six-clusters)

## MODIFIED Requirements

### Requirement: REQ-NAV-002 MainMenu lists six canonical top-level groups, populated via menu-layout.json

`MainMenu` SHALL render the navigation via `CnAppRoot`/`CnAppNav` from the
merged manifest `menu` (`src/manifest.json` + every `src/manifest.d/*.json`
fragment + `src/menu-layout.json`, per `buildManifest()`). The primary
(non-footer, non-settings) top-level entries SHALL be exactly six, matching
ADR-004's v2 table: **Dashboard** (landing, route `Dashboard`), **Meetings**
(route `Meetings`), **Decisions** (route `Decisions`), **Tasks &
Commitments** (id `ActionItems`, route `ActionItems` — label renamed from
"Action items"), **Organisation** (id `GovernanceBodies`, route
`GovernanceBodies` — label renamed from "Bodies"), and **Registers** (id
`Registers`, a route-less group anchor — a pure expandable parent with no
page of its own). Every fragment-declared top-level entry that is not one of
these six base ids SHALL be relocated under one of them (or the settings
gear, or removed) via `src/menu-layout.json`, never left as a 7th+ primary
entry. Motions SHALL NOT appear as a standalone top-level item (unchanged
from REQ-RMN-001). "Beheer" (operator-only configuration) is NOT a menu
item in v2 — it is served by the Nextcloud settings framework
(`/settings/admin/decidesk`, ADR-079 Decision 1) plus the `NcAppNavigationSettings`
gear foldout (ADR-079 Decision 2), not a nav row.

<!-- Previous behavior: the menu listed Dashboard, Meetings, Decisions,
Action items, Motions, Bodies, and a Beheer/Settings entry in the settings
section, with Minutes/Workspaces/Engagement demoted but not part of any
fragment-relocation mechanism. menu-layout.json did not exist as a concept
in that version of this requirement; ia-six-clusters is the first change to
populate it. -->

#### Scenario: Exactly six primary top-level items are rendered

- WHEN the app is in the ready state
- THEN the main menu shows exactly six primary (non-footer, non-settings)
  items: Dashboard, Meetings, Decisions, Tasks & Commitments, Organisation,
  Registers
- AND no 7th primary top-level item is present

#### Scenario: Organisation item is present and routes to GovernanceBodies

- WHEN the app is in the ready state
- THEN an Organisation navigation item is visible
- AND clicking it navigates to the `GovernanceBodies` route
  (`/governance-bodies`)
- AND its underlying menu id remains `GovernanceBodies` (label-only rename)

#### Scenario: Registers is a route-less expandable group

- WHEN the app is in the ready state
- THEN a Registers navigation item is visible with no `route`/`href`/`action`
  of its own
- AND clicking its title toggles its children open/closed rather than
  navigating

#### Scenario: Active route is highlighted

- WHEN the user is on the `/meetings` route
- THEN the Meetings navigation item is marked as active

## ADDED Requirements

### Requirement: REQ-NAV-009 Fragment leaf entries relocate into a canonical group via menu-layout.json relocations

`src/menu-layout.json#relocations` SHALL map each fragment top-level menu
entry id that belongs under an existing base group to that group's id, so
`applyMenuRelocations()` re-homes the leaf as a child of the group and the
leaf disappears from the primary top-level list. The mapping SHALL be:

| Group (id) | Relocated leaf ids |
|---|---|
| `Meetings` | `MondelingeVragen`, `Interpellaties`, `IngekomenStukken`, `Raadsinformatiebrieven`, `KascommissieVerklaringen` |
| `Decisions` | `Raadplegingen`, `Consultations`, `WorTrajecten`, `Adviesaanvragen`, `Zienswijzerondes`, `Voordrachten` |
| `ActionItems` | `Toezeggingen`, `Termijnagenda`, `PCCycli`, `Goals` |
| `GovernanceBodies` | `Roosters`, `Nevenfuncties`, `Geschenken`, `OnboardingTrajecten`, `OffboardingTrajecten`, `ProxyAuthorizations` |
| `Registers` | `Regelingen`, `GoverningDocuments`, `Bevoegdheidstoedelingen`, `Geheimhoudingen` |

`Voordrachten` relocates under `Decisions` (not `GovernanceBodies`): per the
product decision and ADR-005, a nomination IS a decision
(`decisionType=appointment`, change `appointment-decision-type-schema`), so
its register entry belongs with the decision surfaces until that chain
retires it. `Goals` is a forward declaration: the id is introduced by the
parallel change `organisation-goals` (fragment
`src/manifest.d/organisation-goals.json`); `applyMenuRelocations()` is a
no-op for a source id absent from the merged menu, so declaring the
placement before the fragment lands is safe and keeps the nav-ceiling gate
green in either merge order.

The relocated leaf's own page and route SHALL remain unchanged; only its
position in the rendered menu changes.

#### Scenario: A fragment leaf renders as a child of its target group

- GIVEN `src/menu-layout.json#relocations` maps `MondelingeVragen` to
  `Meetings`
- WHEN the merged menu is built
- THEN `MondelingeVragen` does not appear as a primary top-level entry
- AND `MondelingeVragen` appears as a child of the `Meetings` group
- AND navigating directly to its page route still renders the page

#### Scenario: A relocated leaf's page stays routable

- GIVEN any id listed as a relocation source
- WHEN a user navigates directly to that entry's page route
- THEN the page renders identically to before the relocation

### Requirement: REQ-NAV-010 Duplicate or filter-chip nav rows are removed but stay routable

`src/menu-layout.json#removals` SHALL list menu entry ids that are retired
as top-level (or relocated) nav rows because they duplicate a filter,
scope, or another entry's function, while their underlying page remains
registered and reachable by direct route for deep links and e2e specs. The
list SHALL be: `UrgentDecisions` (a filter chip on Decisions, not a
distinct register), `MyDeclarations` (an unscoped duplicate of
`Nevenfuncties`), `Zienswijzen` (a child facet of `Zienswijzerondes`), and
`FeaturesRoadmapMenu` (footer entry retired from the nav; its `FeaturesRoadmap`
page stays routable).

#### Scenario: A removed entry does not render anywhere in the menu

- GIVEN `FeaturesRoadmapMenu` is listed in `menu-layout.json#removals`
- WHEN the merged menu is built
- THEN no primary, footer, or settings entry with id `FeaturesRoadmapMenu`
  is present

#### Scenario: A removed entry's page remains routable

- GIVEN `FeaturesRoadmapMenu` has been removed from the menu
- WHEN a user navigates directly to the `FeaturesRoadmap` page route
- THEN the page renders as before

### Requirement: REQ-NAV-011 Operator/definition config entries lift into the settings gear foldout

`src/menu-layout.json#settingsSection` SHALL list menu entry ids for
operator-only definition/configuration surfaces that do not warrant a
primary top-level slot, so `applySettingsSection()` tags each with
`section: "settings"` and moves it into the `NcAppNavigationSettings` gear
foldout rather than the scrollable primary nav — consistent with ADR-079's
model of keeping non-operational configuration out of the primary working
surface (ADR-004 Rule 4, "Beheer is operator-only"). The list SHALL be:
`Termijnregelingen`, `VveDecisionTemplates`, `ModelreglementPresets`,
`VveConfigurations`, `WooDiwoo`, `GeheimhoudingGronden`, `ModerationQueue`,
`UserSettingsMenu`.

#### Scenario: A listed entry renders inside the settings gear, not the primary nav

- GIVEN `Termijnregelingen` is listed in
  `menu-layout.json#settingsSection`
- WHEN the merged menu is built
- THEN `Termijnregelingen` does not appear as a primary top-level entry or
  as a child of any primary group
- AND `Termijnregelingen` appears inside the `NcAppNavigationSettings` gear
  foldout

#### Scenario: A lifted entry's page stays routable

- GIVEN any id listed in `settingsSection`
- WHEN a user navigates directly to that entry's page route
- THEN the page renders identically to before the lift

### Requirement: REQ-NAV-012 The settings gear carries exactly one Personal settings entry

`src/manifest.json` SHALL set `nav.includePersonalSettings: false` so
`CnAppNav`'s auto-prepended generic "Personal settings" entry (which opens
`NcAppSettingsDialog` via a route-less click handler) does not render
alongside decidesk's own `UserSettingsMenu` fragment entry (a real
`/user-settings` route rendering `UserSettingsPage`, decidesk's built
per-user preference surface with notification/display/delegation/
communication sections). The gear SHALL show exactly one entry labelled
"Personal settings", resolving to the real page.

#### Scenario: Only one Personal settings entry renders in the gear

- WHEN the settings gear foldout is opened
- THEN exactly one entry labelled "Personal settings" is present
- AND clicking it navigates to `/user-settings`, not a generic dialog

#### Scenario: The generic auto-prepended entry is suppressed

- GIVEN `nav.includePersonalSettings` is `false` in `src/manifest.json`
- WHEN the merged manifest is built
- THEN `CnAppNav` does not auto-prepend its own route-less "Personal
  settings" entry
