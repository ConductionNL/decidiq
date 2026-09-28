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
