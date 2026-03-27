---
status: idea
---

# Dashboard Specification

## Purpose

The Decidesk dashboard provides an at-a-glance overview of active decisions, upcoming meetings, pending votes, action items, and governance KPIs. It uses the `CnDashboardPage` component from `@conduction/nextcloud-vue` for a configurable grid layout and integrates with the Nextcloud Dashboard Widget API (`OCP\Dashboard\IWidget`) for platform-level widget exposure. The dashboard serves as the primary entry point for all Decidesk users.

**Standards**: Schema.org (`Dashboard` pattern), Nextcloud Dashboard Widget API
**Feature tier**: MVP

## Evidence Base

### Market Research

| Statistic | Source |
|-----------|--------|
| $541 billion wasted on pointless meetings globally per year | Intelligence DB insight #1 (Doodle) |
| 44% of action items never completed after meetings | External source #342 (Count.co) |
| 78% of workers say they attend too many meetings | External source #319 (Fellow) |
| Only 45% of boards deemed effective (Gartner benchmark) | External source #172 (Gartner Peer Insights) |
| AI meeting tool usage grew 17x in 2024 | Intelligence DB insight #5 |
| 438 user stories reference participants/members/stakeholders -- largest integration category | Intelligence DB insight #29 |
| 158 user stories reference scheduling, deadlines, or agenda items | Intelligence DB insight #26 |
| 213 user stories reference tasks and action items | Intelligence DB insight #26 |

### KPI Benchmarks

