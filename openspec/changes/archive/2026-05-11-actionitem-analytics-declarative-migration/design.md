# Design: ActionItem Analytics — Declarative Migration

## Status
proposed

## Background

`ActionItemAnalyticsService` was nominated for declarative migration in
the 2026-05-06 readiness audit. Re-reading the source against ADR-031
shows the picture is more nuanced — see `proposal.md` for the
method-by-method state.

Net result: **one method to migrate**, not three. This design covers
that one method (`getCompletionRates`) and explicitly classifies the
other two so future readers don't re-target them by mistake.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Summary metrics (`totalOpen`, `totalOverdue`, `completedThisMonth`, `avgDaysToClose`) | **Already declarative.** Declared on ActionItem's schema as `x-openregister-aggregations`; `getSummary()` is a thin `AggregationRunner` dispatch | Migrated in commit `e8b1812`; left untouched by this change. |
| Per-Meeting completion rate (count of completed ActionItems / count of all ActionItems linked to that Meeting × 100) | **Declarative — migrate.** Two `x-openregister-aggregations` (total + completed) on Meeting filtered by relation, plus one `x-openregister-calculations` (`actionItemCompletionRate`) computing the rate from those counts | Pure derived shape over related objects. Same shape as QuorumService's `quorumPercentage`/`quorumMet` migration. |
| User's open ActionItems grouped by urgency (overdue / thisWeek / later) | **Imperative.** Stays in `ActionItemAnalyticsService::getMyItems`. | Three reasons: (1) user-specific (`assignee == current-user`) — calculations don't have user-context in the expression DSL. (2) Bucketing depends on call-time `$today` / `$weekAhead` and "this week" semantics that are presentation logic, not domain state. (3) Returns the *items themselves* grouped, not a scalar — aggregations return scalars, not collections. ADR-031 exception 2 applies (behaviour spans the data model in a way the extension can't express). |
| `getSummary()` rounding + error suppression | Stays in service | Tiny — three lines of `round()` + try/catch. Deleting `getSummary()` would require every caller to call `AggregationRunner` directly with the same boilerplate; the thin wrapper is honest. ADR-031 exception 2 applies (cosmetic wrapper, not architecture). |

**Default chosen: declarative for the migrating method. The other two
are explicitly retained — see rationale.**

## Engine dependency (ADR-031 exception clause, shared with PR #146)

The `getCompletionRates` migration requires the same engine capability
as the QuorumService spec: an aggregation declared on Meeting that
counts related ActionItem objects, filtered by the back-relation:

```jsonc
{
  "completedActionItemCount": {
    "metric": "count",
    "schema": "ActionItem",
    "filter": {
      "meeting": "@self.id",
      "taskStatus": "completed"
    }
  },
  "totalActionItemCount": {
    "metric": "count",
    "schema": "ActionItem",
    "filter": {
      "meeting": "@self.id"
    }
  }
}
```

**This is the same `@self.{relation}` question PR #146 task 1 spikes.**
Two consequences:

1. If PR #146's spike passes, this spec inherits that signal — task 1
   here is a **lighter** spike (verify the same shape works for the
   ActionItem-back-to-Meeting direction; the QuorumService spec verified
   Meeting-forward-to-Participant).
2. If PR #146's spike fails, this spec blocks on the same OR feature
   request. Both move together.

## Impact on existing code

- `lib/Service/ActionItemAnalyticsService.php` — drop `getCompletionRates()`
  (~70 LOC). `getSummary()` and `getMyItems()` unchanged.
- `lib/Controller/AnalyticsController.php` — replace the call to
  `$analytics->getCompletionRates($limit)` with: fetch the most recent
  `$limit` Meetings, return each meeting's `title` + `actionItemCompletionRate`
  + `totalActionItemCount`. Dashboard payload shape may shift slightly
  (the new shape includes the Meeting's id for deep-linking) — coordinate
  with frontend reviewer.
- `tests/Unit/Service/ActionItemAnalyticsServiceTest.php` — drop the
  test cases for `getCompletionRates`. Keep the rest.
- `tests/Unit/Controller/AnalyticsControllerTest.php` — update fixtures
  to load Meetings with `actionItemCompletionRate` populated.
- Frontend dashboard widget (whichever component reads
  `/api/analytics/completion-rates`) — confirm the JSON shape post-migration
  still has `meetingTitle` + `completionRate` + `total`. If the controller
  shape changes, surface as a frontend follow-up task, not part of this
  change.

## Seed data (ADR-001)

Existing Meeting + ActionItem seeds in `x-openregister-seeds` already
provide the data. After this migration each seed Meeting will auto-gain
`actionItemCompletionRate` at materialise time. Spot-check: pick one
seed Meeting that has 2-3 linked ActionItems with mixed `taskStatus`
values and verify the materialised rate matches expected.

## Reuse Analysis (ADR-001)

| OpenRegister abstraction | Used here |
|---|---|
| AggregationRunner | Already used by `getSummary()`; unchanged |
| `x-openregister-aggregations` | New on Meeting — `completedActionItemCount`, `totalActionItemCount` |
| `x-openregister-calculations` | New on Meeting — `actionItemCompletionRate` |
| `x-openregister-relations` | Already declared between Meeting and ActionItem; the new aggregation filter rides on top |
| Aggregation engine cross-schema filter | **Engine dependency — see PR #146 task 1** |

## Deduplication Check (ADR-001)

Searched `openspec/specs/` and `openregister/lib/Service/` for overlap.
No duplication — completion rates are decidesk-domain. The OR side of
the equation is the existing aggregation+calculation engines, which we
consume rather than duplicate.

## Risks

1. **Engine dependency same as PR #146.** If that spike fails, this
   change blocks on the same OR feature request.
2. **`getMyItems()` being mistaken for migration target.** Mitigated
   by the explicit table above. Future readers see the rationale.
3. **Frontend payload drift.** The dashboard widget consuming
   `/api/analytics/completion-rates` reads three fields — controller
   keeps the same wire shape; only the source of the values changes.
   Verify with one Playwright pass on the dashboard after merge.
4. **Performance: aggregation on every Meeting read.** Materialised
   calculations on Meeting writes; if the engine doesn't recompute on
   ActionItem write (when an ActionItem flips to `completed`, the
   parent Meeting's `actionItemCompletionRate` should refresh), the
   field stales. Engine docs verify needed; flag as a soft-gate test
   in tasks.md.

## Out of scope

- `getMyItems()` — explicitly retained as imperative per ADR-031
  exception 2.
- The `getSummary()` rounding wrapper — same.
- Deletion of `ActionItemAnalyticsService` entirely. The class shrinks
  to ~190 LOC after this change but doesn't disappear.
- Migrating the dashboard widget's Vue component. The controller
  preserves the wire shape; widget changes (if any) are a separate
  frontend follow-up.
