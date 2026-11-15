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

## As built (corrections at fe0d28de)

- A declaration names one subject in `agendaItem`: the agenda item on its page, the motion (a Decision) on a motion page. The schema already describes the field as "agenda item / resolution". `ConflictOfInterestAuthorizationGuard` now finds the meeting through a Decision when no agenda item has the id, so a chair can declare on a motion too.
- The member does not know her Membership id: `POST /api/conflicts` without `membershipId` records it for the logged-in member (`membershipForUser`: UID, Participant, Membership). `recuseFromVote: true` stores `actionTaken: recused-from-vote` at once; the chair can still record another action.
- The vote check is a new `RecusalGuard`, injected into `VoteCastingService` (not VoteCastGuard, which VoteCastingService builds with `new`). It refuses the ballot when the member it counts for (the delegator on a proxy vote) has `recused-from-vote` or `recused-from-discussion` on the round's motion, amendment, an amendment's parent motion, or the agenda item any of them sits under. It matches `boardMember` on the Membership and on the Participant (rows written before the Membership move).
- The eligible count: the VotingRoundPanel's "Cast: x / y" now uses participants minus distinct recused members on the motion and its agenda item. The server's result base counts ballots cast, so a recused member who cannot cast is already out of it.
- Not built: the AgendaBuilder's COI badge still counts `COI:` notes (the older notes mechanism); it does not read these declarations.
