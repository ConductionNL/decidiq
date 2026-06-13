---
delta: openspec/specs/dashboard/spec.md
---

# Spec Delta: dashboard — decidesk-dashboard-v2-layout

This delta modifies two existing requirements in `openspec/specs/dashboard/spec.md`. The prior delta (`decidesk-dashboard-v2-widgets`) added REQ-001 through REQ-013 including REQ-004 (Governance Health Widget) and REQ-013 (Active Decisions KPI Widget). The `Nextcloud Dashboard Widget Integration` requirement is not touched here (deferred per the original spec). i18n, refresh, and individual widget-component requirements (REQ-001 through REQ-013) are not re-stated — this delta focuses only on the layout wiring and KPI card grid positions.

---

## MODIFIED Requirements

---

### Requirement: Dashboard Layout

The dashboard MUST use the `CnDashboardPage` component to render a configurable widget grid. The default layout MUST provide an immediate overview of governance activity using the five-row v2 grid.

**Feature tier**: MVP

#### Scenario: Default grid layout on first load

@e2e annotate REQ-dashboard-layout-default-grid

- GIVEN the user has not customized their dashboard layout
- WHEN the user navigates to `/apps/decidesk/`
- THEN the layout MUST render with the default v2 configuration:
  - Row 1 (gridY=0, gridHeight=2): four KPI cards each 3 columns wide — `active-decisions` (custom, slot: `ActiveDecisionsKpiWidget`), `upcoming-meetings-kpi` (custom, slot: `UpcomingMeetingsKpiWidget`), `pending-votes-kpi` (custom, slot: `PendingVotesKpiWidget`), `overdue-actions-kpi` (custom, slot: `OverdueActionsKpiWidget`)
  - Row 2 (gridY=2, gridHeight=4): `upcoming-meetings-list` (6 cols) and `pending-votes-list` (6 cols)
  - Row 3 (gridY=6, gridHeight=4): `running-processes` (6 cols) and `my-action-items` (6 cols)
  - Row 4 (gridY=10, gridHeight=4): `recent-decisions` spanning full 12 columns
  - Row 5 (gridY=14, gridHeight=4): `minutes-in-review` (6 cols, stats-block, the only stats-block in the layout) and `governance-health` (6 cols, custom, slot: `GovernanceHealthWidget`)
- AND each custom widget MUST be rendered via the `slots` mapping in the manifest

#### Scenario: Empty state for new installation

@e2e annotate REQ-dashboard-layout-empty-state

- GIVEN a fresh Decidesk installation with no widgets in the dashboard layout (empty `layout` array)
- WHEN the user views the dashboard
- THEN `CnDashboardPage` SHALL render the `#empty` slot content
- AND `DashboardEmptyState` SHALL be displayed via the manifest's `emptyComponent` configuration
- AND the message "Welcome to Decidesk! Get started by setting up your first governing body in Settings." MUST be visible
- AND quick action buttons MUST be shown: "Set Up Body", "Create Meeting", "Create Decision"

---

### Requirement: KPI Cards

The dashboard MUST display four KPI summary cards in Row 1 (gridY=0, each gridWidth=3, gridHeight=2). Three are custom slot components; one (`active-decisions`) is also a custom slot component counting `outcome == null` client-side.

**Feature tier**: MVP

#### Scenario: Display active decisions count

@e2e annotate REQ-kpi-active-decisions

- WHEN the user views the dashboard
- THEN the "Active Decisions" KPI card (id: `active-decisions`, type: `custom`, slot: `ActiveDecisionsKpiWidget`) MUST be rendered in the grid at gridX=0, gridY=0, gridWidth=3, gridHeight=2
- AND the widget SHALL display the count of decisions whose `outcome` is null (not yet adopted or rejected — see REQ-013 in the widgets change for the component specification)

#### Scenario: Display upcoming meetings KPI

@e2e annotate REQ-kpi-upcoming-meetings

- WHEN the user views the dashboard
- THEN the "Upcoming meetings" KPI card (id: `upcoming-meetings-kpi`, type: `custom`, slot: `UpcomingMeetingsKpiWidget`) MUST be rendered in the grid at gridX=3, gridY=0, gridWidth=3, gridHeight=2

#### Scenario: Display pending votes KPI

@e2e annotate REQ-kpi-pending-votes

- WHEN the user views the dashboard
- THEN the "Pending votes" KPI card (id: `pending-votes-kpi`, type: `custom`, slot: `PendingVotesKpiWidget`) MUST be rendered in the grid at gridX=6, gridY=0, gridWidth=3, gridHeight=2

#### Scenario: Display overdue actions KPI

@e2e annotate REQ-kpi-overdue-actions

- WHEN the user views the dashboard
- THEN the "Overdue actions" KPI card (id: `overdue-actions-kpi`, type: `custom`, slot: `OverdueActionsKpiWidget`) MUST be rendered in the grid at gridX=9, gridY=0, gridWidth=3, gridHeight=2

