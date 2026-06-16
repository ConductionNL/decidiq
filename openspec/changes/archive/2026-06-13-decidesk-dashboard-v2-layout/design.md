# Design: decidesk-dashboard-v2-layout

## Context

The `decidesk-dashboard-v2-widgets` change builds nine Vue widget components and registers them in `src/registry.js`. The Dashboard page in `src/manifest.json` currently shows three Dutch stats-blocks (`minutes-in-review`, `published-decisions`, `open-action-items`). This change replaces that page entry with the five-row v2 grid. It is purely declarative: one JSON object in `src/manifest.json` is replaced.

Key constraints discovered by reading the lib sources:

- **Chart dataSource (CnChartWidget.vue / useDataSource.js):** The `bucket` shorthand supports ONE metric field per query → resolved as `{ series: [values], categories: [keys] }`. A two-series chart (quorumPercentage + actionItemCompletionRate) requires raw GraphQL (`dataSource.graphql`). The raw form is fully supported (CnChartWidget.vue line 215 `dataSource` prop, useDataSource.js `graphql.query` branch). CnDashboardPage.vue `getChartProps()` passes `dataSource` to `CnChartWidget`'s prop separately from the chart props. **Evidence**: `useDataSource.js:91` — `if (s.graphql?.query)` passes through verbatim; `CnChartWidget.vue:235` — `useDataSource(() => props.dataSource)`.

- **Widget title i18n (CnDashboardPage.vue):** `getWidgetTitle(item)` returns `item.customTitle || def?.title || item.widgetId` (line 1227). Manifest widget `title` strings are NOT run through `t()`. They are raw JSON strings. The widget component bodies carry their own translated headings via `t('decidesk', '...')`. **Decision**: for custom widgets (`type: "custom"`), set `showTitle: false` in layout items so the widget's own header (translated by the component) is the visible heading; widget `title` in the manifest serves as the fallback label only. For stats-block and chart widgets, the manifest `title` IS the displayed header (inside `CnWidgetWrapper`) — so they MUST be English strings.

- **Empty-state mechanism (CnDashboardPage.vue):** The `#empty` slot is rendered when `!hasWidgets` — i.e., when `layout.length === 0 && widgetRefItems.length === 0` (line 814-815). This is triggered when the manifest `config.layout` array is empty. The `DashboardEmptyState` component (from the widgets change) is NOT rendered via the `#empty` slot in the manifest — the manifest has no `emptyComponent` field. The mechanism for wiring `DashboardEmptyState` in a config-only change is to include it as a regular custom widget in `widgets[]` AND in `layout[]` spanning the full 12 columns, with a manifest `showWhen` condition. However, CnDashboardPage has no `showWhen` per-widget filtering. **Decision**: add `DashboardEmptyState` as a full-width custom widget at Row 0 of an ALTERNATE empty-state layout. In practice for this config-only change, the manifest specifies the normal v2 layout. A secondary `emptyStateLayout` approach is not available in the current lib. **Actual mechanism**: the `#empty` slot is available to the app's Vue shell (CnAppRoot / page component) — but the manifest cannot inject a slot directly. The pragmatic approach: include `DashboardEmptyState` as a `type: "custom"` widget at the END of the `widgets` array and at the END of `layout` with `gridWidth: 12, gridHeight: 6`, conditional on runtime. Because the manifest cannot express conditional rendering, document this as: `DashboardEmptyState` is placed first in the layout (Row 0) with `gridY: 0` to show it above all other widgets when the app shell detects no governance body and conditionally swaps the layout — but that requires app code, not manifest config. **Final decision**: leave `DashboardEmptyState` as a declared manifest widget but annotate in design.md that the conditional "show only when no governance body" logic requires the app shell (a thin ≤10 LOC glue in the Dashboard view) — this is the Mixed-spec rationale exception under ADR-031. See "Declarative-vs-Imperative Decisions" below.

