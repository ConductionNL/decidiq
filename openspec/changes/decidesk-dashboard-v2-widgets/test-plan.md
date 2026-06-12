# Test Plan: decidesk-dashboard-v2-widgets

Maps each spec requirement to concrete test cases. Component-renderable scenarios use Playwright (`/test-functional`); computed-logic scenarios use vitest (`/test-functional` unit tier); layout-dependent scenarios are deferred to `decidesk-dashboard-v2-layout`.

---

## REQ-001: Running Processes Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-001a | In-flight motions grouped by lifecycle | Functional (unit) | vitest | Mock dashboardData.getMotions; verify groupBy reduce output |
| TC-001b | Motions render in widget with click navigation | Functional (browser) | `/test-functional` | Mount widget in test harness with seed motions |
| TC-001c | Empty state when no in-flight motions | Functional (browser) | `/test-functional` | Empty mock → verify empty message |

---

## REQ-002: My Action Items Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-002a | Filter logic: only current user's open/in-progress items | Functional (unit) | vitest | Mock getCurrentUser + getActionItems |
| TC-002b | Sort by dueDate ascending | Functional (unit) | vitest | |
| TC-002c | Overdue indicator for past-due items | Functional (unit) | vitest | Boundary: dueDate = yesterday |
| TC-002d | Widget renders items list | Functional (browser) | `/test-functional` | Seed data with 2 items |
| TC-002e | Empty state | Functional (browser) | `/test-functional` | |

---

## REQ-003: Recent Decisions Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-003a | Render decisions with outcome and publication badges | Functional (browser) | `/test-functional` | Seed 3 decisions |
| TC-003b | Click navigates to decision detail | Functional (browser) | `/test-functional` | |
| TC-003c | Outcome badge mapping (approved/rejected/tabled/amended) | Functional (unit) | vitest | |

---

## REQ-004: Governance Health Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-004a | Chart renders with >= 2 data points | Functional (browser) | `/test-functional` | Seed 2 meetings with materialized fields |
| TC-004b | "Not enough data" state with < 2 data points | Functional (unit) | vitest | |
| TC-004c | null/undefined field guard | Functional (unit) | vitest | meeting.quorumPercentage = undefined |

---

## REQ-005: Dashboard Empty State

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-005a | DashboardEmptyState renders welcome message and action buttons | Functional (browser) | `/test-functional` | Route with empty governance-body store |
| TC-005b | "Set Up Body" button navigates to Settings | Functional (browser) | `/test-functional` | |
| TC-005c | Accessibility: WCAG AA on empty state | Accessibility | `/test-accessibility` | |

---

## REQ-006: Dashboard Refresh Behaviour

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-006a | dashboardRefreshMixin calls load() on mount | Functional (unit) | vitest | Spy on load() |
| TC-006b | dashboardRefreshMixin calls load() when token changes | Functional (unit) | vitest | Trigger token bump; assert load() called again |

---

## REQ-007: Widget i18n — English Source Keys

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-007a | No Dutch strings as i18n keys in any widget file | Functional (unit) | vitest or lint | Static check; can be grep-based |
| TC-007b | All new keys present in nl/de/fr/es/it l10n files | Functional (unit) | vitest | Compare key sets |

---

## REQ-008: Upcoming Meetings KPI Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-008a | Count of scheduled future meetings | Functional (unit) | vitest | |
| TC-008b | Widget renders CnStatsBlock with correct count | Functional (browser) | `/test-functional` | |

---

## REQ-009: Pending Votes KPI Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-009a | Set-difference: open rounds minus user's cast votes | Functional (unit) | vitest | Core logic test |
| TC-009b | variant="warning" when pending > 0 | Functional (unit) | vitest | |
| TC-009c | variant="default" when 0 pending | Functional (unit) | vitest | |
| TC-009d | Widget renders with correct count | Functional (browser) | `/test-functional` | Seed: 2 open rounds, 1 cast vote |

---

## REQ-010: Overdue Actions KPI Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-010a | dueDate < now AND taskStatus not in [completed, cancelled] | Functional (unit) | vitest | Boundary date test |
| TC-010b | variant="error" when overdue > 0 | Functional (unit) | vitest | |
| TC-010c | variant="default" when 0 overdue | Functional (unit) | vitest | |

---

## REQ-011: Upcoming Meetings List Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-011a | Sorted by scheduledDate ascending | Functional (unit) | vitest | |
| TC-011b | 24h urgency flag: meeting < 24h highlighted | Functional (unit) | vitest | Boundary: 23h59m vs 24h01m |
| TC-011c | Meeting entry shows title, date, body name, agenda count | Functional (browser) | `/test-functional` | Seed meeting with agenda items |
| TC-011d | Click navigates to meeting detail | Functional (browser) | `/test-functional` | |

---

## REQ-012: Pending Votes List Widget

| TC | Scenario | Type | Command | Notes |
|----|----------|------|---------|-------|
| TC-012a | Urgency indicator for deadline < 24h | Functional (unit) | vitest | |
| TC-012b | Empty state renders "No pending votes" + checkmark | Functional (browser) | `/test-functional` | |
| TC-012c | Entry shows decision title + countdown | Functional (browser) | `/test-functional` | Seed with 1 open round |
| TC-012d | Click navigates to voting interface | Functional (browser) | `/test-functional` | |

---

## Coverage Summary

| Requirement | Unit tests | Browser tests | Accessibility | Status |
|-------------|-----------|---------------|---------------|--------|
| REQ-001 Running Processes | TC-001a | TC-001b, TC-001c | — | Covered |
| REQ-002 My Action Items | TC-002a–c | TC-002d–e | — | Covered |
| REQ-003 Recent Decisions | TC-003c | TC-003a–b | — | Covered |
| REQ-004 Governance Health | TC-004b–c | TC-004a | — | Covered |
| REQ-005 Empty State | — | TC-005a–b | TC-005c | Covered |
| REQ-006 Refresh Behaviour | TC-006a–b | — | — | Covered |
| REQ-007 i18n Keys | TC-007a–b | — | — | Covered |
| REQ-008 Upcoming Meetings KPI | TC-008a | TC-008b | — | Covered |
| REQ-009 Pending Votes KPI | TC-009a–c | TC-009d | — | Covered |
| REQ-010 Overdue Actions KPI | TC-010a–c | — | — | Covered |
| REQ-011 Upcoming Meetings List | TC-011a–b | TC-011c–d | — | Covered |
| REQ-012 Pending Votes List | TC-012a | TC-012b–d | — | Covered |

**Deliberately deferred (to decidesk-dashboard-v2-layout):**
- Full dashboard grid layout rendering (REQ from existing spec: "Default grid layout on first load")
- KPI row four-card grid positioning
- Dashboard page load with all widgets wired via manifest

After implementation, promote TC-009a (set-difference), TC-010a (overdue boundary), TC-011b (24h urgency), TC-012a (urgency indicator) as reusable regression test scenarios via `/test-scenario-create` — these encode core governance-domain logic that must never regress.
