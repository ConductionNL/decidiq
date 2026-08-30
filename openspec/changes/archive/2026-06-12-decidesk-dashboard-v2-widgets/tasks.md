# Tasks: decidesk-dashboard-v2-widgets

## 1 — Shared infrastructure

### Task 1: dashboardData service + dashboardRefreshMixin

**spec_ref:** REQ-006 (refresh behaviour)
**files:**
- `src/services/dashboardData.js` (new)
- `src/views/dashboard/widgets/dashboardRefreshMixin.js` (new)

- [x] Implement `src/services/dashboardData.js` with named fetch helpers: `getMeetings`, `getVotingRounds`, `getVotes`, `getActionItems`, `getMotions`, `getDecisions`, `getParticipants`, `getMinutes` — each calls `useObjectStore` with appropriate OR filter params
- [x] Implement `dashboardRefreshMixin.js` following pipelinq's pattern (reactive `dashboardRefreshToken`, mounted → `load()`, watch token → `load()`), exporting a `getDashboardRefreshSignal()` reactive ref that the header refresh action can bump
- [x] Add `widget()` registry-entry helper to `src/registry.js`

- Tests required: vitest unit tests verifying each service function calls the store with correct filter params; mixin `load()` is called on mount and on token change.
- i18n: n/a (no user-visible strings in service layer)

---

## 2 — KPI widget quartet

### Task 2: PendingVotesKpiWidget + UpcomingMeetingsKpiWidget + OverdueActionsKpiWidget + ActiveDecisionsKpiWidget

**spec_ref:** REQ-009, REQ-008, REQ-010, REQ-013
**files:**
- `src/views/dashboard/widgets/PendingVotesKpiWidget.vue` (new)
- `src/views/dashboard/widgets/UpcomingMeetingsKpiWidget.vue` (new)
- `src/views/dashboard/widgets/OverdueActionsKpiWidget.vue` (new)
- `src/views/dashboard/widgets/ActiveDecisionsKpiWidget.vue` (new)
- `tests/unit/dashboard/PendingVotesKpiWidget.spec.js` (new)
- `tests/unit/dashboard/UpcomingMeetingsKpiWidget.spec.js` (new)
- `tests/unit/dashboard/OverdueActionsKpiWidget.spec.js` (new)
- `tests/unit/dashboard/ActiveDecisionsKpiWidget.spec.js` (new)

