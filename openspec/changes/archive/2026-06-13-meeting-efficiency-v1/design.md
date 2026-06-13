# Design: Meeting Efficiency v1

## Architecture

Frontend-heavy per the thin-client pattern (openspec/config.yaml): decidesk owns no
tables; all persistence goes through OpenRegister. The only backend additions are
(a) an additive authorisation widening on `EngagementController::capture()` and
(b) server-side cost stamping in `MeetingService::transition()` via a new
`MeetingCostService` — because a client-computed persisted cost could be spoofed.

### Pure-logic helpers (`src/utils/`)

All time/cost/queue math is in dependency-free, side-effect-free modules with the
current timestamp injected (`now` parameter) so vitest can test tick math without
fake timers. Components own the 1-second `setInterval` and re-render; the helpers
own every decision.

- `meetingTimer.js` — immutable timer state `{ allocatedSeconds, startedAt,
  pausedAt, pausedTotalMs, extensionsSeconds, finished }` with `createTimer`,
  `startTimer`, `pauseTimer`, `resumeTimer`, `extendTimer`, `elapsedSeconds`
  (excludes paused time), `pausedSeconds`, `remainingSeconds`, `isOverTime`,
  `formatClock`. Pause duration is tracked separately per the spec scenario.
- `speakerQueue.js` — immutable queue of `{ participantId, displayName,
  requestedAt, startedAt, spokenMs, speaking }` with `addSpeaker` (dedup),
  `removeSpeaker`, `moveSpeaker` (chair reorder), `startSpeaker` (auto-stops the
  current one, returning its final duration), `stopSpeaker`, `speakerElapsedSeconds`,
  `isOverLimit`, `currentSpeaker`.
- `meetingCost.js` — `computeMeetingCost(elapsedSeconds, attendeeCount, hourlyRate)`
  = hours × attendees × rate (spec worked example: 45 min × 12 × €75 = €675),
  `agendaItemCost(actualMinutes, attendeeCount, hourlyRate)`, `formatEur`.
- `meetingAnalytics.js` — `meetingDurationStats` (actual = closedAt−openedAt,
  scheduled = endDate−scheduledDate, average + overrun flags),
  `agendaCompletionRate` (item completed when status `afgerond` or
  `actualDuration` recorded), `speakingDistribution` (EngagementRecord
  `speakingDuration` shares), `costTrend` (persisted `meetingCost` by date,
  total/average), `agendaItemCostBreakdown` (per-item cost, most expensive
  flagged), `timeAllocationAccuracy` (avg estimated vs actual grouped by
  itemType, with an over/under verdict feeding the UI recommendation string).

### LiveMeeting integration

Three new sections, inserted around (never inside) the existing minutes-ui-v1
`MinutesPanel`:

- `AgendaItemTimer.vue` renders inside the existing "Active item" section for the
  activated agenda item. Chair-only controls (Start / Pause / Resume / +5 min /
  +10 min / Close item); "chair" mirrors the server guard = chair-role participant
  or NC admin fallback. Over-time = red pulsing clock + `role="alert"` with the
  extend/close options. Items without `estimatedDuration` (informational) display
  no countdown but elapsed time still accumulates and is written on close.
  Close writes `actualDuration` (minutes) + `pausedDuration` (minutes) via
  `objectStore.saveObject('agenda-item', …)` — OR per-object ACLs are the
  server-side enforcement; no new endpoint is needed.
- `SpeakerQueuePanel.vue` after the active-item section. NcSelect (`inputLabel`)
  over the meeting participants; queue list with chair up/down reorder and
  remove; "Give floor" starts a per-speaker timer; configurable limit (minutes)
  with over-limit highlight + alert; stopping (or switching) a speaker POSTs
  `/api/engagement` `{ eventType: 'speech', eventData: { duration } }` so
  `speakingDuration` lands in the existing EngagementRecord aggregate.
- `MeetingCostPanel.vue` under the header: toggleable (hidden by default), reads
  the governance body's `hourlyRate` (lazily fetched via the object store),
  counts present participants (fallback: all linked participants when nobody is
  marked present), elapsed from the meeting's `openedAt` stamp, ticking every
  second. Shows a hint instead of a figure when no rate is configured.

### Backend (additive only)

- `MeetingService::transition()` — on `open`, stamp `openedAt` (first open only,
  so resume-after-adjourn keeps the original start); on `close`, stamp `closedAt`
  and ask `MeetingCostService::calculateForMeeting()` for the final cost
  (fail-soft: cost errors never block closing a meeting).
- `MeetingCostService` — `computeCost()` pure math + `calculateForMeeting()`
  which resolves the governance body's `hourlyRate` and the present-participant
  count through OR ObjectService (real API: `find`/`findAll`, named args).
  Returns null when no rate is configured (nothing persisted).
- `EngagementController::capture()` — the existing guard (self-or-admin) gains
  the meeting chair/secretary via `ParticipantResolver::hasRole()`, matching
  `AgendaController::requireChairOrAdmin()`. No route changes.

### Analytics surface

`GovernanceBodyEfficiencyTab.vue` registered in `src/registry.js` (kind: page) and
added to the `GovernanceBodyDetail` manifest page's `sidebarTabs` — the same
registry pattern as `GovernanceBodyMembersTab`. It fetches meetings, agenda items,
participants and engagement-records via `useObjectStore` (new `engagement-record`
logical type registered in `src/store/store.js`) and filters client-side by the
body id (both `@self.relations` and flat-property shapes, like LiveMeeting does).
Charts are dependency-free CSS bars.

## Decisions

- **Per-body tab, not a new top-level page** — analytics are inherently per body
  ("GIVEN a body with 12 meetings…"); the detail tab keeps the IA flat and reuses
  the existing registry/manifest pattern with zero new routes.
- **No new HTTP endpoints** — timers are presentation state; every persisted write
  rides an existing guarded path. Hence no Newman collection changes (rule:
  Newman only for new endpoints).
- **Server-side `meetingCost` persistence** — the live panel is client-computed
  for display, but the persisted figure on close is computed in PHP from stored
  data so analytics can trust it.
- **`pausedDuration` additive on agenda-item** — the spec requires the pause
  duration "recorded separately"; a sibling integer next to `actualDuration` is
  the smallest honest representation.

## Test strategy

- **vitest (primary)**: exhaustive unit specs for all four utils — tick math,
  pause/resume accounting, extensions, over-time, queue ordering/dedup/reorder,
  speaker switch semantics, cost formula (incl. the spec's €675 worked example),
  EUR formatting, every analytics aggregate incl. empty-input safety.
- **PHPUnit**: `MeetingCostServiceTest` (pure math + OR-resolution paths),
  `EngagementControllerTest` (401 / self-allowed / other-forbidden /
  chair-allowed / admin-allowed), `MeetingServiceTest` additions (openedAt
  stamped on open; closedAt + meetingCost on close; cost failure is fail-soft).
- **Playwright**: `tests/e2e/spec-coverage/meeting-efficiency.spec.ts` with
  `@e2e` scenario references + defensive skips (no meeting/body seeded → skip).
  Wall-clock scenarios (15-minute expiry, 3-minute speaking limit) carry
  reason-bearing `@e2e exclude` in the spec; their logic is vitest-covered.