The following benchmarks from Flowtrace (2.2M meetings analyzed, external source #322) and Worklytics (external source #347) inform the KPI cards and analytics:

| KPI | Benchmark | Source |
|-----|-----------|--------|
| Meetings per week per person | 8-12 (knowledge workers) | Worklytics #347 |
| Average meeting duration | 45-60 min | Flowtrace #322 |
| Decision rate | 2-3 decisions per meeting | Flowtrace #323 |
| Action item completion rate | >90% target (EOS), 56% actual average | Count.co #342 |
| Meeting cost per employee/year | $25K average | MeetingKing #325 |
| Focus time ratio | >50% of workday without meetings | Worklytics #347 |
| Agenda compliance | 37% average (low!) | Fellow #319 |
| Attendance rate | >80% target | Flowtrace #323 |

### Dashboard Design References

| Source | Key Pattern | Evidence |
|--------|-------------|----------|
| Flowtrace Meeting Analytics Dashboard | Executive tiles, team investment view, meeting audit, trend visualization | External source #349 |
| Worklytics Manager Scorecard | 12 highest-impact KPIs with benchmarks, focus time ratio | External source #347 |
| iBabs Board Portal | Quorum tracking, RSVP, next meeting, pending actions | External source #97 |
| BoardPro Decision Register | Automated decision register, searchable by keyword/date | External source #114 |
| Dimpact ZAC Dashboard | Customizable drag-and-drop dashboard with signaling cards | Competitor feature analysis |
| EspoCRM Dashboard | Draggable dashlets, pipeline reports, stream/activity feed | Competitor feature analysis |

### Competitor Analysis

| Competitor | Dashboard Features | Gap |
|------------|-------------------|-----|
| Diligent Boards | Board book summarization, action tracking, compliance | No meeting cost KPIs, $48K-$155K/year |
| iBabs | Next meeting, pending actions, document status | No analytics, no cost tracking, no KPI benchmarks |
| Fellow.app | Action items, meeting notes, AI summaries | No governance-specific KPIs, no voting |
| Flowtrace | Meeting cost, team comparison, trend visualization | SaaS-only, no governance, no decision tracking |
| BoardPro | Decision register, action items, meeting calendar | No cost analytics, no personal scorecard |
| Dimpact ZAC | Signaling cards, configurable worklists | Case management focused, not governance |

## Requirements

---

### Requirement: Dashboard Layout

The dashboard MUST use the `CnDashboardPage` component to render a configurable widget grid. The default layout MUST provide an immediate overview of governance activity.

**Feature tier**: MVP

#### Scenario: Default grid layout on first load

- GIVEN the user has not customized their dashboard layout
- WHEN the user navigates to the dashboard
- THEN the layout MUST render with the default configuration:
  - Row 1: Four KPI cards (3 columns each) -- Active Decisions, Upcoming Meetings, Pending Votes, Overdue Actions
  - Row 2: "My Pending Votes" widget (6 columns) and "Upcoming Meetings" widget (6 columns)
  - Row 3: "Recent Decisions" widget spanning full width (12 columns)
- AND each widget MUST be rendered inside a `CnDashboardPage` widget slot

#### Scenario: Empty state for new installation

- GIVEN a fresh Decidesk installation with no data
- WHEN the user views the dashboard
- THEN a welcome message MUST be displayed: "Welcome to Decidesk! Get started by setting up your first governing body in Settings."
- AND quick action buttons MUST be shown: "Set Up Body", "Create Meeting", "Create Decision"

#### Scenario: Role-based dashboard variants

- GIVEN a user with the "chair" role in one body and "member" role in another
- WHEN they view the dashboard
- THEN widgets MUST aggregate data across all bodies the user belongs to
- AND role-specific actions MUST be contextual (e.g., chair sees "Start Meeting", member sees "Join Meeting")

---

### Requirement: KPI Cards

The dashboard MUST display KPI summary cards showing headline governance metrics using `CnStatsBlock` components. KPI values MUST be backed by benchmark data from external research.

**Feature tier**: MVP
**Evidence**: 15 essential meeting KPIs identified by Flowtrace (external source #323). 12 highest-impact manager KPIs with benchmarks by Worklytics (external source #347).

#### Scenario: Display active decisions count

- WHEN the user views the dashboard
- THEN the "Active Decisions" KPI card MUST display the count of decisions with status not in (`enacted`, `archived`, `rejected`)
- AND clicking the card MUST navigate to the Decisions view filtered by active status

#### Scenario: Display pending votes count with urgency

- WHEN the user views the dashboard
- THEN the "Pending Votes" KPI card MUST display the count of decisions currently in `voting` status where the user has not yet cast their vote
- AND if the count is greater than 0, the card MUST use `variant="warning"` (orange accent)
- AND if any vote has less than 24 hours remaining, the card MUST use `variant="error"` (red accent)
- AND clicking the card MUST navigate to the user's pending votes

#### Scenario: Display overdue action items count

- WHEN the user views the dashboard
- THEN the "Overdue Actions" KPI card MUST display the count of action items past their deadline
- AND if overdue count is greater than 0, the card MUST use `variant="error"` (red accent)
- AND clicking the card MUST navigate to the action items view filtered by overdue
- AND the card MUST show the completion rate percentage alongside the count (benchmark: >90% target per EOS Level 10)

#### Scenario: Display upcoming meetings count

- WHEN the user views the dashboard
- THEN the "Upcoming Meetings" KPI card MUST display the count of meetings within the next 7 days
- AND if a meeting is within 24 hours, the card MUST show the next meeting time
- AND clicking the card MUST navigate to the meetings calendar view

---

### Requirement: My Pending Votes Widget

The dashboard MUST include a widget showing decisions awaiting the current user's vote, ordered by urgency (voting deadline).

**Feature tier**: MVP
**Evidence**: Institutional investor story #5 shows need for centralized vote management. Pending vote urgency is critical for governance compliance deadlines.

#### Scenario: Show pending votes with urgency indicators

- GIVEN the user has 3 decisions pending their vote
- WHEN the dashboard loads
- THEN the "My Pending Votes" widget MUST list each decision with title, body, and time remaining
- AND decisions with less than 24 hours remaining MUST show a red urgency indicator
- AND decisions with less than 48 hours remaining MUST show an orange urgency indicator
- AND clicking a decision MUST navigate to the voting interface

#### Scenario: No pending votes

- GIVEN the user has no decisions pending their vote
- WHEN the dashboard loads
- THEN the widget MUST show "No pending votes" with a check mark icon

#### Scenario: Quick vote from dashboard

- GIVEN a simple yes/no vote pending
- WHEN the user clicks the quick-vote action on a decision card
- THEN a vote confirmation dialog MUST appear
- AND the user MUST be able to cast their vote without leaving the dashboard
- AND the KPI card MUST update immediately after voting

---

### Requirement: Upcoming Meetings Widget

The dashboard MUST include a widget showing the user's upcoming meetings across all bodies, ordered by date. The widget MUST provide a calendar strip view for the current week.

**Feature tier**: MVP
**Evidence**: 158 user stories reference scheduling and agenda items (insight #26). Calendar integration is the second-highest-value Nextcloud integration.

#### Scenario: Show upcoming meetings with context

- GIVEN the user is a member of 2 bodies with upcoming meetings
- WHEN the dashboard loads
- THEN the widget MUST list each meeting with title, date/time, body name, and agenda item count
- AND meetings within the next 24 hours MUST be highlighted
- AND clicking a meeting MUST navigate to the meeting detail view

#### Scenario: Calendar strip for the current week

- GIVEN the current week has 3 meetings
- WHEN the dashboard loads
- THEN a horizontal calendar strip MUST show days of the current week
- AND days with meetings MUST show dot indicators with the count
- AND clicking a day MUST show meeting details for that day

#### Scenario: Meeting preparation status

- GIVEN a meeting in 2 days with a meeting package
- WHEN the user views the upcoming meetings widget
- THEN each meeting MUST show preparation status (documents read/total)
- AND meetings where documents have not been read MUST show a reminder indicator

---

### Requirement: Decision Board (Kanban)

The dashboard MUST include an optional kanban-style decision board that groups decisions by their current state within their process template.

**Feature tier**: V1
**Evidence**: EspoCRM and Dimpact ZAC both provide kanban views for status-based entity management. BottleCRM uses Pipeline->Stage->Entity pattern with drag-drop (competitor analysis). 300 user stories match workflow/pipeline patterns (insight #32).

#### Scenario: View decisions as kanban board

- GIVEN the user selects the "Decision Board" view
- WHEN the board loads
- THEN decisions MUST be grouped into columns by their current state (e.g., Draft, Proposed, Debating, Voting, Adopted)
- AND each card MUST show decision title, body, and days in current state
- AND cards MUST be color-coded by urgency or body

#### Scenario: Filter board by governing body

- GIVEN the user is a member of 3 bodies
- WHEN they select a specific body filter
- THEN only decisions from that body MUST be shown
- AND the columns MUST reflect the process template states for that body

#### Scenario: Drag-and-drop state transitions (chair/secretary only)

- GIVEN a user with chair or secretary role
- WHEN they drag a decision card from "Debating" to "Voting"
- THEN the system MUST validate the transition against the process template guards
- AND if valid, the state transition MUST be executed with audit trail
- AND if invalid (e.g., quorum not met), an error MUST be displayed explaining why

---

### Requirement: My Work Section

The dashboard MUST include a "My Work" section showing the user's personal pending items across all governance activities.

**Feature tier**: MVP

#### Scenario: Show personal pending items

- GIVEN a user with various pending governance items
- WHEN they view the "My Work" section
- THEN the following MUST be shown in a unified list:
  - Pending votes (with deadline)
  - Action items assigned to them (with due date and status)
  - Documents requiring their review
  - Meetings requiring their preparation
- AND items MUST be sorted by urgency (deadline)
- AND each item MUST show the governing body context

#### Scenario: Mark items as complete

- GIVEN an action item in the "My Work" section
- WHEN the user clicks "Mark Complete"
- THEN the action item status MUST update to "completed"
- AND the item MUST move to a "Recently Completed" section (visible for 24 hours)
- AND the completion rate KPI MUST update

---

### Requirement: Organization-Wide Decision Overview

The dashboard MUST provide an organization-wide view of all decisions for users with administrative or oversight roles.

**Feature tier**: V1
**Evidence**: Council member story #179 needs dashboard of all adopted motions. Story #203 needs dashboard of executive commitments. Story #290 needs compliance dashboard.

#### Scenario: View all decisions across bodies

- GIVEN an administrator or compliance officer
- WHEN they view the organization-wide overview
- THEN all decisions across all bodies MUST be shown
- AND decisions MUST be filterable by body, status, date range, and type
- AND a summary bar MUST show total active, adopted this quarter, and overdue

#### Scenario: Monitor executive commitments (toezeggingen)

- GIVEN a council member viewing the oversight dashboard
- WHEN they filter by "commitments"
- THEN all open commitments from the executive MUST be listed with deadlines and status (user story #203)
- AND overdue commitments MUST be highlighted
- AND a compliance rate MUST be calculated

#### Scenario: View motion follow-up status

- GIVEN adopted motions from council meetings
- WHEN the user views the motion status dashboard
- THEN each adopted motion MUST show its current implementation status (user story #179)
- AND the responsible executive member MUST be shown
- AND a timeline of status updates MUST be available

---

### Requirement: Meeting Efficiency Analytics Widget

The dashboard MUST include an analytics widget showing key meeting efficiency metrics for the user's governance bodies.

**Feature tier**: V1
**Evidence**: $541B wasted globally (insight #1). 15 KPIs from Flowtrace (external source #323). 12 manager KPIs from Worklytics (external source #347).

#### Scenario: Display efficiency metrics

- GIVEN the user has meeting history data
- WHEN the analytics widget loads
- THEN the following metrics MUST be displayed:
  - Average meeting duration (trend over last 6 months)
  - Decision throughput (decisions per meeting, trend)
  - Action item completion rate (vs. 90% target)
  - Meeting cost trend (total and per-meeting)
- AND each metric MUST show a trend indicator (improving/declining/stable)

#### Scenario: Compare bodies

- GIVEN the user is a member of 3 bodies
- WHEN they view the comparative analytics
- THEN efficiency metrics MUST be shown side-by-side for each body
- AND the best-performing body MUST be highlighted as a benchmark

---

### Requirement: Nextcloud Dashboard Widget Integration

The system MUST register a Nextcloud Dashboard widget via `OCP\Dashboard\IWidget` so that Decidesk summary data appears on the Nextcloud main dashboard.

**Feature tier**: MVP

#### Scenario: View Decidesk widget on Nextcloud dashboard

- GIVEN a user with Decidesk access
- WHEN they view the Nextcloud main dashboard
- THEN a "Decidesk" widget MUST be available showing:
  - Pending votes count (with urgency indicator)
  - Next meeting (title, time, body)
  - Overdue action items count
- AND clicking the widget MUST navigate to the Decidesk dashboard

#### Scenario: Widget data refresh

- GIVEN the Decidesk widget is displayed on the Nextcloud dashboard
- WHEN the user receives a new pending vote
- THEN the widget MUST update without requiring a page refresh (if Nextcloud push is available)
- AND the count MUST increment and show the new vote's title

---

### Requirement: Quick Actions

The dashboard MUST provide quick action buttons for common governance tasks, accessible from the dashboard header.

**Feature tier**: MVP

#### Scenario: Create new meeting from dashboard

- GIVEN a user with secretary or chair role
- WHEN they click the "New Meeting" quick action
- THEN a meeting creation form MUST open with the user's default body pre-selected
- AND the form MUST include agenda template options

#### Scenario: Create new decision from dashboard

- GIVEN a user with permission to create decisions
- WHEN they click the "New Decision" quick action
- THEN a decision creation form MUST open
- AND the form MUST offer process template selection based on the selected body

#### Scenario: Start ad-hoc vote from dashboard

- GIVEN a chair viewing the dashboard
- WHEN they click "Start Vote"
- THEN a quick-vote creation form MUST open for simple yes/no decisions
- AND the form MUST auto-populate quorum requirements from the body's process template

## User Stories

### High-Priority Stories (from Intelligence DB)

1. **New board member accessing knowledge base** (DB #84, priority: high): As a new board member, I want to access all historical decisions, current action items, financial status, and governance documents so that I can quickly become effective in my role. *AC: Complete decision archive, current open items, latest financial status, key contacts.*

2. **Institutional investor managing proxy voting** (DB #5, priority: should): As an institutional investor, I want to manage proxy voting across all portfolio company AGMs from a single dashboard, so that I can efficiently exercise my voting rights at scale. *AC: All upcoming AGMs with deadlines, bulk voting, SRD II compliance.*

3. **CEO viewing meeting cost dashboard** (DB #330, priority: must): As a CEO/director, I want a real-time dashboard showing organizational meeting costs, trends, and cost per department. *AC: Total cost per period, department breakdown, trend lines, benchmarks.*

4. **Manager tracking personal meeting KPIs** (DB #331, priority: must): As a manager, I want a personal meeting scorecard showing my KPIs so I can optimize meeting behavior. *AC: Meetings/week, duration, decision rate, completion rate, focus time ratio.*

5. **Council member viewing motion dashboard** (DB #179, priority: should): As a raadslid, I want a dashboard showing all adopted motions with their current status, so I can hold the executive accountable.

6. **Council member monitoring executive commitments** (DB #203, priority: should): As a raadslid, I want a dashboard showing all open commitments from the executive with deadlines and status.

7. **Alderman reviewing participation results** (DB #255, priority: high): As an alderman, I want a dashboard showing all active and completed participation processes with key results so I can prioritize responses. *AC: Status, participation numbers, key themes, response deadline.*

8. **Financial controller monitoring budget utilization** (DB #101, priority: medium): As a financial controller, I want a dashboard of all approved budget decisions with actual spend tracking. *AC: Approved vs actual, variance alerts, drill-down.*

9. **Administrator publishing meeting decisions** (DB #76, priority: medium): As administrator, I want to publish key decisions from ALV and board meetings on the member portal so that all members stay informed.

10. **CFO viewing risk management dashboard** (DB #21, priority: should): As a CFO, I want a real-time risk dashboard showing enterprise risks, control effectiveness, and compliance status. *AC: Risk heat map, control testing status, trend analysis, drill-down.*

### Capability Stories (from external source clustering)

11. **KPI dashboard** (DB #1212): As an organizational leader, I want to use a KPI dashboard for meeting effectiveness measurement.

12. **Manager dashboard** (DB #1207): As an organizational leader, I want to use a manager dashboard for meeting effectiveness.

13. **Executive dashboard** (DB #1222): As an organizational leader, I want to use an executive dashboard for organizational meeting visibility.

14. **Analytics dashboard** (DB #1539): As an organizational leader, I want to use an analytics dashboard for meeting effectiveness measurement.

15. **Personal dashboard** (DB #1684): As an organizational leader, I want a personal dashboard for individual meeting performance.

## Acceptance Criteria

- Dashboard uses CnDashboardPage with 12-column grid layout
- Four KPI cards show active decisions, upcoming meetings, pending votes, and overdue actions
- KPI cards use color variants (warning/error) based on urgency thresholds
- Pending votes widget shows urgency indicators with countdown (24h red, 48h orange)
- Quick vote from dashboard is supported for simple yes/no votes
- Upcoming meetings widget shows meetings across all user's bodies with preparation status
- Calendar strip shows current week with meeting indicators
- Decision board (kanban) groups decisions by process template state
- Kanban drag-and-drop validates against process template guards before transitioning
- My Work section shows unified list of pending votes, action items, documents, and meetings
- Organization-wide decision overview available for admins/oversight roles
- Motion follow-up and executive commitment tracking dashboards available
- Meeting efficiency analytics widget shows duration trends, decision throughput, completion rate, cost
- Comparative analytics across bodies available
- Empty state shows setup guidance for new installations
- Nextcloud Dashboard widget registered via OCP\Dashboard\IWidget with pending votes, next meeting, overdue actions
- Quick action buttons in header for creating meetings, decisions, and ad-hoc votes
- All KPI values backed by benchmark data from Flowtrace/Worklytics research
- Dashboard data refreshes without full page reload

## External Sources

| # | Type | Title | Key Insight |
|---|------|-------|-------------|
| 323 | product | 15 Meeting KPIs (Flowtrace) | Decision rate, action item completion, attendance analytics |
| 347 | research | 12 Manager Scorecard KPIs (Worklytics) | Meeting hours/week, 1:1 frequency, focus time ratio, benchmarks |
| 349 | product | Meeting Analytics Dashboard Guide (Flowtrace) | Executive tiles, team investment view, meeting audit |
| 322 | product | Flowtrace Meeting Analytics | 2.2M meetings benchmarked, real-time cost, behavioral nudges |
| 319 | research | State of Meetings Report 2024 (Fellow) | 78% too many meetings, only 37% use agendas |
| 342 | research | Action Item Completion Rate (Count.co) | 44% never completed, 71% meetings fail objectives |
| 325 | research | $37B/Year in Unnecessary Meetings | $25K/employee/year, $100M/year large companies |
| 172 | analyst-report | Gartner Corporate Governance Software | Only 45% of boards deemed effective |
| 114 | product | BoardPro Decision Register | Automated decision capture, searchable by keyword/date |
| 97 | case-study | iBabs for Local Government | Quorum tracking, RSVP, voting, ISO/GDPR certified |
| 151 | comparison | Top Board Portal Software 2026 | Diligent, OnBoard, iDeals, Nasdaq, Govenda, Boardable |
| 442 | review | BoardEffect Reviews (G2) | 4.5/5, real-time sync, limited meeting minutes |
| 432 | review | Diligent Boards Reviews (Capterra) | Organized single location, reusable templates, admin not user-friendly |
