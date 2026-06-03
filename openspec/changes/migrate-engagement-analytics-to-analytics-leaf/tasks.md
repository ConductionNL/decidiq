# Tasks: Migrate engagement/voting analytics dashboards to the Analytics integration leaf

## 1. Adopt the analytics leaf
- [x] 1.1 Confirm the analytics leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent
- [x] 1.2 Surface the analytics leaf as the engagement dashboard tab via `MeetingIntegrations.vue`
- [x] 1.3 Surface the analytics leaf for action-item and voting-behaviour dashboards
- [x] 1.4 Graceful degradation when Analytics is absent (hide tab; data intact)

## 2. Split calculation from rendering
- [x] 2.1 Per metric, apply the "can the leaf/schema aggregation compute it from raw OR data?" test
- [x] 2.2 Move generic aggregations (counts, sums, completion rate, overdue) to the leaf or `x-openregister-aggregations` (ADR-031)
- [x] 2.3 Retain governance-specific derived metrics (engagement score, quorum/weight-aware voting behaviour) as in-app calculations exposed to the leaf as values
- [x] 2.4 Confirm engagement/vote/action-item *capture* paths are untouched

## 3. Retire in-app chart rendering
- [x] 3.1 Remove in-app dashboard chart components for engagement/action-item/voting-behaviour
- [x] 3.2 Strip chart-driving responsibilities from `EngagementService` / `ActionItemAnalyticsService` / `VotingBehaviourService`, keeping only retained calculations
- [x] 3.3 Remove duplicate dashboard widgets that mirror the analytics leaf

## 4. Verification
- [x] 4.1 Dashboards render via the analytics leaf over OR data (browser check)
- [x] 4.2 Engagement score still correct, computed in-app, charted by the leaf
- [x] 4.3 Analytics-absent instance renders pages without error
- [x] 4.4 `composer check:strict` and ESLint pass
