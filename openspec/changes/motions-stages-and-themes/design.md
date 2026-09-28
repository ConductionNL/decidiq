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
