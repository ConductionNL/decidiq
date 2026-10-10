# Design: voting-named-paper-vote-entry

Kind: code. One enum value and two properties in a register fragment, one write endpoint, one service method, one modal. The JSON part is incidental to the code (ADR-032).

Read at development `4d7430ff`.

## What is there today

- The open dialog offers three methods: for, against, abstain; show of hands; weighted (`src/components/VotingRoundPanel.vue:46` to `:55`). `VotingRound.votingMethod` allows `for-against-abstain`, `ranked-choice`, `weighted` and `show-of-hands` (`lib/Settings/decidesk_register.json:1359`).
- A show-of-hands round shows three number inputs to the chair (`VotingRoundPanel.vue:142` to `:170`) and posts them to `POST /api/voting-rounds/{id}/tally` (`:859`). `VotingController::tally()` (`lib/Controller/VotingController.php:327`) passes them to `VotingService::saveShowOfHandsTally()` (`lib/Service/VotingService.php:324`), which calls `VotingRoundResults::saveShowOfHands()` (`lib/Service/VotingRoundResults.php:173`). That refuses any method other than `show-of-hands` (`:179`), checks the sum against active attendance (`assertWithinAttendance()`, `:321`) and stores only the three totals and the computed result on the round.
- A member's own vote goes through `POST /api/voting-rounds/{id}/cast` to `VoteCastingService::castVote()` (`lib/Service/VoteCastingService.php:127`). `VoteBallotFactory::buildVote()` (`lib/Service/VoteBallotFactory.php:77`) builds the `vote` object: `value`, `weight` 1, `isProxy`, `castAt` now, `castAs`, and relations to the round and, on an open round, to the participant (`voteRelations()`, `:151`). Its slug `vote-{round}-{participant}` (`idempotencySlug()`, `:202`) makes a second cast replace the first.
- Closing counts `vote` objects. `VotingService::closeRound()` (`VotingService.php:369`) calls `tallyResults()`, which is `VotingRoundResults::tally()` (`VotingRoundResults.php:124`): it loads the round's votes (`ballotsInRound()`, `:212`), sums them by weight (`countVotes()`, `:236`) and writes the totals and result back (`persistOutcome()`, `:268`). `VotingRoundCloser::close()` (`lib/Service/VotingRoundCloser.php:93`) then moves the subject and resolves the decision stage.
- The `vote` schema (`decidesk_register.json:1640`, plus `authorizationRef` from `lib/Settings/register.d/63-member-proxy-authorization.json:240`) has no field that says who wrote the row or whether the member cast it.
- `MotionVotesTab` (`src/components/tabs/MotionVotesTab.vue:165` to `:194`) already lists each vote of a motion with the caster's display name, value and time. Recorded votes show there without change.
- Meeting members come from `ParticipantResolver::resolveMeetingParticipants()` (`lib/Service/ParticipantResolver.php:141`), and each participant carries `party` and `leftAt` (`decidesk_register.json`, `Participant`).

## Decisions

### D1. A method, not a mode on show of hands

`roll-call` is a new `VotingRound.votingMethod` value. `decision-methods` requires vote sub-variants to be `votingMethod` values, and a round has to say up front whether members cast or the clerk records, because the panel shows different controls for each. Show of hands stays as it is for votes where names are not kept.

### D2. One vote object per member, written by the clerk

`POST /api/voting-rounds/{id}/recorded-votes`, route `voting#recordVotes`, `#[NoAdminRequired]`. Body:

```json
{
  "heldAt": "2026-03-12T21:40:00+01:00",
  "entries": [
    { "participantId": "00000000-0000-0000-0000-000000000001", "value": "for" },
    { "participantId": "00000000-0000-0000-0000-000000000002", "value": "against" }
  ]
}
```

The guard is `VotingRoundGuard::requireChairOrSecretary()` with the meeting resolved from the round, the same form REQ-VCR-003 gives `tally()`.

