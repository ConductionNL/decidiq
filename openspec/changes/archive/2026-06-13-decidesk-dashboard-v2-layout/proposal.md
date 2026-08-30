---
kind: config
depends_on:
  - decidesk-dashboard-v2-widgets
---

# Proposal: decidesk-dashboard-v2-layout

## Summary

This change rewires the Dashboard page in `src/manifest.json` to the full v2 layout. It replaces the three hardcoded Dutch stats-blocks with a five-row governance intelligence grid: four KPI cards (Row 1), two list widgets (Row 2), two process/personal widgets (Row 3), a full-width decisions widget (Row 4), and a minutes stats-block plus a governance health widget (Row 5). This is the second and final link of the 2-spec ADR-032 chain — the first link (`decidesk-dashboard-v2-widgets`) builds the components; this change wires them into the manifest and adds the `DashboardEmptyState` empty-state slot.

**2-spec chain (ADR-032):**
1. `decidesk-dashboard-v2-widgets` (kind: code, COMPLETE) — eleven Vue widget components (including `ActiveDecisionsKpiWidget` and `GovernanceHealthWidget`), registry entries, vitest tests, i18n.
2. `decidesk-dashboard-v2-layout` (this change, kind: config) — inserts `widgets`, `layout`, `slots`, and data sources into the Dashboard page in `src/manifest.json`; fixes hardcoded Dutch titles to English.

## Motivation

The eleven widget components built by `decidesk-dashboard-v2-widgets` exist in the registry but are not yet surfaced to users — the Dashboard page in `src/manifest.json` still shows three Dutch stats-blocks. This change is the mechanical wiring step that makes the v2 governance intelligence dashboard visible. Because it is declarative-only (manifest JSON edits), it carries zero PHP risk and a minimal review surface.

## Affected Projects

- [ ] Project: `decidesk` — `src/manifest.json` (Dashboard page rewrite); `tests/e2e/dashboard-layout.spec.js` (full-dashboard Playwright e2e); `openspec/specs/dashboard/spec.md` (MODIFIED Dashboard Layout + KPI Cards requirements).

## Scope

### In Scope

- Rewrite the `"id": "Dashboard"` page entry in `src/manifest.json`:
  - Replace `widgets`, `layout` arrays with the v2 five-row grid
  - Add `slots` map (pipelinq pattern: `"widget-<id>": "<ComponentName>"`) — 11 entries covering all custom widgets
  - `active-decisions` wired as `type: "custom"` + slot `widget-active-decisions` → `ActiveDecisionsKpiWidget` (built in the widgets change; counts `outcome == null` client-side)
  - `governance-health` wired as `type: "custom"` + slot `widget-governance-health` → `GovernanceHealthWidget` (built in the widgets change; live two-series chart)
  - `minutes-in-review` retained as the sole remaining `type: "stats-block"` with English title "Minutes awaiting approval"
  - `DashboardEmptyState` declared in `widgets[]` + `slots` but not `layout[]` (conditional glue in app shell — see design.md mixed-spec rationale)
  - All manifest widget titles in English
- Full-dashboard Playwright e2e (scenarios deferred from the widgets change with `@e2e exclude full-dashboard-only`)
- Spec delta: MODIFIED Dashboard Layout + KPI Cards requirements

### Out of Scope

- Vue component files — all components ship in `decidesk-dashboard-v2-widgets`
- `OCP\Dashboard\IWidget` PHP registration — deferred per existing spec
- Engagement / speaking-time analytics
- New OpenRegister schema changes — the governance health chart uses existing materialized fields

## Approach

Edit `src/manifest.json` only. The manifest's `pages[].config.widgets[]` array defines the widget metadata (type, title, dataSource); `pages[].config.layout[]` defines the grid positions; `pages[].slots` maps widget IDs to component names (pipelinq pattern). All eleven custom components from the widgets change are wired as `type: "custom"` widgets. The sole `type: "stats-block"` is `minutes-in-review`. See design.md for the complete target manifest JSON.

## New Dependencies

None. No new npm packages or PHP dependencies.

## Impact

- `src/manifest.json` — Dashboard page rewritten (one JSON object replaced)
- `tests/e2e/dashboard-layout.spec.js` — new Playwright spec file
- `openspec/specs/dashboard/spec.md` — two MODIFIED requirements

No PHP changes. No schema changes. No Vue component changes.

## Cross-Project Dependencies

Depends on `decidesk-dashboard-v2-widgets` being merged first. That change's nine components must be present in `src/views/dashboard/widgets/` and registered in `src/registry.js` before this manifest wiring is deployed. The lib's local nextcloud-vue (`useLocalLib` alias) must support `kind: "widget"` resolution (verified in the widgets change design.md Decision 2).

## Risks

### Risk 1: Slot name collision in manifest

**Severity:** Low — **Mitigation:** The `slots` map uses `"widget-<id>"` keys (pipelinq pattern). All eleven slot names are unique; no collision with the lib's built-in slot identifiers. Verified against the pipelinq manifest reference implementation.

### Risk 2: `active-decisions` and `governance-health` require widgets change to be merged first

**Severity:** Medium — **Mitigation:** Both `ActiveDecisionsKpiWidget` and `GovernanceHealthWidget` are built in `decidesk-dashboard-v2-widgets` (this change's `depends_on`). If the widgets change is not yet deployed, the manifest wiring results in unknown-component slots (graceful CnDashboardPage fallback — other widgets still render). Deploy order is enforced by the ADR-032 chain dependency.

## Rollback Strategy

One JSON file changed. Rollback = revert the commit to `src/manifest.json`. The widget components remain in the registry but are no longer referenced from the manifest — they render as unknown/unavailable on the old Dashboard page (the old three stats-blocks return). No DB rollback.
