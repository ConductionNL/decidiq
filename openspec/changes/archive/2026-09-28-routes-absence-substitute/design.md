# Design: routes-absence-substitute

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Resolver | `lib/Service/ApprovalActorResolver.php:80,299` |
| Lapse | `lib/Service/StageLapsePolicy.php:305`, `lib/Service/ApprovalStageLapseService.php:168` |
| Delegation | `lib/Service/NotificationPreferenceService.php:53-55` delegate, delegationFrom, delegationUntil |

## Design correction at build time

The first draft sent new steps to the substitute outright. The code has a better fit: a stage already carries `substituteActor` (fragment 81), which the lapse sweep fills part way through a window, and both people may act. Absence now fills the same field at activation. Building it showed that `lib/Service/ApprovalStageGuard.php` never honoured `substituteActor`: only the assignee, or a delegate with a mandate reference, could act, so a substitute the lapse sweep asked was refused by the server (the Parafering card offered the buttons from `src/integrations/approvalChainLink.js:192`). REQ-RAS-002 fixes that.

## Approach

1. Reuse delegate, delegationFrom and delegationUntil as the absence period (they already exist per user) and label them as such in settings.
2. `ApprovalStageActivator::activationPatch()` asks `NotificationPreferenceService::getActiveDelegate()` for the stage's actor on the activation date and, when a delegate is active, writes `substituteActor` and `substituteAskedAt`. Notifications already reach the delegate through the preference service's delegation expansion.
3. `ApprovalStageGuard` accepts the stage's `substituteActor`.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: ApprovalActorResolver returns the substitute during the period and the original after (red before).