## Goals / Non-Goals

**Goals:**
- Replace the Dashboard page in `src/manifest.json` with the v2 five-row grid
- Wire all eleven custom widget components via the `slots` map (pipelinq pattern)
- Add full-dashboard Playwright e2e scenarios (deferred from the widgets change)
- Update spec delta: MODIFIED Dashboard Layout + KPI Cards

**Non-Goals:**
- Vue component file changes — all in `decidesk-dashboard-v2-widgets`
- `OCP\Dashboard\IWidget` PHP registration
- Per-user layout persistence (same as current manifest — no `useDashboardView` store integration in this chain)

## Declarative-vs-Imperative Decisions (ADR-031)

| Behaviour | Choice | Rationale |
|-----------|--------|-----------|
| Widget grid layout | **Fully declarative** | `manifest.json` `widgets[]` + `layout[]` + `slots` map — zero code |
| KPI row — `active-decisions` | **Declarative (manifest custom slot)** | `type: "custom"` + slot `widget-active-decisions` → `ActiveDecisionsKpiWidget`; the Decision schema has no lifecycle field so "active" = `outcome == null`, counted client-side by the component (built in the widgets change). A stats-block with a manifest filter was ruled out: OR's shorthand cannot express IS NULL and count-all would misrepresent resolved decisions. |
| Custom widgets (10 + empty-state = 11 components) | **Declarative (manifest slots)** | `type: "custom"` + `slots` map; all components from the widgets change |
| Governance health widget | **Declarative (manifest custom slot)** | `type: "custom"` + slot `widget-governance-health` → `GovernanceHealthWidget`. A built-in `type: "chart"` manifest widget was investigated and ruled out: `useDataSource.js:149–162` resolves a single metric to `{ series: [values], categories: [keys] }` — two named live series (quorum % + action completion %) are not expressible without assembling `{ name, data[] }` objects; only a fake static fallback would be possible. The user confirmed reinstatement of the custom component with live data (decision 2026-06-12). The chart's grid position (Row 5, gridX=6) is defined in `layout[]` of THIS change; the component implementation lives in the widgets change. |
| `DashboardEmptyState` conditional rendering | **Mixed-spec exception (≤8 LOC, 1 file)** | The `#empty` slot of CnDashboardPage triggers only when `layout.length === 0`. Showing `DashboardEmptyState` when governance bodies = 0 but layout is non-empty requires runtime detection. The app shell's Dashboard.vue (or CnDashboardPage's parent) needs `if (governanceBodies.length === 0) showEmptyState = true`. This is ~8 LOC in `src/views/DashboardPage.vue` (or whatever the current dashboard page component is). If that file doesn't exist and the layout is fully manifest-driven via CnAppRoot → CnPageRenderer, the empty state is delivered by leaving an `emptyLayout: []` array that swaps in when no bodies are detected — deferred to a future iteration. **For this change**: include `DashboardEmptyState` in the `widgets[]` array and the `slots` map; NOT in `layout[]`. App implementers wire the conditional swap in the dashboard page component. |
| Minutes-in-review stats-block | **Declarative (manifest stats-block, English title)** | Retained from v1 layout, title updated to "Minutes awaiting approval". This is the only remaining `type: "stats-block"` in the layout. |
| All widget titles | **Declarative (English manifest strings)** | Manifest titles are not run through `t()` (lib evidence above); custom widget titles set `showTitle: false` in layout so component-rendered headers appear instead |

**Mixed-spec rationale (ADR-031 exception):**
`DashboardEmptyState` conditional rendering: ~8 LOC in the dashboard page Vue component to check `governanceBodies.length === 0` and swap the layout. Strictly ≤20 LOC, ≤2 files. This is the minimum imperative glue not expressible in the manifest.

## Decisions

### Decision 1: Slots map follows pipelinq pattern exactly

