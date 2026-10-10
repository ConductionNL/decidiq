# Design: voting-results-by-faction-and-member

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Widget | `src/components/tabs/MotionVotesTab.vue:101,207` reads row.caster |
| Ballot | `lib/Service/VoteBallotFactory.php` voteRelations() |
| Faction | Membership.party, Membership.faction from bodies-membership-terms-contacts-and-factions |

## Found while building (design corrected)

- The widget fetched a round's ballots with a `votingRound` filter, but `VoteBallotFactory::buildVote()` never writes `votingRound` or `participant`: both live only in the ballot's `relations`. So the list was empty, not just the voter column. Ballots can only be found through `ObjectRelationFilter` (`_relations.relations`), which is a server-side query.
- The breakdown is therefore built on the server: `VoteBreakdownService::forRound()` behind `GET /api/voting-rounds/{id}/breakdown` (`VoteBreakdownController`). It reads the voter from the structured relations or from OpenRegister's flattened `relations.N.id` form, counts a proxy vote for the member it was cast for, takes the faction from `Participant.party`, and returns totals only for a secret round. The round is looked up with the user's own rights, so a round they cannot read is a 404.
- `src/utils/voteBreakdown.js` turns the answer into member rows and faction rows; `MotionVotesTab` shows both, `MeetingVotesTab` adds a per-faction column.

## Approach

1. A shared `src/utils/voteBreakdown.js` that groups ballots by the voter's faction; the three widgets use it.

## Declarative or imperative

UI only; no schema change.

## Tests

- vitest: voteBreakdown groups real ballot payloads (with relations) per faction and names voters (red before: voter column empty).
