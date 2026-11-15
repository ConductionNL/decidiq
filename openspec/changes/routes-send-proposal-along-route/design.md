# Design: routes-send-proposal-along-route

Read at decidiq development `ace9aa2a`.

## What exists

| Piece | Where |
|---|---|
| Engine | `lib/Service/ApprovalRouteService.php:362` instantiate(), `lib/Controller/ApprovalRouteController.php:84` |
| Routes | `lib/Settings/register.d/69-approval-routes.json` ApprovalRoute (subjectType, isDefault, active, steps) |
| Widget | `src/integrations/registerApprovalChainLeaf.js`, CnApprovalChainWidget |
| Clearance | GET /api/approval-routes/clearance (SubjectClearanceService) |

## Approach

1. `src/dialogs/SendForApprovalDialog.vue` and a decision page widget in src/components/tabs/DecisionApprovalTab.vue reusing the chain leaf.
2. Agenda placement reads the clearance endpoint before adding a decision as agenda item.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that carries the behaviour.

## Tests

- vitest: dialog lists active decision routes, posts the right body; placement refused while not cleared.
- ApprovalRouteControllerTest: a caller who cannot reach the decision gets 403.
