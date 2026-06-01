# Design: Migrate engagement/voting analytics dashboards to the Analytics integration leaf

status: pr-created

## Context

Three in-app services both *compute* metrics and *render* dashboards:

- `EngagementService` — speeches/questions/topics counts + a derived engagement score per participant per meeting.
- `ActionItemAnalyticsService` — completion rates, overdue counts, personal action lists (queries VTODO/ActionItem data).
- `VotingBehaviourService` — voting-behaviour statistics from Vote objects.

ADR-019's **analytics** leaf renders registry-bound charts over OR object data. ADR-022 forbids an app-local charting/dashboard surface duplicating it. But ADR-022's exceptions clause allows in-app logic the OR abstraction genuinely cannot provide — and a generic analytics leaf can count and sum, but cannot know decidesk's *governance-specific* derived metrics (an engagement-score formula, quorum/weight-aware voting behaviour).

## Goals / Non-goals

- **Goal:** charts/dashboards are the analytics leaf, not in-app chart components.
- **Goal:** governance-specific derived metrics that the leaf can't compute stay in-app and feed the leaf as values.
- **Non-goal:** stop *capturing* engagement records or votes — capture is unaffected; only the dashboard surface moves.

## Decisions

### D1 — Split rendering (→ leaf) from domain calculation (→ in-app, only where needed)

- **Move to the leaf:** all chart/dashboard rendering, plus generic aggregations the leaf can express (counts, sums, completion rate, overdue counts, simple group-bys). Prefer schema-declarative aggregations (`x-openregister-aggregations`, ADR-031) when the metric is expressible there, so even the calculation lives in the schema register rather than a service class.
- **Keep in-app (ADR-022 exception):** derived metrics the generic leaf cannot compute — the engagement-score formula and any quorum/weight-aware voting-behaviour statistic. These remain as in-app calculations exposed to the leaf as precomputed values/fields. The test is "can the analytics leaf or a schema aggregation compute it from raw OR data?" — if yes it moves; if no it stays as a feed.

### D2 — Capture is untouched

The *recording* of engagement records, action-item status, and votes is upstream of analytics and does not move. This change only changes who renders the dashboard and where generic aggregation happens.

### D3 — Migration: replace charts, repoint feeds

In-app dashboard chart components are removed and replaced by the analytics leaf tab/widget. Where a domain calculation is retained, it is exposed as a value the leaf reads (computed field / endpoint), not as a chart. No object migration is needed — analytics reads existing OR data; there is no analytics object store to archive.

## ADR-022 exceptions (kept in-app — NOT migrated)

- **Governance-specific derived metrics** — engagement-score formula, quorum/weight-aware voting-behaviour statistics. Justified exception: the generic analytics leaf cannot compute domain formulas from raw data. Retained as in-app calculations feeding the leaf (D1).
- **Statutory voting** — `VotingService` / `QuorumService` / `LiveDecisionService` (secret ballots, quorum, proxy/weighted votes) stay in-app. This change touches only the *behaviour analytics* over recorded votes, never the voting mechanism. The polls leaf is for informal straw polls only.
- **ORI / Popolo publication** — ADR-001 / ADR-003 stays in-app.

## Risks

- **Analytics not installed.** Registry hides the dashboard tab gracefully; raw data remains in OR.
- **Over-migration of domain logic.** Risk of pushing a governance formula into the leaf where it loses meaning; mitigated by D1's explicit "can the leaf compute it from raw data?" test, applied per metric at apply time.
