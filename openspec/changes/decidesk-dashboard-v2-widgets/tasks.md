# Tasks: decidesk-dashboard-v2-widgets

## 1 — Shared infrastructure

### Task 1: dashboardData service + dashboardRefreshMixin

**spec_ref:** REQ-006 (refresh behaviour)
**files:**
- `src/services/dashboardData.js` (new)
- `src/views/dashboard/widgets/dashboardRefreshMixin.js` (new)

- [ ] Implement `src/services/dashboardData.js` with named fetch helpers: `getMeetings`, `getVotingRounds`, `getVotes`, `getActionItems`, `getMotions`, `getDecisions`, `getParticipants`, `getMinutes` — each calls `useObjectStore` with appropriate OR filter params
- [ ] Implement `dashboardRefreshMixin.js` following pipelinq's pattern (reactive `dashboardRefreshToken`, mounted → `load()`, watch token → `load()`)
- [ ] Export a `getDashboardRefreshSignal()` reactive ref that the header refresh action can bump
- [ ] Add `widget()` registry-entry helper to `src/registry.js`

- Tests required: vitest unit tests verifying each service function calls the store with correct filter params; mixin `load()` is called on mount and on token change.
- i18n: n/a (no user-visible strings in service layer)

---

## 2 — KPI widget trio

### Task 2: PendingVotesKpiWidget + UpcomingMeetingsKpiWidget + OverdueActionsKpiWidget

**spec_ref:** REQ-009, REQ-008, REQ-010
**files:**
- `src/views/dashboard/widgets/PendingVotesKpiWidget.vue` (new)
- `src/views/dashboard/widgets/UpcomingMeetingsKpiWidget.vue` (new)
- `src/views/dashboard/widgets/OverdueActionsKpiWidget.vue` (new)
- `tests/unit/dashboard/PendingVotesKpiWidget.spec.js` (new)
- `tests/unit/dashboard/UpcomingMeetingsKpiWidget.spec.js` (new)
- `tests/unit/dashboard/OverdueActionsKpiWidget.spec.js` (new)