Widget slot binding uses the `slots` key at the **page top level — a sibling of `config`, NOT inside `config` and NOT inside each widget definition** — mapping `"widget-<widgetId>": "<ComponentName>"`. This is the pipelinq pattern (confirmed in `pipelinq/src/manifest.json` Dashboard page, where `slots` sits beside `config`). CnPageRenderer reads `page.slots` (not `page.config.slots`) and builds the `#widget-<id>` scoped slots for CnDashboardPage. Placing `slots` under `config` silently breaks every custom widget (see the live-verify correction note above).

### Decision 2: `showTitle` for custom widgets

All custom widget layout items (all eleven, including `active-decisions` and `governance-health`) set `"showTitle": false`. The widget components themselves render their own translated heading. The manifest `title` field on custom widgets is an English string (for the overlay tooltip and edit-mode label) but does NOT appear as a rendered header when `showTitle: false`.

The sole `type: "stats-block"` (`minutes-in-review`) sets `"showTitle": true` (or omits, since true is the default) — the manifest `title` IS the rendered header via `CnWidgetWrapper`.

### Decision 3: `active-decisions` is a custom slot widget, not a stats-block

The Decision schema has no lifecycle field; "active" = `outcome` is null (enum: `adopted`/`rejected`). OR's manifest shorthand (`type: "stats-block"` + `dataSource.filter`) cannot express an IS NULL condition. A count-all approximation would overcount once decisions are resolved — unacceptable for a KPI card.

**Chosen:** `type: "custom"` with slot `widget-active-decisions` → `ActiveDecisionsKpiWidget`. The component (built in the widgets change) fetches decisions and counts `outcome == null` client-side. The manifest entry carries no `dataSource` block.

**Manifest config:**
```json
{
  "id": "active-decisions",
  "type": "custom",
  "title": "Active decisions"
}
```
Layout item: `showTitle: false` (component renders its own translated heading).

### Decision 4: Governance health is a custom slot widget, not a manifest chart

**Evidence for ruling out `type: "chart"`:** `useDataSource.js:149–162` resolves a single metric to `{ series: [values], categories: [keys] }`. `CnDashboardPage.vue` `getChartProps()` only forwards static `props` to `CnChartWidget`. There is no supported path for assembling two named live series (`[{ name, data: number[] }]`) from a manifest `type: "chart"` widget — the only option was hardcoded static seed values, i.e. fake data on a production dashboard. (Static data also triggers hydra stub-scan concerns.)

**User decision (2026-06-12):** reinstate `GovernanceHealthWidget` as a custom component with live data (built in the widgets change). The layout change wires it declaratively via the slot mechanism.

**Chosen:** `type: "custom"` with slot `widget-governance-health` → `GovernanceHealthWidget`. The component fetches up to 12 recent meetings with materialized `quorumPercentage`/`actionItemCompletionRate` and renders a live two-series chart. The manifest entry carries no `dataSource` block and no static `props.series`.

**Manifest config:**
```json
{
  "id": "governance-health",
  "type": "custom",
  "title": "Governance health"
}
```
Layout item: `showTitle: false` (component renders its own translated heading and handles the "Not enough data" state).

**REQ-004 Governance Health** is defined in the WIDGETS change spec delta (it is a component requirement, not a layout requirement). This change's spec delta carries only the grid position of `governance-health` inside the MODIFIED Dashboard Layout requirement.

### Decision 5: Remove `published-decisions` and `open-action-items` stats-blocks

Both superseded: `recent-decisions` covers published decisions; `my-action-items` + `overdue-actions-kpi` cover open action items. `minutes-in-review` is kept (English title: "Minutes awaiting approval") as a useful governance metric for approvers.

### Decision 6: Grid coordinates

12-column grid, default `cellHeight: 80` px (lib default). Widget heights in `gridHeight` cells:
- Row 1: KPI cards (`gridHeight: 2`) — compact, quick read
- Rows 2/3: List/process widgets (`gridHeight: 4`) — enough room for ~5 rows of items
- Row 4: Recent decisions (`gridHeight: 4`) — full width, 10 items
- Row 5: Minutes stats-block + health chart (`gridHeight: 4`) — matched heights

