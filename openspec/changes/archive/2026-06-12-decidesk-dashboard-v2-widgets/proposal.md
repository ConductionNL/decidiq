---
kind: code
---

# Proposal: decidesk-dashboard-v2-widgets

## Summary

This change builds the Vue widget components that power the Decidesk v2 dashboard — eleven bespoke `CnDashboardPage`-compatible slot components (KPI cards, list widgets, a pipeline overview, a governance health chart, and an empty state) — together with their vitest unit tests, Playwright e2e coverage, and five-language i18n. (The governance health chart was initially planned as a built-in manifest `type: "chart"` widget, but the lib's chart dataSource was verified unable to assemble two live series — see design.md Decision 5 — so it is a custom component with live data rather than a declarative widget with fake static data.) The components are registered in `src/registry.js` under `kind: "widget"` entries but are **not yet wired** into `src/manifest.json`; the follow-up change `decidesk-dashboard-v2-layout` (kind: config, depends on this one) inserts the widgets array, layout grid, and data sources into the manifest's Dashboard page and fixes the three hardcoded Dutch stats-block titles.

## Motivation

The current Decidesk dashboard (three Dutch-titled stats-blocks, static manifest) provides no actionable governance intelligence. Board members need at-a-glance visibility into: which votes are pending their attention, what meetings are coming up, what action items they own, and how governance health is trending. The existing `openspec/specs/dashboard/spec.md` (status: idea) documented the intent but no code exists. Building the widget components first, in a clean isolated change, means the layout-wiring change can proceed independently and the component API is locked before the manifest references it.

**2-spec chain (ADR-032):**
1. `decidesk-dashboard-v2-widgets` (this change, kind: code) — Vue component files + registry + tests + i18n. No manifest.json edits.
2. `decidesk-dashboard-v2-layout` (kind: config, depends_on: [decidesk-dashboard-v2-widgets]) — inserts `widgets`, `layout`, and data sources into the Dashboard page in `src/manifest.json`; fixes hardcoded Dutch titles to English.

## Affected Projects

- [ ] Project: `decidesk` — eleven new Vue widget components in `src/views/dashboard/widgets/`, a shared `dashboardRefreshMixin.js`, and a `dashboardData.js` service; updates to `src/registry.js` to register widgets; vitest tests in `tests/unit/`; Playwright e2e in `tests/e2e/`; l10n strings in `l10n/`.

## Scope

### In Scope

- Build 11 Vue 2.7 widget components (see component list below)
- Shared `dashboardRefreshMixin.js` and `src/services/dashboardData.js` for OR object fetching
- Registry entries in `src/registry.js` (`kind: "widget"`, with the lib-required `defaultSize`/`minSize`/`maxSize`/`allowedSlots`/`propsSchema` metadata) for all 11 components
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
9. `ActiveDecisionsKpiWidget` — count of decisions with `outcome` null (not yet adopted/rejected; the Decision schema has no lifecycle field, so "active" = undecided, filtered client-side)
10. `GovernanceHealthWidget` — live two-series chart (quorumPercentage + actionItemCompletionRate from recent meetings' materialized fields) with a "Not enough data" state
11. `DashboardEmptyState` — welcome card shown when no governance body exists

### Out of Scope

- `src/manifest.json` edits — all manifest wiring (widgets array, layout, dataSources, English titles) belongs in `decidesk-dashboard-v2-layout`
- `OCP\Dashboard\IWidget` PHP registration for the Nextcloud main dashboard — deferred per the existing spec's Nextcloud Dashboard Widget Integration requirement (out of scope for this chain)
- Engagement/speaking-time analytics — deferred (separate roadmap item)
- New OpenRegister schemas — no schema changes; existing meeting/motion/decision/action-item/voting-round/vote/participant/minutes schemas are reused as-is

## Approach

Follow the pipelinq dashboard widget pattern exactly: Vue 2.7 SFCs in `src/views/dashboard/widgets/`, a shared `dashboardRefreshMixin.js`, and a `src/services/dashboardData.js` that calls `useObjectStore` from `@conduction/nextcloud-vue` against OpenRegister. KPI widgets use `CnStatsBlock` with conditional `variant`. List/chart widgets render OR objects directly. Per-user logic (pending votes, my action items) is client-side: resolve `getCurrentUser()` → match against participant.nextcloudUserId → filter collections in component computed properties; a user with no matching participant record sees pending votes = 0 (not a voting member). The governance health chart fetches up to 12 recent meetings and renders two live series (quorumPercentage, actionItemCompletionRate) via the lib's chart machinery (or vue-apexcharts directly if the lib does not export its chart component — verified at apply time). All 11 components are registered in `src/registry.js` under `kind: "widget"` (verified supported by the local nextcloud-vue lib decidesk builds against) so CnPageRenderer can resolve them by name from manifest `component` references.

## New Dependencies

None. No new npm packages are introduced.

## Impact

- `src/views/dashboard/widgets/` — 11 new `.vue` files + 1 mixin + 1 service
- `src/registry.js` — 11 new `widget()` entries
- `tests/unit/dashboard/` — new vitest test files for widget logic
- `tests/e2e/` — new Playwright specs for component-level dashboard scenarios
- `l10n/` — new string keys for all widget labels, empty states, and status descriptions

No PHP changes. No manifest.json changes. No schema changes.

## Cross-Project Dependencies

None. This change is self-contained within the decidesk app. The layout change (`decidesk-dashboard-v2-layout`) depends on this one, not the reverse.

## Risks

### Risk 1: Per-user vote resolution performance

**Severity:** Medium — **Mitigation:** The pending-votes set-difference requires fetching all voting-rounds (lifecycle=open) and all existing votes for the current user. With large governance bodies this could be O(n) fetches. Use OR's filter parameters to pre-scope on the server side where possible (`filter[lifecycle]=open`); the participant→votingRound→vote join is client-side only for the final deduplication. Cache the result for the dashboard refresh interval.

### Risk 2: registry `kind: "widget"` resolution — RESOLVED

**Severity:** None (verified 2026-06-12) — the local nextcloud-vue lib that decidesk builds against (via the `useLocalLib` webpack alias) supports `kind: "widget"` natively; entries must carry `defaultSize`/`minSize`/`maxSize`/`allowedSlots`/`propsSchema` metadata. Residual: building without the local lib falls back to npm beta.108 whose support is unverified (and ≤ beta.111 is broken regardless — a new lib beta is a known pending follow-up). See design.md Decision 2.

### Risk 3: Meeting materialized fields absent on older objects

**Severity:** Low — **Mitigation:** `quorumPercentage` and `actionItemCompletionRate` may be null/undefined on meetings created before the materialization rules. `GovernanceHealthWidget` guards against null values and renders a "Not enough data" state when fewer than two meetings carry the fields.

## Rollback Strategy

All changes are additive (new files + new registry entries). Rollback = revert the commit; the manifest does not reference these components until `decidesk-dashboard-v2-layout` lands, so partial rollback is safe. The layout change cannot ship without these components present.
