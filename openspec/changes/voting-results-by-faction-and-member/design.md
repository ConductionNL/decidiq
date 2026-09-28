# Design: voting-results-by-faction-and-member

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Widget | `src/components/tabs/MotionVotesTab.vue:101,207` reads row.caster |
| Ballot | `lib/Service/VoteBallotFactory.php` voteRelations() |
| Faction | Membership.party, Membership.faction from bodies-membership-terms-contacts-and-factions |

## Approach

1. A shared `src/utils/voteBreakdown.js` that groups ballots by the voter's faction; the three widgets use it.

## Declarative or imperative

UI only; no schema change.

## Tests

- vitest: voteBreakdown groups real ballot payloads (with relations) per faction and names voters (red before: voter column empty).