- [ ] Implement all three KPI widgets using `CnStatsBlock` + `dashboardRefreshMixin` + `dashboardData` service; pending-votes uses set-difference (open voting-rounds minus user's cast votes)
- [ ] Write vitest unit tests: pending-votes set-difference logic, overdue date calculation (dueDate < now AND taskStatus not in completed/cancelled), upcoming meetings date filter (scheduledDate >= now), variant switching (warning when pending>0, error when overdue>0)

- i18n keys (English): "Pending votes", "votes", "Upcoming meetings", "meetings", "Overdue actions", "actions"
- Acceptance: CnStatsBlock variant="warning" when pending>0; variant="error" when overdue>0; variant="default" when 0.

---

## 3 — List widgets

### Task 3: UpcomingMeetingsListWidget

**spec_ref:** REQ-011
**files:**
- `src/views/dashboard/widgets/UpcomingMeetingsListWidget.vue` (new)
- `tests/unit/dashboard/UpcomingMeetingsListWidget.spec.js` (new)

- [ ] Implement widget fetching meetings lifecycle=scheduled, sorting by scheduledDate, flagging entries within 24h for urgency highlight, rendering title/date/body-name/agenda-item-count per row; click navigates to meeting detail
- [ ] Write vitest unit tests: 24h urgency threshold logic, sorting order

- i18n keys (English): "Upcoming meetings", "No upcoming meetings", "today", "tomorrow"

---

### Task 4: PendingVotesListWidget

**spec_ref:** REQ-012
**files:**
- `src/views/dashboard/widgets/PendingVotesListWidget.vue` (new)
- `tests/unit/dashboard/PendingVotesListWidget.spec.js` (new)

- [ ] Implement widget resolving open voting-rounds minus user's cast votes; render each entry with decision/motion title and deadline countdown; <24h → red urgency indicator; click → voting interface; empty state with checkmark icon + "No pending votes"
- [ ] Write vitest unit tests: urgency threshold (<24h), countdown display, set-difference, empty-state condition

- i18n keys (English): "Pending votes", "No pending votes", "Vote now", "Less than 24 hours remaining", "Urgent"

---

## 4 — Process and personal widgets

### Task 5: RunningProcessesWidget + MyActionItemsWidget

**spec_ref:** REQ-001, REQ-002
**files:**
- `src/views/dashboard/widgets/RunningProcessesWidget.vue` (new)
- `src/views/dashboard/widgets/MyActionItemsWidget.vue` (new)
- `tests/unit/dashboard/RunningProcessesWidget.spec.js` (new)
- `tests/unit/dashboard/MyActionItemsWidget.spec.js` (new)

- [ ] Implement `RunningProcessesWidget`: fetch motions lifecycle IN [submitted, under-discussion, voting], group by lifecycle using reduce in computed property; click navigates to motion detail
- [ ] Implement `MyActionItemsWidget`: fetch action items with OR filter assignee=currentUserId & status IN [open, in-progress], sort by dueDate ascending; show overdue indicator for past-due items; click navigates to action item detail
- [ ] Write vitest unit tests: lifecycle grouping algorithm (groupBy reduce), overdue detection, sort order

- i18n keys (English): "Running processes", "No active motions", "Submitted", "Under discussion", "Voting", "My action items", "No action items assigned to you", "Overdue"

---

## 5 — Decisions and health chart widgets

### Task 6: RecentDecisionsWidget + GovernanceHealthWidget

**spec_ref:** REQ-003, REQ-004
**files:**
- `src/views/dashboard/widgets/RecentDecisionsWidget.vue` (new)
- `src/views/dashboard/widgets/GovernanceHealthWidget.vue` (new)
- `tests/unit/dashboard/RecentDecisionsWidget.spec.js` (new)
- `tests/unit/dashboard/GovernanceHealthWidget.spec.js` (new)

- [ ] Implement `RecentDecisionsWidget`: fetch decisions sorted decisionDate DESC, limit 10; render title + outcome badge + publication badge; click navigates to decision detail
- [ ] Implement `GovernanceHealthWidget`: fetch up to 12 recent meetings with materialized `quorumPercentage`/`actionItemCompletionRate`; render ApexCharts mixed chart; "Not enough data" state when <2 data points
- [ ] Write vitest unit tests: empty/insufficient data states, badge value mapping (approved/rejected/tabled/amended)

- i18n keys (English): "Recent decisions", "No decisions yet", "Approved", "Rejected", "Tabled", "Amended", "Governance health", "Not enough data", "Quorum %", "Action item completion %"

---

## 6 — Empty state and registry

### Task 7: DashboardEmptyState + registry registration

**spec_ref:** REQ-005
**files:**
- `src/views/dashboard/widgets/DashboardEmptyState.vue` (new)
- `src/registry.js` (modified — add 10 widget entries)

- [ ] Implement `DashboardEmptyState`: welcome message, "Set Up Body" → Settings nav, "Create Meeting" → Meetings nav, "Create Decision" → Decisions nav
- [ ] Add all 10 widgets to `src/registry.js` using `widget()` helper entries; if lib does not resolve `kind: "widget"`, use `page()` with a code comment documenting the fallback
- [ ] Write vitest unit test: DashboardEmptyState renders correct message and action buttons

- i18n keys (English): "Welcome to Decidesk! Get started by setting up your first governing body in Settings.", "Set Up Body", "Create Meeting", "Create Decision"

---

## 7 — i18n translations

### Task 8: l10n string stubs for nl/de/fr/es/it

**spec_ref:** REQ-007
**files:**
- `l10n/nl.js`, `l10n/nl.json` (modified — add new keys)
- `l10n/de.js`, `l10n/de.json`
- `l10n/fr.js`, `l10n/fr.json`
- `l10n/es.js`, `l10n/es.json`
- `l10n/it.js`, `l10n/it.json`

- [ ] Add all new English source key strings (from tasks 2–7) to all five language files with correct translations following the app's l10n tooling conventions

- Acceptance: all new `t('decidesk', ...)` keys present in nl/de/fr/es/it with non-empty translations; no Dutch strings as i18n keys anywhere in widget files.

---

## 8 — Playwright e2e

### Task 9: Playwright e2e coverage for component-renderable scenarios

**spec_ref:** REQ-001 through REQ-012
**files:**
- `tests/e2e/dashboard-widgets.spec.js` (new)

- [ ] Write Playwright tests for component-renderable widget scenarios: empty state renders, KPI widget count display, list widget item click navigation, overdue/urgency indicator rendering — mount via stub dashboard page or direct route with seed data
- [ ] Annotate layout-dependent scenarios (REQ-011 grid layout, KPI grid row) in the spec with `@e2e exclude full-dashboard-only — covered by decidesk-dashboard-v2-layout` on a standalone line per gate-19 honest-coverage rules

- Acceptance: gate-19 passes; no `@e2e exclude` on scenarios that CAN be tested at component level.

---

## Acceptance criteria and quality reminders

- All 10 widget components present at `src/views/dashboard/widgets/<ComponentName>.vue`
- All 10 registered in `src/registry.js`
- vitest unit tests cover: set-difference logic, overdue calculation, urgency threshold (<24h), lifecycle grouping, insufficient-data guard
- Playwright e2e: gate-19 honest coverage for component-renderable scenarios; layout-dependent scenarios annotated with `@e2e exclude full-dashboard-only` on its own line
- Zero Dutch string keys in any `t()` call
- nl/de/fr/es/it translations present and non-empty for all new keys
- No nested `CnDashboardPage` inside any widget (hydra dashboard-antipattern gate)
- Any `NcSelect` inside a widget has an `inputLabel` prop (hydra nc-input-labels gate)
- `@spec` tags on any new methods in PHP (none expected — frontend-only change)
- No hardcoded colours; use CSS variables for NL Design System compliance