## Seed Data (ADR-001)

Reuse **Gemeenteraad Westerkwartier** seed from `decidesk-dashboard-v2-widgets/design.md`. No new objects needed. The governance health widget (`GovernanceHealthWidget`) fetches live data from OR — no static seed series in the manifest.

## Target Manifest: Dashboard Page (exact JSON)

This is the complete replacement for the `"id": "Dashboard"` entry in `src/manifest.json`. The apply step replaces the current Dashboard page object with this one verbatim.

```json
{
  "id": "Dashboard",
  "route": "/",
  "type": "dashboard",
  "title": "Dashboard",
  "config": {
    "widgets": [
      {
        "id": "active-decisions",
        "type": "custom",
        "title": "Active decisions"
      },
      {
        "id": "upcoming-meetings-kpi",
        "type": "custom",
        "title": "Upcoming meetings"
      },
      {
        "id": "pending-votes-kpi",
        "type": "custom",
        "title": "Pending votes"
      },
      {
        "id": "overdue-actions-kpi",
        "type": "custom",
        "title": "Overdue actions"
      },
      {
        "id": "upcoming-meetings-list",
        "type": "custom",
        "title": "Upcoming meetings"
      },
      {
        "id": "pending-votes-list",
        "type": "custom",
        "title": "Pending votes"
      },
      {
        "id": "running-processes",
        "type": "custom",
        "title": "Running processes"
      },
      {
        "id": "my-action-items",
        "type": "custom",
        "title": "My action items"
      },
      {
        "id": "recent-decisions",
        "type": "custom",
        "title": "Recent decisions"
      },
      {
        "id": "minutes-in-review",
        "type": "stats-block",
        "title": "Minutes awaiting approval",
        "iconClass": "icon-file",
        "props": {
          "countLabel": "minutes",
          "variant": "warning"
        },
        "dataSource": {
          "register": "decidesk",
          "schema": "minutes",
          "filter": { "lifecycle": "review" },
          "aggregate": "count"
        }
      },
      {
        "id": "governance-health",
        "type": "custom",
        "title": "Governance health"
      },
      {
        "id": "dashboard-empty-state",
        "type": "custom",
        "title": "Welcome"
      }
    ],
    "layout": [
      {
        "id": "1",
        "widgetId": "active-decisions",
        "gridX": 0,
        "gridY": 0,
        "gridWidth": 3,
        "gridHeight": 2,
        "showTitle": false
      },
      {
        "id": "2",
        "widgetId": "upcoming-meetings-kpi",
        "gridX": 3,
        "gridY": 0,
        "gridWidth": 3,
        "gridHeight": 2,
        "showTitle": false
      },
      {
        "id": "3",
        "widgetId": "pending-votes-kpi",
        "gridX": 6,
        "gridY": 0,
        "gridWidth": 3,
        "gridHeight": 2,
        "showTitle": false
      },
      {
        "id": "4",
        "widgetId": "overdue-actions-kpi",
        "gridX": 9,
        "gridY": 0,
        "gridWidth": 3,
        "gridHeight": 2,
        "showTitle": false
      },
      {
        "id": "5",
        "widgetId": "upcoming-meetings-list",
        "gridX": 0,
        "gridY": 2,
        "gridWidth": 6,
        "gridHeight": 4,
        "showTitle": false
      },
      {
        "id": "6",
        "widgetId": "pending-votes-list",
        "gridX": 6,
        "gridY": 2,
        "gridWidth": 6,
        "gridHeight": 4,
        "showTitle": false
      },
      {
        "id": "7",
        "widgetId": "running-processes",
        "gridX": 0,
        "gridY": 6,
        "gridWidth": 6,
        "gridHeight": 4,
        "showTitle": false
      },
      {
        "id": "8",
        "widgetId": "my-action-items",
        "gridX": 6,
        "gridY": 6,
        "gridWidth": 6,
        "gridHeight": 4,
        "showTitle": false
      },
      {
        "id": "9",
        "widgetId": "recent-decisions",
        "gridX": 0,
        "gridY": 10,
        "gridWidth": 12,
        "gridHeight": 4,
        "showTitle": false
      },
      {
        "id": "10",
        "widgetId": "minutes-in-review",
        "gridX": 0,
        "gridY": 14,
        "gridWidth": 6,
        "gridHeight": 4,
        "showTitle": true
      },
      {
        "id": "11",
        "widgetId": "governance-health",
        "gridX": 6,
        "gridY": 14,
        "gridWidth": 6,
        "gridHeight": 4,
        "showTitle": false
      }
    ]
  },
  "slots": {
    "widget-active-decisions": "ActiveDecisionsKpiWidget",
    "widget-upcoming-meetings-kpi": "UpcomingMeetingsKpiWidget",
    "widget-pending-votes-kpi": "PendingVotesKpiWidget",
    "widget-overdue-actions-kpi": "OverdueActionsKpiWidget",
    "widget-upcoming-meetings-list": "UpcomingMeetingsListWidget",
    "widget-pending-votes-list": "PendingVotesListWidget",
    "widget-running-processes": "RunningProcessesWidget",
    "widget-my-action-items": "MyActionItemsWidget",
    "widget-recent-decisions": "RecentDecisionsWidget",
    "widget-governance-health": "GovernanceHealthWidget",
    "widget-dashboard-empty-state": "DashboardEmptyState"
  }
}
```

