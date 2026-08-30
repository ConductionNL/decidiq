---
delta: openspec/specs/dashboard/spec.md
---

# Spec Delta: dashboard — decidesk-dashboard-v2-widgets

This delta extends `openspec/specs/dashboard/spec.md` with the widget component requirements introduced by this change. The existing requirements (Dashboard Layout, KPI Cards, My Pending Votes Widget, Upcoming Meetings Widget, Nextcloud Dashboard Widget Integration) are not modified here; the layout-wiring change (`decidesk-dashboard-v2-layout`) will modify the Dashboard Layout and KPI Cards requirements. This delta adds requirements for the eleven new widget components plus the cross-cutting i18n and refresh behaviour.

---

## ADDED Requirements

---

### REQ-001: Running Processes Widget

The dashboard MUST include a `RunningProcessesWidget` component that shows motions currently in flight, grouped by their lifecycle stage.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display in-flight motions grouped by lifecycle

- GIVEN the user navigates to the dashboard
- WHEN the `RunningProcessesWidget` loads
- THEN it SHALL fetch motions with lifecycle in `[submitted, under-discussion, voting]`
- AND display them grouped under labelled stage sections
- AND each motion entry SHALL show its title
- AND clicking a motion entry SHALL navigate to the motion detail view

#### Scenario: Empty state for no in-flight motions

- GIVEN no motions have lifecycle in `[submitted, under-discussion, voting]`
- WHEN the widget loads
- THEN the widget SHALL display a message indicating no active motions

---

### REQ-002: My Action Items Widget

The dashboard MUST include a `MyActionItemsWidget` component showing action items assigned to the current user that are open or in-progress, sorted by dueDate ascending.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display current user's open action items

- GIVEN the user has 3 action items assigned to them with taskStatus open or in-progress
- WHEN the `MyActionItemsWidget` loads
- THEN it SHALL display those action items sorted by dueDate ascending
- AND each item SHALL show its title, dueDate, and status badge
- AND items with dueDate in the past SHALL be visually distinguished as overdue

#### Scenario: No action items assigned to user

- GIVEN the user has no action items assigned to them with taskStatus open or in-progress
- WHEN the widget loads
- THEN the widget SHALL display an empty state message

---

### REQ-003: Recent Decisions Widget

The dashboard MUST include a `RecentDecisionsWidget` component showing the latest N decisions (default 10) with outcome badge and publication status badge. Badge values MUST map the real schema enums (outcome: adopted/rejected/null; isPublished: internal/public/confidential).

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display recent decisions with badges

- GIVEN at least one decision exists in the register
- WHEN the `RecentDecisionsWidget` loads
- THEN it SHALL display decisions sorted by decisionDate descending, up to 10 entries
- AND each decision SHALL show its title, an outcome badge (adopted/rejected, or "undecided" when outcome is null — the Decision schema's outcome enum is [adopted, rejected]), and a publication status badge (internal/public/confidential)
- AND clicking a decision SHALL navigate to the decision detail view

---

### REQ-004: Governance Health Widget

The dashboard MUST include a `GovernanceHealthWidget` component that renders a live two-series chart of `quorumPercentage` and `actionItemCompletionRate` from recent meetings' materialized fields. (A declarative manifest `type: "chart"` widget was ruled out: the lib's chart dataSource cannot assemble two live series — see design.md Decision 5.)

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Render governance health chart from meeting data

- GIVEN at least two meetings with materialized `quorumPercentage` and `actionItemCompletionRate` fields exist
- WHEN the `GovernanceHealthWidget` loads
- THEN it SHALL render a chart with up to 12 recent meetings on the x-axis
- AND plot `quorumPercentage` as one live series and `actionItemCompletionRate` as another
- AND the data SHALL come from fetched meeting objects, never from hardcoded values

#### Scenario: Insufficient data state

- GIVEN fewer than two meetings with materialized governance health fields exist
- WHEN the widget loads
- THEN it SHALL display a "Not enough data" placeholder rather than an empty chart

---

### REQ-005: Dashboard Empty State

The dashboard MUST include a `DashboardEmptyState` component shown when no governance body exists, guiding the user to initial setup.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display empty state for fresh installation

- GIVEN a fresh Decidesk installation with no governance bodies in the register
- WHEN the user navigates to the dashboard
- THEN the `DashboardEmptyState` component SHALL be rendered
- AND it SHALL display the message: "Welcome to Decidesk! Get started by setting up your first governing body in Settings."
- AND it SHALL show quick action buttons: "Set Up Body", "Create Meeting", "Create Decision"
- AND clicking "Set Up Body" SHALL navigate to the Settings page

---

### REQ-006: Dashboard Refresh Behaviour

All dashboard widget components MUST respond to a dashboard-wide refresh signal without requiring a full page remount.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Widget refreshes when dashboard refresh is triggered

- GIVEN a dashboard widget is rendered and displaying data
- WHEN the user triggers a dashboard refresh (e.g., via a header refresh action)
- THEN each widget MUST re-execute its `load()` method and update its displayed data
- AND the refresh MUST NOT cause a full page remount or lose widget layout state

---

### REQ-007: Widget i18n — English Source Keys

All user-visible strings in dashboard widget components MUST use `t('decidesk', '...')` with English source strings as keys.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: All widget labels use i18n

