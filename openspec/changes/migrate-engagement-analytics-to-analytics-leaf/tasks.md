# Tasks: Migrate engagement/voting analytics dashboards to the Analytics integration leaf

## 1. Adopt the analytics leaf
- [ ] 1.1 Confirm the analytics leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent
- [ ] 1.2 Surface the analytics leaf as the engagement dashboard tab via `MeetingIntegrations.vue`
- [ ] 1.3 Surface the analytics leaf for action-item and voting-behaviour dashboards
- [ ] 1.4 Graceful degradation when Analytics is absent (hide tab; data intact)

## 2. Split calculation from rendering
- [ ] 2.1 Per metric, apply the "can the leaf/schema aggregation compute it from raw OR data?" test
- [ ] 2.2 Move generic aggregations (counts, sums, completion rate, overdue) to the leaf or `x-openregister-aggregations` (ADR-031)
- [ ] 2.3 Retain governance-specific derived metrics (engagement score, quorum/weight-aware voting behaviour) as in-app calculations exposed to the leaf as values
- [ ] 2.4 Confirm engagement/vote/action-item *capture* paths are untouched

## 3. Retire in-app chart rendering
- [ ] 3.1 Remove in-app dashboard chart components for engagement/action-item/voting-behaviour
- [ ] 3.2 Strip chart-driving responsibilities from `EngagementService` / `ActionItemAnalyticsService` / `VotingBehaviourService`, keeping only retained calculations
- [ ] 3.3 Remove duplicate dashboard widgets that mirror the analytics leaf

## 4. Verification
- [ ] 4.1 Dashboards render via the analytics leaf over OR data (browser check)
- [ ] 4.2 Engagement score still correct, computed in-app, charted by the leaf
- [ ] 4.3 Analytics-absent instance renders pages without error
- [ ] 4.4 `composer check:strict` and ESLint pass
