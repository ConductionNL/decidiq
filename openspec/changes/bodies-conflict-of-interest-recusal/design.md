# Design: bodies-conflict-of-interest-recusal

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Service | `lib/Service/ConflictOfInterestService.php:126` declare(), `:292` recordAction(), `:380` getActiveConflicts() |
| Controller | `lib/Controller/ConflictOfInterestController.php`, routes `appinfo/routes.php:192-194` |
| Vote path | `lib/Service/VoteCastGuard.php`, `lib/Service/VoteCastingService.php`, `lib/Service/VotingRoundGuard.php`: none reads a conflict |

## Approach

1. A dialog `src/dialogs/ConflictDeclareDialog.vue` and a small list widget on the two detail pages.
2. VoteCastGuard gets ConflictOfInterestService injected and checks getActiveConflicts() for the caster and the round's motion or agenda item before a ballot is written.
3. The eligible-voter count used by the round excludes recused members.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: VoteCastGuardTest with the real ConflictOfInterestService signature: a recused caster is refused (red before).
- vitest: the dialog posts the declaration payload.