- GIVEN any dashboard widget component
- WHEN inspecting the component template and script
- THEN every user-visible string SHALL be wrapped in `t('decidesk', 'English source string')`
- AND no Dutch strings SHALL appear as raw text or as i18n keys

---

### REQ-008: Upcoming Meetings KPI Widget

The dashboard MUST include an `UpcomingMeetingsKpiWidget` component showing the count of meetings with `lifecycle=scheduled` and `scheduledDate >= now`.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display count of upcoming scheduled meetings

- GIVEN 3 meetings exist with lifecycle=scheduled and scheduledDate in the future
- WHEN the `UpcomingMeetingsKpiWidget` loads
- THEN it SHALL display a `CnStatsBlock` with count 3

---

### REQ-009: Pending Votes KPI Widget

The dashboard MUST include a `PendingVotesKpiWidget` component showing the count of open voting-rounds where the current user has not yet cast a vote. The widget SHALL use `variant="warning"` when count > 0.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Count open voting rounds pending user's vote

- GIVEN 2 voting-rounds with lifecycle=open exist and the current user has voted in 1 of them
- WHEN the `PendingVotesKpiWidget` loads
- THEN it SHALL display count 1
- AND the `CnStatsBlock` SHALL use variant="warning"

#### Scenario: No pending votes

- GIVEN the current user has voted in all open voting-rounds (or none exist)
- WHEN the widget loads
- THEN count SHALL be 0 and variant SHALL be "default"

#### Scenario: User without participant record sees zero

- GIVEN open voting-rounds exist
- AND no participant record has `nextcloudUserId` matching the current user
- WHEN the `PendingVotesKpiWidget` loads
- THEN count SHALL be 0 and variant SHALL be "default" (the user is not a voting member)

---

### REQ-010: Overdue Actions KPI Widget

The dashboard MUST include an `OverdueActionsKpiWidget` component showing the count of action items with `dueDate` in the past and `taskStatus` not in `[completed, cancelled]`. The widget SHALL use `variant="error"` when count > 0.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Count overdue action items

- GIVEN 2 action items have dueDate < now and taskStatus=open
- WHEN the `OverdueActionsKpiWidget` loads
- THEN it SHALL display count 2
- AND the `CnStatsBlock` SHALL use variant="error"

#### Scenario: No overdue action items

- GIVEN no action items are overdue
- WHEN the widget loads
- THEN count SHALL be 0 and variant SHALL be "default"

---

### REQ-011: Upcoming Meetings List Widget

The dashboard MUST include an `UpcomingMeetingsListWidget` component listing upcoming meetings (lifecycle=scheduled, scheduledDate >= now) sorted by scheduledDate ascending. Meetings within the next 24 hours MUST be highlighted. Clicking a meeting entry MUST navigate to the meeting detail view.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: List upcoming meetings with 24h highlight

- GIVEN 3 meetings are scheduled in the future, one within the next 24 hours
- WHEN the `UpcomingMeetingsListWidget` loads
- THEN all 3 SHALL be listed sorted by scheduledDate ascending
- AND the meeting within 24 hours SHALL have a visual highlight indicating urgency
- AND each entry SHALL show: title, scheduledDate, governance body name, agenda item count

#### Scenario: Navigate to meeting detail on click

- GIVEN at least one upcoming meeting is listed
- WHEN the user clicks the meeting entry
- THEN the app SHALL navigate to the meeting detail view for that meeting

---

### REQ-012: Pending Votes List Widget

The dashboard MUST include a `PendingVotesListWidget` component listing decisions/voting-rounds awaiting the current user's vote, with a deadline countdown. Entries with less than 24 hours remaining MUST show a red urgency indicator. An empty state MUST be shown when no pending votes exist.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: List decisions pending the user's vote with urgency

- GIVEN 2 voting-rounds are open and the user has not voted in either
- AND one has a deadline within 24 hours
- WHEN the `PendingVotesListWidget` loads
- THEN both SHALL be listed
- AND the entry with deadline < 24h SHALL show a red urgency indicator
- AND each entry SHALL show the associated decision/motion title and time remaining

#### Scenario: Empty state when no votes pending

- GIVEN the user has voted in all open voting-rounds or none exist, OR no participant record matches the current user
- WHEN the widget loads
- THEN the widget SHALL display "No pending votes" with a checkmark icon

#### Scenario: Navigate to voting interface on click

- GIVEN at least one pending vote is listed
- WHEN the user clicks the entry
- THEN the app SHALL navigate to the voting interface for that decision

---

### REQ-013: Active Decisions KPI Widget

The dashboard MUST include an `ActiveDecisionsKpiWidget` component showing the count of decisions whose `outcome` is null (not yet adopted or rejected — the Decision schema has no lifecycle field). Clicking the card MUST navigate to the Decisions view.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Count undecided decisions

- GIVEN 3 decisions exist of which 1 has outcome "adopted" and 2 have no outcome
- WHEN the `ActiveDecisionsKpiWidget` loads
- THEN it SHALL display a `CnStatsBlock` with count 2

#### Scenario: Navigate to decisions view on click

- GIVEN the widget is rendered
- WHEN the user clicks the card
- THEN the app SHALL navigate to the Decisions view

---