A new `VotingService::recordNamedVotes()` delegates to a new `RecordedVoteWriter` in `lib/Service/`. It validates the whole list before it writes anything:

- the round exists, is open, and has `votingMethod` `roll-call`;
- the round is not secret;
- every `participantId` is an active participant of the round's meeting (`resolveMeetingParticipants()`, `leftAt` empty);
- every `value` is `for`, `against` or `abstain`;
- no participant appears twice;
- no entry would replace a vote with `entryMode` `cast`.

Any failure refuses the whole request with the offending ids. Then it builds each vote with `VoteBallotFactory::buildVote()`, so the slug and relations are the same as a cast vote, and sets `entryMode: recorded`, `recordedBy` (the caller's uid) and `castAt` from `heldAt` (now when absent). Re-posting the list replaces the recorded votes by slug, so a corrected sheet does not double count. A member left out of the list has no vote, which reads as "did not vote".

### D3. Closing counts the recorded votes

Nothing new is needed for a roll-call round: `tally()` already counts `vote` objects. The endpoint does not write totals on the round itself, so there is one source of truth.

### D4. Closing keeps a show-of-hands result

Reading the close path for D3 showed that it recounts `vote` objects for every method. A show-of-hands round has none, so `persistOutcome()` writes zero totals and the calculator returns `invalid` (`lib/Service/VotingResultCalculator.php`, `compute()`, the `$total === 0` branch) over the totals the chair saved. This was read, not run. The fix is one branch: `VotingRoundResults::tally()` returns the stored totals for a `show-of-hands` round instead of recounting. It is in this change because the matrix counts entering totals as the built half of this row, and that half does not survive a close.

### D5. The entry sheet

A modal, `src/modals/RecordedVotesModal.vue` (hydra modal isolation rule), opened from the panel by a "Record votes by name" button on an open `roll-call` round when the permissions answer has `canEnterTally`. It lists the meeting's active participants with name and party, and a for, against, abstain or not voted choice per row. A party control sets every member of one party at once, which is how iBabs describes per-fractie entry. The modal posts the list and shows the refusal ids inline. Member cast buttons are hidden on a `roll-call` round.

## Declarative or imperative

- The new enum value and the two `vote` properties are declared in a new register fragment `lib/Settings/register.d/NN-named-paper-vote-entry.json` (next free number at build time). Fragments union enum lists (see the note in `81-resolve-a-manager-and-declare-silence.json`), so the fragment adds `roll-call` without restating the other values.
- `vote.entryMode`: string, enum `cast`, `recorded`, default `cast`. The default keeps every stored vote meaning what it means today.
- `vote.recordedBy`: string, the Nextcloud uid of the clerk. Empty on cast votes.
- The write is imperative: it validates a list against the meeting's members and refuses as a whole, which no `x-openregister-*` extension expresses. It sits on the existing voting service seam and adds no parallel store.
- Totals stay computed by the existing tally, not declared as an aggregation, because the result rules (threshold, abstentions, tie break) live in `VotingResultCalculator` and are shared with every other method.

## Seed data

In `lib/Settings/profiles/municipality.json` (`x-openregister.seedData.objects`):

- a `voting-round` `stemming-hoofdelijk-parkeerbeleid`: `votingMethod: roll-call`, `isSecret: false`, opened and closed on 12 March 2026, `votesFor: 2`, `votesAgainst: 1`, `result: adopted`;
- three `vote` objects on that round: `stem-hoofdelijk-halsema` (`for`), `stem-hoofdelijk-bakker` (`for`), `stem-hoofdelijk-yilmaz` (`against`), each with `entryMode: recorded`, `recordedBy: griffier` and `castAt` of the vote.

## Risks

- The `participant` schema is deprecated in favour of Person and Membership (`decidesk_register.json`, `Participant` description). Votes still relate to participants everywhere, so this change follows the cast path. When the shim retires, recorded votes move with cast votes.
- A clerk entering a list after a member already voted electronically gets a refusal naming that member. The clerk then leaves that member out, which keeps the member's own vote.
