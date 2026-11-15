# Design: planning-cycle-generate-from-template

Read at decidiq development `ace9aa2a`.

## What exists

| Piece | Where |
|---|---|
| Schemas | `lib/Settings/register.d/82-planning-cycle-in-plain-words.json` |
| Pages | `src/manifest.d/pc-cyclus.json` PlanningCycles, PlanningCycleDetail, PlanningCycleStepDetail |
| Services | none: grep PlanningCycle in lib/Service finds nothing |

## Approach

1. `lib/Service/PlanningCycleGenerator.php` (pure date resolution) and `lib/Listener/PlanningCycleCreatedListener.php` on ObjectCreatedEvent for planning-cycle; skips when steps already exist.
2. `src/components/tabs/PlanningCycleStepsTab.vue` on PlanningCycleDetail.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that carries the behaviour.

## Tests

- PlanningCycleGeneratorTest with the real template shape; each generated step validated against the planning-cycle-step schema.
- Listener wiring test from the registrar.
- vitest for the widget.
