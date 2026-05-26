# Proposal: Migrate engagement/voting analytics dashboards to the Analytics integration leaf

## Why

Decidesk computes and renders several analytics dashboards from in-app services:

- `EngagementService` (6 methods) — aggregates speeches, questions, topics, and a derived engagement score per participant per meeting.
- `ActionItemAnalyticsService` (4 methods) — action-item completion rates, overdue counts, personal lists.
- `VotingBehaviourService` (4 methods) — voting-behaviour statistics aggregated from Vote objects.

Each computes metrics **and** drives chart rendering in the app's own dashboard. ADR-019 exposes an **analytics** leaf — registry-bound charts over OR object data — and ADR-022 forbids an app-local charting/dashboard surface that duplicates it.

The right split: the **chart/dashboard surface** moves to the analytics leaf; **decision-domain metric calculations** that the generic leaf cannot compute (e.g. a governance-specific engagement score, quorum-weighted voting behaviour) stay in-app as a calculation the leaf consumes — they are not chart rendering, they are domain logic.

## What Changes

- **Adopt the analytics leaf** as the dashboard/charting surface for engagement, action-item, and voting-behaviour metrics, bound via the ADR-019 registry and surfaced through the registry tab/widget shell.
- **Keep domain metric calculation in-app where the leaf cannot compute it.** Generic aggregations (counts, completion rate, simple sums) move to the leaf's query/aggregation layer. Governance-specific derived metrics (engagement score formula, weighted/quorum-aware voting behaviour) remain as in-app calculations exposed to the leaf as computed fields/values.
- **Retire the in-app chart rendering** and the dashboard widgets that duplicate the analytics leaf.

## Capabilities

### New Capabilities

- `governance-analytics-via-analytics-leaf`: Engagement, action-item, and voting-behaviour dashboards are rendered by the ADR-019 analytics leaf over OR object data; governance-specific derived metrics are computed in-app and exposed to the leaf as values.

### Removed Capabilities

- `participant-engagement-tracking` dashboard rendering and the in-app voting-behaviour / action-item dashboard charts — superseded by the analytics leaf. (The *capture* of engagement records and votes is unaffected.)

## Impact

- **Services reshaped:** `EngagementService`, `ActionItemAnalyticsService`, `VotingBehaviourService` lose their chart-driving/dashboard responsibilities; their genuinely domain-specific calculations are retained as computed values for the leaf, or moved to schema-declarative aggregations per ADR-031 where expressible.
- **Frontend:** in-app analytics dashboard charts replaced by the registry-driven analytics leaf.
- **Dependency:** Nextcloud Analytics app; OpenRegister integration registry (ADR-019).
- **Out of scope / kept in-app:** statutory voting itself and ORI/Popolo publication; *generic* metric capture stays; only the dashboard/charting surface migrates — see design.md.
