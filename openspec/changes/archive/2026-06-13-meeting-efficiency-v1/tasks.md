# Tasks: Meeting Efficiency v1

## 1. Schema (additive)

- [x] 1.1 `lib/Settings/decidesk_register.json`: add `hourlyRate` (number) to governance-body; `openedAt`, `closedAt` (date-time) + `meetingCost` (number) to meeting; `pausedDuration` (integer) to agenda-item. Bump the three schema versions.

## 2. Pure-logic helpers + vitest (primary suite)

- [x] 2.1 `src/utils/meetingTimer.js` — timer state machine (create/start/pause/resume/extend, elapsed-excluding-pauses, paused total, remaining, over-time, clock formatting).
- [x] 2.2 `src/utils/speakerQueue.js` — speaker queue (add dedup / remove / chair reorder / give-floor auto-switch / stop returns duration, per-speaker elapsed, over-limit).
- [x] 2.3 `src/utils/meetingCost.js` — running cost formula (spec worked example 12 × €75 × 45 min = €675), per-agenda-item cost, EUR formatting.
- [x] 2.4 `src/utils/meetingAnalytics.js` — duration stats vs scheduled, agenda completion rate, speaking distribution, cost trend, per-item cost breakdown with most-expensive flag, time-allocation accuracy by item type.
- [x] 2.5 Exhaustive vitest specs for all four utils (`tests/vitest/meetingTimer.spec.js`, `speakerQueue.spec.js`, `meetingCost.spec.js`, `meetingAnalytics.spec.js`).

## 3. Backend (additive)

- [x] 3.1 `lib/Service/MeetingCostService.php` — pure `computeCost()` + `calculateForMeeting()` resolving hourlyRate/attendees via OR ObjectService (named args, real API).
- [x] 3.2 `MeetingService::transition()` — stamp `openedAt` on first open; `closedAt` + fail-soft `meetingCost` on close.
- [x] 3.3 `EngagementController::capture()` — additive chair/secretary authorisation via `ParticipantResolver` (admin fallback preserved).
- [x] 3.4 PHPUnit: `MeetingCostServiceTest`, `EngagementControllerTest`, `MeetingServiceTest` open/close stamping additions.

## 4. LiveMeeting UI

- [x] 4.1 `src/components/liveMeeting/AgendaItemTimer.vue` — countdown for the active item, chair-only start/pause/resume/extend(+5/+10)/close, over-time alert state, no countdown for items without `estimatedDuration`, writes `actualDuration` + `pausedDuration` on close via the object store.
- [x] 4.2 `src/components/liveMeeting/SpeakerQueuePanel.vue` — NcSelect (inputLabel) speaker picker, queue with chair reorder/remove, current-speaker highlight, configurable limit + over-limit alert, records speeches via `POST /api/engagement`.
- [x] 4.3 `src/components/liveMeeting/MeetingCostPanel.vue` — toggleable live cost (elapsed × present attendees × body hourlyRate), no-rate hint state.
- [x] 4.4 Integrate all three into `src/views/LiveMeeting.vue` around the minutes-ui-v1 MinutesPanel (no changes to its wiring).

## 5. Analytics tab

- [x] 5.1 `src/components/tabs/GovernanceBodyEfficiencyTab.vue` — per-body efficiency analytics computed client-side from OR objects (duration trend + average + overrun highlight, completion rate, speaking distribution, cost trend, per-item cost breakdown, allocation accuracy + recommendations).
- [x] 5.2 Register the tab in `src/registry.js` and on the `GovernanceBodyDetail` page's `sidebarTabs` in `src/manifest.json`; register `engagement-record` in `src/store/store.js`.

## 6. i18n

- [x] 6.1 English-keyed strings added to `l10n/en.json`, `l10n/en_US.json`, `l10n/nl.json` (lossless merge).

## 7. e2e + spec sync

- [x] 7.1 `tests/e2e/spec-coverage/meeting-efficiency.spec.ts` — timer renders / pause indicator, no-countdown informational, speaker queue add/remove/highlight, cost toggle, efficiency tab sections; defensive skips; `@e2e` scenario references. Wall-clock scenarios carry reason-bearing `@e2e exclude` in the spec.
- [x] 7.2 Spec delta synced to `openspec/specs/meeting-efficiency/spec.md`; frontmatter updated honestly.

## 8. Quality

- [x] 8.1 `npm run build` green; full vitest + PHPUnit suites green; all 24 hydra gates green.
