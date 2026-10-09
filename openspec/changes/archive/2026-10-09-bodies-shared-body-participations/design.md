# Design: bodies-shared-body-participations

Read at decidiq development `ace9aa2a`.

## What exists

| Piece | Where |
|---|---|
| Schema | `lib/Settings/register.d/56-shared-governance-bodies.json` BodyParticipation, Membership.onBehalfOf |
| Body page | `src/manifest.json` GovernanceBodyDetail object lists body-participating-orgs, body-shared-participations |
| Members widget | `src/components/tabs/GovernanceBodyMembersTab.vue`, `src/modals/MemberAddDialog.vue` |

## Approach

1. `src/components/tabs/BodyParticipationsTab.vue` plus `src/dialogs/BodyParticipationDialog.vue` writing through the object store; the old object lists are replaced by the widget.
2. Filled seats computed client side from memberships (src/utils/bodyParticipations.js).
3. MemberAddDialog gains an onBehalfOf select when the body is a shared body.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that carries the behaviour.

## Tests

- vitest: payload validated with tests/vitest/helpers/registerSchema.js against body-participation; filled-seat count; widget hidden for other body types.
