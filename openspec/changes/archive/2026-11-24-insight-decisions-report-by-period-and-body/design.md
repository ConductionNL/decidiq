# Design: insight-decisions-report-by-period-and-body

Read at decidiq development `4d7430ff`, openregister development `555af72` and nextcloud-vue development `c8aa858`.

## What exists

| Piece | Where |
|---|---|
| Report page | `src/manifest.json:1354` `DecisionsReport` (`/reports/decisions`, type dashboard): stats `dec-adopted`, `dec-rejected`, `dec-public`; donuts `dec-by-outcome`, `dec-by-type`, `dec-by-lifecycle`; table `dec-recent`; `_note` claims only scalar equality works |
| Reports hub card | `src/manifest.json:1319` |
| Decision fields | `lib/Settings/decidesk_register.json` `Decision.decisionDate`, `outcome`, `decisionType`, `lifecycle`; `lib/Settings/register.d/67-model-debt-cleanup.json` `Decision.meeting` (uuid, facetable); `lib/Settings/register.d/78-decision-carries-the-brc-besluit-fields.json` `Decision.governingBody` (free text) |
| Meeting body | `Meeting.governanceBody` in `lib/Settings/decidesk_register.json` |
| OpenRegister operators | openregister `lib/Service/Aggregation/AggregationQuery.php:12-13` |
| Dashboard range | nextcloud-vue `src/components/CnDashboardPage/CnDashboardPage.vue:1165-1185` (`dateRange`), `:2434-2447` writes `dateFrom`, `dateTo`, `datePreset` into the workspace context; `:1221` `pageFilters` (static select only) |
| Filter tokens | nextcloud-vue `src/utils/resolveFilterTokens.js`: `@workspace.<key>` with trailing `?` for optional, `@yearStart`, `@quarterStart`, operator form `{ field: { gte: ... } }` |
| Decision listeners | `lib/AppInfo/Registrar/ObjectListenerRegistrar.php:40-43` registers `ObjectCreatingEvent` and friends; openregister also dispatches `ObjectUpdatingEvent` (`lib/Event/ObjectUpdatingEvent.php`) |

## Approach

1. **Field.** Fragment `lib/Settings/register.d/92-decisions-report-cut.json` adds `Decision.decidingBody` (uuid, `$ref` GovernanceBody, facetable, nullable).
2. **Fill.** `lib/Listener/DecisionDecidingBodyListener.php` on `ObjectCreatingEvent` and `ObjectUpdatingEvent` for schema `decision`: when `meeting` is set and `decidingBody` is empty, copy the meeting's `governanceBody`. A repair step `lib/Repair/FillDecisionDecidingBody.php` does the same once for existing decisions.
3. **Period.** `DecisionsReport` gets `dateRange: { enabled: true, default: "this-year", presets: [this-year, last-year, this-quarter, custom] }`. Every stat and chart source adds `decisionDate: { gte: "@workspace.dateFrom?", lte: "@workspace.dateTo?" }`; the optional marker drops the bound when the range is open.
4. **Body.** nextcloud-vue's `pageFilters` take static options only, and bodies differ per installation. A small custom header widget `DecisionsReportBodyPicker` (`src/components/reports/DecisionsReportBodyPicker.vue`) lists governance bodies and writes the chosen uuid to the workspace context as `decidingBody`; every source adds `decidingBody: "@workspace.decidingBody?"`.
5. **By body and the list.** A donut `dec-by-body` groups by `decidingBody`. The `dec-recent` table gets `viewAllRoute: Decisions` with `viewAllQuery: { decidingBody: "@workspace.decidingBody?", decisionDate_gte: "@workspace.dateFrom?", decisionDate_lte: "@workspace.dateTo?" }`, so the list opens pre-filtered and its mass export (CnIndexPage) exports exactly the report's decisions.
6. **Note.** The page `_note` is rewritten to name the supported operators and cite `AggregationQuery.php`.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Period and body cut, charts, list link | Declarative manifest | Supported by CnDashboardPage and OpenRegister |
| Body picker | One thin custom widget | `pageFilters` cannot list objects |
| Filling `decidingBody` | Imperative listener and repair step | ADR-031 exception: a derived value from another object (the meeting), which calculations cannot read |

## Seed data

Each example set's decisions get `decidingBody` through the repair step on load; the municipality set spreads its decisions over the council and the college and over 2025 and 2026, so the pickers show a difference on a fresh install.

## Files

- `lib/Settings/register.d/92-decisions-report-cut.json`, `lib/Listener/DecisionDecidingBodyListener.php`, `lib/Repair/FillDecisionDecidingBody.php`, `appinfo/info.xml` (repair step), `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- `src/manifest.json` (`DecisionsReport`), `src/components/reports/DecisionsReportBodyPicker.vue`, `src/registry.js`
- `tests/Unit/Listener/DecisionDecidingBodyListenerTest.php`, `tests/newman/decisions-report-cut.json` (the aggregation endpoint counts with `gte` and `lte`), `tests/e2e/decisions-report.spec.ts`
