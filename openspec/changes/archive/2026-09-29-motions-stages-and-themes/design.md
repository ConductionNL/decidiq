# Design: motions-stages-and-themes

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Motion transitions | `lib/Lifecycle/MotionLifecycleTransitioner.php` MOTION_TRANSITIONS |
| Decision widget | `src/components/tabs/DecisionLifecycleTab.vue` |
| Motions index | `src/manifest.json:853` |

## Approach

1. Reuse DecisionLifecycleTab with a motion mode that targets /api/motions/{id}/transition, mounted on MotionDetail.
2. Register fragment: Theme schema, Decision.themes; manifest columns and filters.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- vitest: the motion stage widget posts withdraw to the motion route (red before).
- PHPUnit: Theme fragment validates; a motion with themes validates.

## Corrections at build (29 Sep, decidiq development f3103afb)

- The stage widget is its own `MotionStageTab.vue` (the shape of `MeetingStageTab.vue`), not a motion mode of DecisionLifecycleTab: the decision widget speaks the decision action vocabulary (propose, deliberate, ...) against another route, while the motion route takes `newState` plus `outcome`. The server decides the buttons: `MotionStages::forCaller()` behind `GET /api/motions/{id}/transitions`.
- Withdraw by the submitter: `MotionController::transition()` accepted chair or secretary only; it now also accepts `newState: withdrawn` from the motion's owner (`MotionStages::mayWithdraw()`).
- The theme schema slug is `motion-theme`, not `theme`: opencatalogi owns `theme` on the shared OpenRegister (hydra gate-106).
- The stage and result filters are the facets of `lifecycle` and `outcome` (made facetable in fragment 102) in the Motions list sidebar, not quick filters.
