# Design: meeting-stage-buttons-and-cost

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| State machine | `lib/Service/MeetingService.php:59-66` TRANSITIONS, `:199-212` cost stamp on close |
| Route | `appinfo/routes.php:145` meeting#lifecycle, no src caller |
| Cost | `lib/Service/MeetingCostService.php:82` computeCost(), `:109` calculateForMeeting() |
| Page | `src/manifest.json` MeetingDetail Outcome widget, lifecycle editable:false |

## Approach

1. A GET of allowed transitions per caller (MeetingController, reusing MeetingRoleGate) and a `src/components/tabs/MeetingStageTab.vue` widget with one button per allowed transition.
2. Manifest: remove lifecycle from the meeting create form fields; the pre-save default comes from meeting-rules-from-body-and-type or draft.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: MeetingController transitions endpoint answers only the chair's transitions (red before).
- vitest: the stage widget posts the transition and shows the new stage.

## As built (2026-09-29, read at development `ecabe45b`)

- `GET /api/meetings/{id}/transitions` (MeetingController::transitions) answers `{ lifecycle, actions }`. The actions come from `MeetingService::availableActionsFor()`, which runs the state machine, the domain rules and the chair-only gate that `transition()` runs; quorum is left to the step itself, whose refusal says why. A caller who is not chair or secretary of the meeting (MeetingRoleGate, NC-admin fallback) gets the stage and no actions.
- `POST /api/meetings/{id}/lifecycle` now refuses with 403 a caller who is not chair or secretary. Before, anyone with write access on the object in OpenRegister could move the stage, which the page would not have shown. The MCP tool path (McpMeetingTools) is unchanged.
- The create form: `excludeFields: ["lifecycle"]` on the Meetings index (create and edit dialog), and register fragment 99 gives `Meeting.lifecycle` the default `draft`, which OpenRegister applies on create. `lifecycle` stays in `required` because a fragment cannot remove a list entry (lists are concatenated). The type's own initial stage belongs to meeting-rules-from-body-and-type.
- Defect found on the way: MeetingCostService counted participants with a `meeting` filter, a property Participant does not declare, so the cost used the wrong number of people. It now counts the members of the meeting's body (declared `governanceBody`) marked present, or the whole roster when nobody's attendance was taken. Per-meeting attendance is meeting-attendance-per-meeting.
- The stage widget (`MeetingStageTab`, between Outcome and Agenda) shows the stage, one button per offered step, and the cost once recorded.
