# Design: routes-absence-substitute

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Resolver | `lib/Service/ApprovalActorResolver.php:80,299` |
| Lapse | `lib/Service/StageLapsePolicy.php:305`, `lib/Service/ApprovalStageLapseService.php:168` |
| Delegation | `lib/Service/NotificationPreferenceService.php:53-55` delegate, delegationFrom, delegationUntil |

## Approach

1. Reuse delegate, delegationFrom and delegationUntil as the absence period (they already exist per user) and label them as such in settings.
2. ApprovalActorResolver reads them when resolving a step's actor.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: ApprovalActorResolver returns the substitute during the period and the original after (red before).
