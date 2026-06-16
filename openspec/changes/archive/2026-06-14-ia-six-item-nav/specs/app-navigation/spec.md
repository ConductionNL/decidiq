# app-navigation Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- ia-six-item-nav

## Purpose

Restructures the Decidesk top-level navigation to ADR-004's six-item, mode-aware
information architecture (C7). The eight-item working menu collapses to six
canonical items (Meetings, Decisions, Action items, Motions, Bodies, Beheer) plus
a Dashboard landing item; GovernanceBodies is promoted into the menu as Bodies;
Minutes / Workspaces / Engagement are demoted out of the top menu (their routes
remain). An `organisatie-modus`-driven label map relabels items per tenant mode
without branching the nav (ADR-004 Rule 1, ADR-006 mechanism 1).

## ADDED Requirements

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

## Non-Functional Requirements

- **Performance:** Label resolution is a synchronous in-memory object lookup per
  menu item; it MUST add no network call and no measurable render delay.
- **Accessibility:** Navigation items MUST keep nc-vue `NcAppNavigationItem`
  active-state, focus, and screen-reader semantics (WCAG 2.1 AA); relabeling
  changes only the visible text, not the markup contract.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); both
  the canonical and the mode-specific label strings MUST be present in the l10n
  source files so external translators can contribute them.

## Acceptance Criteria

- [ ] `src/manifest.json` `menu` lists exactly the six working items + Dashboard landing in the main section
- [ ] GovernanceBodies appears as a Bodies menu item routing to `GovernanceBodies` (`/governance-bodies`)
- [ ] Minutes, Workspaces, and Engagement no longer appear in the top menu (routes retained)
- [ ] `src/config/modeLabels.js` exists and is keyed by the five modes
- [ ] `translateForApp` resolves the Bodies label per `organisatie_modus` before calling `t()`

## Notes

- ADR-004 (Information Architecture) §Top-level navigation + Rule 1; ADR-006
  (Mode Adaptation Over Parallel Entities) mechanism 1.
- DEFERRED: per-mode relabeling is wired for the Bodies item in C7; the map
  scaffolds the other items for a follow-up change.
- DEFERRED: Dashboard is kept as a distinct landing item (route `/`); folding it
  into Meetings is a product decision.

## MODIFIED Requirements

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
