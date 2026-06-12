---
kind: code
---

# Proposal: decidesk-dashboard-v2-widgets

## Summary

This change builds the Vue widget components that power the Decidesk v2 dashboard — ten bespoke `CnDashboardPage`-compatible slot components (KPI cards, list widgets, a pipeline overview, a governance health chart, and an empty state) — together with their vitest unit tests, Playwright e2e coverage, and five-language i18n. The components are registered in `src/registry.js` under `kind: "widget"` entries but are **not yet wired** into `src/manifest.json`; the follow-up change `decidesk-dashboard-v2-layout` (kind: config, depends on this one) inserts the widgets array, layout grid, and data sources into the manifest's Dashboard page and fixes the three hardcoded Dutch stats-block titles.

## Motivation

The current Decidesk dashboard (three Dutch-titled stats-blocks, static manifest) provides no actionable governance intelligence. Board members need at-a-glance visibility into: which votes are pending their attention, what meetings are coming up, what action items they own, and how governance health is trending. The existing `openspec/specs/dashboard/spec.md` (status: idea) documented the intent but no code exists. Building the widget components first, in a clean isolated change, means the layout-wiring change can proceed independently and the component API is locked before the manifest references it.

**2-spec chain (ADR-032):**
1. `decidesk-dashboard-v2-widgets` (this change, kind: code) — Vue component files + registry + tests + i18n. No manifest.json edits.
2. `decidesk-dashboard-v2-layout` (kind: config, depends_on: [decidesk-dashboard-v2-widgets]) — inserts `widgets`, `layout`, and data sources into the Dashboard page in `src/manifest.json`; fixes hardcoded Dutch titles to English.

## Affected Projects

- [ ] Project: `decidesk` — ten new Vue widget components in `src/views/dashboard/widgets/`, a shared `dashboardRefreshMixin.js`, and a `dashboardData.js` service; updates to `src/registry.js` to register widgets; vitest tests in `tests/unit/`; Playwright e2e in `tests/e2e/`; l10n strings in `l10n/`.

## Scope

### In Scope

- Build 10 Vue 2.7 widget components (see component list below)
- Shared `dashboardRefreshMixin.js` and `src/services/dashboardData.js` for OR object fetching
- Registry entries in `src/registry.js` (`kind: "widget"`) for all 10 components
- i18n: all strings via `t('decidesk', '...')` with English source keys; nl/de/fr/es/it translation stubs following l10n tooling conventions
- vitest unit tests for computed logic: pending-votes set-difference, overdue calculation, urgency thresholds (<24h), lifecycle grouping
- Playwright e2e: component-level coverage where renderable in isolation; scenarios requiring full dashboard wiring annotated with `@e2e exclude full-dashboard-only — covered by decidesk-dashboard-v2-layout` on their own line

**Components (exact names — layout change depends on these):**
1. `PendingVotesKpiWidget` — count of open voting-rounds awaiting the current user's vote
2. `UpcomingMeetingsKpiWidget` — count of meetings lifecycle=scheduled with scheduledDate >= now
3. `OverdueActionsKpiWidget` — count of action items past dueDate and not completed/cancelled
4. `UpcomingMeetingsListWidget` — list of upcoming meetings sorted by scheduledDate
5. `PendingVotesListWidget` — list of decisions/voting-rounds awaiting the user's vote
6. `RunningProcessesWidget` — motions in flight grouped by lifecycle stage
7. `MyActionItemsWidget` — action items where assignee = current user, status open/in-progress
8. `RecentDecisionsWidget` — latest N decisions with outcome and publication badges
9. `GovernanceHealthWidget` — chart of quorumPercentage and actionItemCompletionRate from meetings
10. `DashboardEmptyState` — welcome card shown when no governance body exists

### Out of Scope

- `src/manifest.json` edits — all manifest wiring (widgets array, layout, dataSources, English titles) belongs in `decidesk-dashboard-v2-layout`
- `OCP\Dashboard\IWidget` PHP registration for the Nextcloud main dashboard — deferred per the existing spec's Nextcloud Dashboard Widget Integration requirement (out of scope for this chain)
- Engagement/speaking-time analytics — deferred (separate roadmap item)
- New OpenRegister schemas — no schema changes; existing meeting/motion/decision/action-item/voting-round/vote/participant/minutes schemas are reused as-is

## Approach

Follow the pipelinq dashboard widget pattern exactly: Vue 2.7 SFCs in `src/views/dashboard/widgets/`, a shared `dashboardRefreshMixin.js`, and a `src/services/dashboardData.js` that calls `useObjectStore` from `@conduction/nextcloud-vue` against OpenRegister. KPI widgets use `CnStatsBlock` with conditional `variant`. List/chart widgets render OR objects directly. Per-user logic (pending votes, my action items) is client-side: resolve `getCurrentUser()` → match against participant.nextcloudUserId → filter collections in component computed properties. Meeting aggregation fields (`quorumPercentage`, `actionItemCompletionRate`) are read from OR meeting objects where these materialized fields already exist in `lib/Settings/decidesk_register.json`. Chart rendering uses the ApexCharts integration pattern the CnDashboardPage chart widget type supports. All 10 components are registered in `src/registry.js` under `kind: "widget"` so CnPageRenderer can resolve them by name from manifest `component` references.

## New Dependencies

None. ApexCharts is already a transitive dependency of `@conduction/nextcloud-vue`. No new npm packages are introduced.

## Impact

- `src/views/dashboard/widgets/` — 10 new `.vue` files + 1 mixin + 1 service
- `src/registry.js` — 10 new `widget()` entries
- `tests/unit/dashboard/` — new vitest test files for widget logic
- `tests/e2e/` — new Playwright specs for component-level dashboard scenarios
- `l10n/` — new string keys for all widget labels, empty states, and status descriptions

No PHP changes. No manifest.json changes. No schema changes.

## Cross-Project Dependencies

None. This change is self-contained within the decidesk app. The layout change (`decidesk-dashboard-v2-layout`) depends on this one, not the reverse.

## Risks

### Risk 1: Per-user vote resolution performance

**Severity:** Medium — **Mitigation:** The pending-votes set-difference requires fetching all voting-rounds (lifecycle=open) and all existing votes for the current user. With large governance bodies this could be O(n) fetches. Use OR's filter parameters to pre-scope on the server side where possible (`filter[lifecycle]=open`); the participant→votingRound→vote join is client-side only for the final deduplication. Cache the result for the dashboard refresh interval.

### Risk 2: Meeting materialized fields absent on older objects

**Severity:** Low — **Mitigation:** `quorumPercentage` and `actionItemCompletionRate` are materialized fields added in a recent schema version. The GovernanceHealthWidget must guard against `undefined`/`null` values and render a "not enough data" state gracefully rather than crashing.

### Risk 3: registry `kind: "widget"` resolution in current lib version

**Severity:** Low — **Mitigation:** Confirm `CnPageRenderer` resolves `kind: "widget"` registry entries; if the lib version in use only supports `kind: "page"`, fall back to `kind: "page"` wrapping (the layout change can reference components directly). Document the decision in design.md.

## Rollback Strategy

All changes are additive (new files + new registry entries). Rollback = revert the commit; the manifest does not reference these components until `decidesk-dashboard-v2-layout` lands, so partial rollback is safe. The layout change cannot ship without these components present.
