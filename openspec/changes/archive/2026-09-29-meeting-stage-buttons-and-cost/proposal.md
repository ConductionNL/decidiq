---
kind: code
depends_on: []
---

# Proposal: meeting-stage-buttons-and-cost

## Summary

The guarded meeting state machine and the meeting cost calculation exist on the server, but no button calls them, so the stage is typed as a plain field and the cost is never stamped. This change adds stage buttons to the meeting page and removes the stage from the create form.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pla-15, move a meeting through its stages, from draft to convened, in progress and closed

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/MeetingService.php:59-66 TRANSITIONS draft>scheduled>opened>paused/adjourned>closed with chair gates, via POST /api/meetings/{id}/lifecycle (appinfo/routes.php:145), which no frontend calls (grep '/lifecycle' under src/: none); MeetingDetail Outcome widget locks lifecycle (editable:false, src/manifest.json:571); lifecycle is a required plain field on the create form; lib/Service/AgendaService.php:165-170 sets 'opened' as a side effect of the API-only agenda publish

Matrix note, verbatim:

> The guarded state machine exists server-side but no button calls it, and the detail page locks the field. In practice the stage is typed in as a plain field on the create form, bypassing the guards (I did not check whether the index's edit form also exposes it).

No competitor cell is rated `yes`.

### pla-10, see what a meeting costs in the time of the people attending

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/MeetingCostService.php:82 computeCost() (elapsed hours x attendees x body hourlyRate at lib/Settings/decidesk_register.json:630), :109 calculateForMeeting(); stamped only in lib/Service/MeetingService.php:199-212 on the 'close' transition, reached through POST /api/meetings/{id}/lifecycle (appinfo/routes.php:145) which no frontend calls (grep '/lifecycle' under src/); src/components/tabs/GovernanceBodyEfficiencyTab.vue cost trend reads Meeting.meetingCost, per-item breakdown computed client-side in src/utils/meetingAnalytics.js:225 from actualDuration

Matrix note, verbatim:

> The cost formula exists and the body's efficiency widget charts cost. The server only stamps a meeting's cost when it is closed through an API route no screen calls, so the trend stays empty unless someone types the cost in; the per-item breakdown works when actual durations were recorded.

No competitor cell is rated `yes`.

## Why

Both rows are in the core area (planning). Closing a meeting through the guarded transition is also what stamps its cost.

## What is built today

- MeetingService TRANSITIONS draft, scheduled, opened, paused, adjourned, closed with chair gates behind POST /api/meetings/{id}/lifecycle.
- MeetingCostService computes the cost on close; the body efficiency widget charts Meeting.meetingCost.

## What changes

1. A stage widget on MeetingDetail shows the current stage and the transitions GET-able for the caller, and posts the chosen one to /api/meetings/{id}/lifecycle.
2. The create form no longer offers lifecycle; a new meeting starts as draft (or the type's initialLifecycle).
3. Closing through the button stamps meetingCost, which the Outcome widget and the body efficiency widget show.

## Out of scope

- Publishing the agenda on convening (agenda-publish-and-invite-members).
