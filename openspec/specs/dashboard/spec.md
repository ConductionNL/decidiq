---
status: idea
---

# Dashboard Specification

## Purpose

The Decidesk dashboard provides an at-a-glance overview of active decisions, upcoming meetings, pending votes, action items, and governance KPIs. It uses the `CnDashboardPage` component from `@conduction/nextcloud-vue` for a configurable grid layout and integrates with the Nextcloud Dashboard Widget API (`OCP\Dashboard\IWidget`) for platform-level widget exposure. The dashboard serves as the primary entry point for all Decidesk users.

**Standards**: Schema.org (`Dashboard` pattern), Nextcloud Dashboard Widget API
**Feature tier**: MVP

## Requirements

---

### Requirement: Dashboard Layout

The dashboard MUST use the `CnDashboardPage` component to render a configurable widget grid. The default layout MUST provide an immediate overview of governance activity.

**Feature tier**: MVP

#### Scenario: Default grid layout on first load

- GIVEN the user has not customized their dashboard layout
- WHEN the user navigates to the dashboard
- THEN the layout MUST render with the default configuration:
  - Row 1: Four KPI cards (3 columns each) — Active Decisions, Upcoming Meetings, Pending Votes, Overdue Actions
  - Row 2: "My Pending Votes" widget (6 columns) and "Upcoming Meetings" widget (6 columns)
  - Row 3: "Recent Decisions" widget spanning full width (12 columns)
- AND each widget MUST be rendered inside a `CnDashboardPage` widget slot

#### Scenario: Empty state for new installation

- GIVEN a fresh Decidesk installation with no data
- WHEN the user views the dashboard
- THEN a welcome message MUST be displayed: "Welcome to Decidesk! Get started by setting up your first governing body in Settings."
- AND quick action buttons MUST be shown: "Set Up Body", "Create Meeting", "Create Decision"

---

### Requirement: KPI Cards

The dashboard MUST display KPI summary cards showing headline governance metrics using `CnStatsBlock` components.

**Feature tier**: MVP

#### Scenario: Display active decisions count

- WHEN the user views the dashboard
- THEN the "Active Decisions" KPI card MUST display the count of decisions with status not in (`enacted`, `archived`, `rejected`)
- AND clicking the card MUST navigate to the Decisions view filtered by active status

#### Scenario: Display pending votes count

- WHEN the user views the dashboard
- THEN the "Pending Votes" KPI card MUST display the count of decisions currently in `voting` status where the user has not yet cast their vote
- AND if the count is greater than 0, the card MUST use `variant="warning"` (orange accent)
- AND clicking the card MUST navigate to the user's pending votes

#### Scenario: Display overdue action items count

- WHEN the user views the dashboard
- THEN the "Overdue Actions" KPI card MUST display the count of action items past their deadline
- AND if overdue count is greater than 0, the card MUST use `variant="error"` (red accent)
- AND clicking the card MUST navigate to the action items view filtered by overdue

---

### Requirement: My Pending Votes Widget

The dashboard MUST include a widget showing decisions awaiting the current user's vote, ordered by urgency (voting deadline).

**Feature tier**: MVP

#### Scenario: Show pending votes with urgency indicators

- GIVEN the user has 3 decisions pending their vote
- WHEN the dashboard loads
- THEN the "My Pending Votes" widget MUST list each decision with title, body, and time remaining
- AND decisions with less than 24 hours remaining MUST show a red urgency indicator
- AND clicking a decision MUST navigate to the voting interface

#### Scenario: No pending votes

- GIVEN the user has no decisions pending their vote
- WHEN the dashboard loads
- THEN the widget MUST show "No pending votes" with a check mark icon

---

### Requirement: Upcoming Meetings Widget

The dashboard MUST include a widget showing the user's upcoming meetings across all bodies, ordered by date.

**Feature tier**: MVP

#### Scenario: Show upcoming meetings with context

- GIVEN the user is a member of 2 bodies with upcoming meetings
- WHEN the dashboard loads
- THEN the widget MUST list each meeting with title, date/time, body name, and agenda item count
- AND meetings within the next 24 hours MUST be highlighted
- AND clicking a meeting MUST navigate to the meeting detail view

---

### Requirement: Nextcloud Dashboard Widget Integration

The system MUST register a Nextcloud Dashboard widget via `OCP\Dashboard\IWidget` so that Decidesk summary data appears on the Nextcloud main dashboard.

**Feature tier**: MVP

#### Scenario: View Decidesk widget on Nextcloud dashboard

- GIVEN a user with Decidesk access
- WHEN they view the Nextcloud main dashboard
- THEN a "Decidesk" widget MUST be available showing pending votes count and next meeting
- AND clicking the widget MUST navigate to the Decidesk dashboard

## User Stories

1. **New board member accessing knowledge base**: As a new board member, I want to access all historical decisions, current action items, financial status, and governance documents so that I can quickly become effective in my role. (Source: intelligence DB #84)

2. **Institutional investor managing proxy voting across AGMs**: As an institutional investor, I want to manage proxy voting across all portfolio company AGMs from a single dashboard, so that I can efficiently exercise my voting rights at scale. (Source: intelligence DB #5)

3. **Administrator publishing meeting decisions**: As administrator, I want to publish key decisions from ALV and board meetings on the member portal so that all members stay informed about association governance. (Source: intelligence DB #76)

## Acceptance Criteria

- Dashboard uses CnDashboardPage with 12-column grid layout
- Four KPI cards show active decisions, upcoming meetings, pending votes, and overdue actions
- Pending votes widget shows urgency indicators with countdown
- Upcoming meetings widget shows meetings across all user's bodies
- Empty state shows setup guidance for new installations
- Nextcloud Dashboard widget registered via OCP\Dashboard\IWidget
- Quick action buttons in header for creating meetings and decisions
