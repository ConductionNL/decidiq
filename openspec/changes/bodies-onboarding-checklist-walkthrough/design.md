# Design: bodies-onboarding-checklist-walkthrough

Read at decidiq development `ace9aa2a`.

## What exists

| Piece | Where |
|---|---|
| Schemas | `lib/Settings/register.d/88-member-onboarding-in-plain-words.json` MemberOnboarding, MemberOffboarding (steps[].stepType, status, lifecycle) |
| Pages | `src/manifest.d/member-onboarding.json` index and detail pages, no step actions |
| Membership write path | `src/components/tabs/useRelationStore.js` buildMembershipPayload() |
| Services | none: grep onboarding in lib/Service and lib/Listener finds nothing |

## Approach

1. New `lib/Service/OnboardingChecklistService.php` with completeStep(record, index, outcome) that updates the step, runs the membership side effect for installation and end steps, and sets lifecycle.
2. Route PUT /api/onboarding/{kind}/{id}/steps/{index} with a secretary, chair or admin guard (GovernanceScopeGuard).
3. Widget `src/components/tabs/OnboardingChecklistTab.vue` registered on both detail pages.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that carries the behaviour.

## Tests

- OnboardingChecklistServiceTest: step done, skip, membership created and end-dated, lifecycle moves; payload validated against the real membership schema.
- Controller test: a plain member gets 403.
- vitest for the widget.
