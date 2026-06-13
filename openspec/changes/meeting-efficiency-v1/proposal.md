---
kind: code
---

# Proposal: Meeting Efficiency v1 — Agenda Timers, Speaker Queue, Cost Calculator, Analytics

## Problem

The seeded `openspec/specs/meeting-efficiency/spec.md` is `status: idea` with 0/4 requirements built. The data layer is already half-there — `agenda-item` carries `estimatedDuration`/`actualDuration`, `EngagementService` aggregates `speakingDuration` per (meeting, participant) — but nothing in the SPA surfaces it:

- No agenda-item countdown timer exists in the LiveMeeting view; `actualDuration` is never written, so the "Actual minutes spent" field is dead schema.
- No speaker queue or per-speaker timer exists; `EngagementService::captureEngagement()` is only reachable through the bare Engagement index page, never from a running meeting, and `EngagementController::capture()` blocks a non-admin chair from recording a speech for another participant (admin-only override), so a real Dutch chair (never an NC admin, per the MeetingController ADR-005 note) cannot operate it.
- No meeting cost surface exists anywhere; the governance-body schema has no rate field.
- No per-body efficiency analytics exist — duration vs scheduled, agenda completion, speaking-time distribution and cost trends are uncomputed even though the OR objects to derive them are all present.

## Proposed Change

Build all four requirements as a frontend-heavy change with minimal additive backend:

1. **Agenda item timer** — pure-logic timer state machine in `src/utils/meetingTimer.js` (start/pause/resume/extend/over-time/elapsed-excluding-pauses), consumed by a new `AgendaItemTimer.vue` panel in LiveMeeting. Chair-only controls (chair participant or NC admin fallback, mirroring the server guards). On "Close item" the actual minutes (and separately-recorded paused minutes) are written back to the agenda item through the shared OR object store (per-object ACLs enforce the write server-side). Informational items without `estimatedDuration` show no countdown but still track elapsed time for analytics.
2. **Speaking time management** — pure queue logic in `src/utils/speakerQueue.js` (add/remove/reorder/start/stop, per-speaker elapsed, over-limit), consumed by `SpeakerQueuePanel.vue` in LiveMeeting: NcSelect speaker picker, chair reordering, current-speaker highlight, configurable per-speaker limit with over-limit alert. Stopping a speaker records the speech via the existing `POST /api/engagement` endpoint (EngagementService data layer extended additively: `EngagementController::capture()` now also authorises the meeting's chair/secretary via `ParticipantResolver`, not only NC admins).
3. **Meeting cost calculator** — additive `hourlyRate` on the governance-body schema; pure `src/utils/meetingCost.js` (cost formula + EUR formatting); toggleable `MeetingCostPanel.vue` in LiveMeeting showing live cost = elapsed × attendees × rate. `MeetingService::transition()` additively stamps `openedAt` on open and `closedAt` + computed `meetingCost` on close via a new `MeetingCostService` (fail-soft, server-side so the persisted figure cannot be spoofed by a client).
4. **Meeting analytics dashboard** — `GovernanceBodyEfficiencyTab.vue` registered on the GovernanceBodyDetail manifest page (registry pattern), computing everything client-side from OR objects via `useObjectStore`: average meeting duration vs scheduled, agenda completion rate, per-item cost breakdown (most expensive highlighted), speaking-time distribution from EngagementRecords, cost trend over time, and allocated-vs-actual accuracy by item type with recommendations. Pure math lives in `src/utils/meetingAnalytics.js`.

## What Breaks / Risks

- Nothing breaks: all schema changes are additive (`hourlyRate`, `openedAt`, `closedAt`, `meetingCost`, `pausedDuration`); existing objects without the new fields render the new surfaces in their empty states.
- LiveMeeting was just reworked by minutes-ui-v1 (PR #60, MinutesPanel); the new sections are inserted around it without touching the minutes editor wiring.
- Timer state is client-side presentation only (no new endpoint); the persisted writes (`actualDuration`, engagement speeches, `meetingCost`) all go through guarded paths (OR per-object ACLs, chair/secretary/admin guard, server-side cost computation).

## Affected Specs

- `meeting-efficiency` (all 4 requirements move from idea → built)