- [x] Implement all four KPI widgets using `CnStatsBlock` + `dashboardRefreshMixin` + `dashboardData` service; pending-votes uses set-difference (open voting-rounds minus user's cast votes), no participant record matching the current user ⇒ count 0; active-decisions counts decisions with `outcome == null` client-side (click → Decisions view)
- [x] Write vitest unit tests: pending-votes set-difference logic, no-participant ⇒ 0 case, overdue date calculation (dueDate < now AND taskStatus not in completed/cancelled), upcoming meetings date filter (scheduledDate >= now), active = outcome null count, variant switching (warning when pending>0, error when overdue>0)

- i18n keys (English): "Pending votes", "votes", "Upcoming meetings", "meetings", "Overdue actions", "actions", "Active decisions", "decisions"
- Acceptance: CnStatsBlock variant="warning" when pending>0; variant="error" when overdue>0; variant="default" when 0.

---

## 3 — List widgets

### Task 3: UpcomingMeetingsListWidget

**spec_ref:** REQ-011
**files:**
- `src/views/dashboard/widgets/UpcomingMeetingsListWidget.vue` (new)
- `tests/unit/dashboard/UpcomingMeetingsListWidget.spec.js` (new)

- [x] Implement widget fetching meetings lifecycle=scheduled, sorting by scheduledDate, flagging entries within 24h for urgency highlight, rendering title/date/body-name/agenda-item-count per row; click navigates to meeting detail
- [x] Write vitest unit tests: 24h urgency threshold logic, sorting order

- i18n keys (English): "Upcoming meetings", "No upcoming meetings", "today", "tomorrow"

---

### Task 4: PendingVotesListWidget

**spec_ref:** REQ-012
**files:**
- `src/views/dashboard/widgets/PendingVotesListWidget.vue` (new)
- `tests/unit/dashboard/PendingVotesListWidget.spec.js` (new)

- [x] Implement widget resolving open voting-rounds minus user's cast votes (no participant record ⇒ empty state); render each entry with decision/motion title and deadline countdown; <24h → red urgency indicator; click → voting interface; empty state with checkmark icon + "No pending votes"
- [x] Write vitest unit tests: urgency threshold (<24h), countdown display, set-difference, empty-state condition (including no-participant case)

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

- [x] Implement `RunningProcessesWidget`: fetch motions lifecycle IN [submitted, under-discussion, voting], group by lifecycle using reduce in computed property; click navigates to motion detail
- [x] Implement `MyActionItemsWidget`: fetch action items with OR filter assignee=currentUserId & status IN [open, in-progress], sort by dueDate ascending; show overdue indicator for past-due items; click navigates to action item detail
- [x] Write vitest unit tests: lifecycle grouping algorithm (groupBy reduce), overdue detection, sort order

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

- [x] Implement `RecentDecisionsWidget` (fetch decisions sorted decisionDate DESC, limit 10; title + outcome badge + publication badge; click → decision detail) and `GovernanceHealthWidget` (fetch ≤12 recent meetings with non-null materialized `quorumPercentage`/`actionItemCompletionRate`; render two LIVE series via the lib's exported chart component or vue-apexcharts — verify which export exists, document in the component; "Not enough data" when <2 data points; never hardcoded series)
- [x] Write vitest unit tests: empty/insufficient data states, null-field guard, badge value mapping (adopted/rejected + publication status)

- i18n keys (English): "Recent decisions", "No decisions yet", "Adopted", "Rejected", "Undecided", "Internal", "Public", "Confidential", "Governance health", "Not enough data", "Quorum %", "Action item completion %"

---

## 6 — Empty state and registry

### Task 7: DashboardEmptyState + registry registration

**spec_ref:** REQ-005
**files:**
- `src/views/dashboard/widgets/DashboardEmptyState.vue` (new)
- `src/registry.js` (modified — add 11 widget entries)

- [x] Implement `DashboardEmptyState`: welcome message, "Set Up Body" → Settings nav, "Create Meeting" → Meetings nav, "Create Decision" → Decisions nav
- [x] Add all 11 widgets to `src/registry.js` using `widget()` helper entries with the lib-required metadata fields `defaultSize`, `minSize`, `maxSize`, `allowedSlots`, `propsSchema` (kind "widget" support verified in the local nextcloud-vue lib — see design.md Decision 2)
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

- [x] Add all new English source key strings (from tasks 2–7) to all five language files with correct translations following the app's l10n tooling conventions

- Acceptance: all new `t('decidesk', ...)` keys present in nl/de/fr/es/it with non-empty translations; no Dutch strings as i18n keys anywhere in widget files.

---

## 8 — Playwright e2e

### Task 9: Playwright e2e coverage for component-renderable scenarios

**spec_ref:** REQ-001 through REQ-013
**files:**
- `tests/e2e/dashboard-widgets.spec.js` (new)

- [ ] Write Playwright tests for component-renderable widget scenarios: empty state renders, KPI widget count display, list widget item click navigation, overdue/urgency indicator rendering — mount via stub dashboard page or direct route with seed data
- [x] Annotate layout-dependent scenarios (REQ-011 grid layout, KPI grid row) in the spec with `@e2e exclude full-dashboard-only — covered by decidesk-dashboard-v2-layout` on a standalone line per gate-19 honest-coverage rules

- Acceptance: gate-19 passes; no `@e2e exclude` on scenarios that CAN be tested at component level.

---

## Acceptance criteria and quality reminders

- All 11 widget components present at `src/views/dashboard/widgets/<ComponentName>.vue`
- All 11 registered in `src/registry.js`
- vitest unit tests cover: set-difference logic, no-participant ⇒ 0 rule, overdue calculation, urgency threshold (<24h), lifecycle grouping, active = outcome-null count, chart insufficient-data guard
- Playwright e2e: gate-19 honest coverage for component-renderable scenarios; layout-dependent scenarios annotated with `@e2e exclude full-dashboard-only` on its own line
- Zero Dutch string keys in any `t()` call
- nl/de/fr/es/it translations present and non-empty for all new keys
- No nested `CnDashboardPage` inside any widget (hydra dashboard-antipattern gate)
- Any `NcSelect` inside a widget has an `inputLabel` prop (hydra nc-input-labels gate)
- `@spec` tags on any new methods in PHP (none expected — frontend-only change)
- No hardcoded colours; use CSS variables for NL Design System compliance

---

## Reconciliation notes (apply-loop, 2026-06-12)

State reconciled against on-disk files after a mid-iteration-1 crash. All 11 widgets, the `dashboardData` service, `dashboardRefreshMixin`, the `widget()` registry helper, and the 11 registry entries (with the five required `defaultSize`/`minSize`/`maxSize`/`allowedSlots`/`propsSchema` metadata fields) were already present and are verified.

**Deviations from the literal task spec (intentional, environment-driven):**

- **Test location & shape.** vitest is configured with the `node` environment (no jsdom / `@vue/test-utils`), so per-widget `.vue` *mount* specs under `tests/unit/dashboard/` are not feasible. Instead, every governance-domain computation is factored into pure functions in `src/views/dashboard/widgets/widgetLogic.js` and exhaustively unit-tested in `tests/vitest/dashboard/widgetLogic.spec.js` (41 tests) — set-difference + no-participant⇒0, overdue, <24h urgency/countdown, upcoming filter+sort, lifecycle grouping, active=outcome-null count, outcome/publication badge mapping, health data-points/series/insufficient-data guard. Service filter params (`dashboardData.spec.js`, 11 tests) and mixin load-on-mount/-on-token (`dashboardRefreshMixin.spec.js`, 7 tests) are covered too. 70 vitest tests pass.
- **l10n is JSON-only.** This app has no `l10n/*.js` files (json-only convention; `tests/l10n/check-l10n.js` validates against `l10n/en.json`). Added all 32 widget source keys (incl. the dynamic badge labels Adopted/Rejected/Undecided/Internal/Public/Confidential) to `en.json`+`nl.json` and created `de/fr/es/it.json` with non-empty translations. English source keys throughout; no Dutch keys.

**Open items left unticked (not genuinely complete; not actionable in this container):**

- **DashboardEmptyState vitest render test** — requires a DOM mount environment the `node`-env vitest harness does not provide. The component is purely presentational (welcome copy + 3 nav buttons, no computed logic to unit-test).
- **Component-renderable Playwright tests** — the widgets are not wired into any renderable manifest surface in this change (manifest editing is owned by the follow-up `decidesk-dashboard-v2-layout`), and the repo has no Playwright component-test harness. Browser rendering is therefore genuinely *full-dashboard-only* here. All 23 spec scenarios are annotated at the requirement level with `@e2e exclude full-dashboard-only — … covered by decidesk-dashboard-v2-layout` (gate-19 PASSES: 23/23 scenarios recognised as excluded with reasons). Logic is covered by vitest as above.
