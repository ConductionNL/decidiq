# ActionItem Analytics — Declarative Migration

## Problem

`lib/Service/ActionItemAnalyticsService.php` (266 LOC) has three public
methods that compute analytics for the dashboard. Per ADR-031, behaviour
that fits an `x-openregister-*` extension belongs in the schema register,
not in a PHP service.

Per the 2026-05-06 readiness audit this service was nominated for
"migrate to `x-openregister-aggregations`". Re-reading the source shows
the picture is more nuanced than the audit caught:

| Method | LOC | Current state |
|---|---|---|
| `getSummary($from, $to)` | ~12 | **Already declarative-first.** Thin wrapper that calls OR's `AggregationRunner` for each of `totalOpen`, `totalOverdue`, `completedThisMonth`, `avgDaysToClose`. Each metric is declared on ActionItem's schema (commit `e8b1812`). The PHP cost is dispatch + a `round()` + error-suppression. |
| `getCompletionRates($limit)` | ~70 | **Imperative — migration target.** Walks the `$limit` most recent Meetings, then for each Meeting walks its linked ActionItems and counts completed-vs-total. Pure aggregation, but it requires **cross-schema grouping** (count ActionItems grouped by their parent Meeting) — the same engine capability question raised by QuorumService. |
| `getMyItems($userDisplayName)` | ~50 | **Stays imperative.** User-specific filter (`assignee == $current-user`) + presentation bucketing into overdue/thisWeek/later. The bucketing depends on user-call-time `$today` and `$weekAhead`, which calculations don't typically have access to (no `$user` scope in the expression DSL). Closer to controller logic than a schema-derived field. |

So the migration is **one method, not three**. Two of the three are
already in the right shape — one declaratively (getSummary), one
legitimately imperative (getMyItems).

## Proposed Solution

Migrate `getCompletionRates()` to a per-Meeting calculation that
consumes a cross-schema aggregation:

1. **`x-openregister-aggregations` on Meeting** declaring two cross-schema
   counts of related ActionItems (total, completed). Filtered on
   `meeting == @self.id` (or whichever back-reference the relation
   engine exposes).
2. **`x-openregister-calculations` on Meeting** declaring `actionItemCompletionRate`
   (completed / total × 100), readable on every Meeting object.
3. **Update `AnalyticsController::completionRates`** (or whichever
   controller call exposes this) to fetch recent Meetings and read
   `meeting.actionItemCompletionRate` directly.
4. **Delete `getCompletionRates()`** from `ActionItemAnalyticsService`.
   The remaining service has just `getSummary()` (already a thin
   AggregationRunner wrapper) and `getMyItems()` (legitimately
   imperative). Net LOC drop: ~70.

The migration depends on the **same OR engine capability** as the
QuorumService spec (`@self.{relation}` cross-schema filters). Two
routes:

- **A.** If the engine already supports it: ship both migrations under
  the same engine assumption. The QuorumService spike (PR #146 task 1)
  validates the capability for both.
- **B.** If the engine doesn't yet support it: this change blocks on
  the same OR feature request that QuorumService blocks on. Apply
  ADR-031 exception 1 to both; resume both when the OR change ships.

## Capabilities

### Modified Capabilities

- `meeting-management` — Meeting schema gains `actionItemCompletionRate`
  derived field + the two underlying ActionItem aggregations.
- `actionitem-analytics` — analytics service drops `getCompletionRates()`;
  `getSummary()` and `getMyItems()` unchanged.

### New Capabilities

(none)

## Stakeholders

- **Decidesk maintainers** — own the migration.
- **OpenRegister team** — share the cross-schema aggregation question
  with QuorumService spec; one feature request unblocks both.
- **Hydra reviewers** — second ADR-031-aware spec on decidesk; reuses
  the QuorumService spec's engine-dependency framing to verify the
  template stays consistent across migrations.

## References

- ADR-031 (hydra) — Schema-declarative business logic over service classes
- ADR-022 (hydra) — Apps consume OR abstractions
- `decidesk/lib/Service/ActionItemAnalyticsService.php` — current source
- `decidesk/lib/Settings/decidesk_register.json` — ActionItem aggregations
  block (existing reference) + Meeting schema (target for new fields)
- Decidesk PR #146 (`quorum-declarative-migration`) — sister spec hitting
  the same engine-capability question; if its task-1 spike passes, this
  spec inherits that signal
- ActionItem in same register — working `x-openregister-aggregations`
  + `x-openregister-calculations` reference (totalCompleted, byStatus,
  completedThisMonth, totalOpen, totalOverdue, avgDaysLate, avgDaysToClose,
  isOverdue, daysOpen, daysLate)