> **⚠️ Live-verify correction (2026-06-13):** `slots` MUST be a sibling of `config` at the **page top level** — NOT nested inside `config`. CnPageRenderer's `resolvedSlotEntries` reads `page.slots`, not `page.config.slots`; placing it under `config` makes every custom widget render the `unavailableLabel` ("Widget not available") because the `#widget-<id>` scoped slots are never wired. The first apply placed it under `config` (gate-22 manifest-validation does not check slot placement and there was no browser in the container), and the host browser-verify caught it — the dashboard rendered 9× "Widget not available" with only the stats-block resolving. Fixed by relocating `slots` to the page top level, matching the working `pipelinq/src/manifest.json`.

**Note on `dashboard-empty-state`:** it is declared in `widgets[]` and `slots` but NOT in `layout[]`. CnDashboardPage will not render it unless the dashboard page component adds it to the layout at runtime when `governanceBodies.length === 0`. This is the mixed-spec exception: the thin glue (≤8 LOC) goes in the dashboard page Vue component, not in this manifest change. The manifest correctly declares the component; the conditional swap is the minimum imperative code not expressible in JSON.

**Widget/slot summary:** 12 entries in `widgets[]` (11 custom + 1 stats-block). 11 entries in `layout[]` (10 custom + 1 stats-block; `dashboard-empty-state` excluded). 11 entries in `slots` (all custom components; stats-block has no slot entry).

## Risks / Trade-offs

- [GovernanceHealthWidget and ActiveDecisionsKpiWidget require widgets change deployed first] → if the layout change is deployed before the widgets change, CnDashboardPage renders unknown-slot fallback for those two positions; other widgets are unaffected. Deploy order enforced by ADR-032 chain dependency.
- [showTitle: false on all custom widgets] → widget component headings (translated) are the visible titles; manifest titles are labels only. The sole stats-block (`minutes-in-review`) retains `showTitle: true` as the manifest title IS the rendered header for stats-block widgets.

## Migration Plan

No database or schema changes. Deploy: replace the Dashboard page entry in `src/manifest.json`, rebuild frontend, clear OPcache (`docker exec nextcloud apache2ctl graceful`). Rollback: revert `src/manifest.json` to the prior three-widget version.
