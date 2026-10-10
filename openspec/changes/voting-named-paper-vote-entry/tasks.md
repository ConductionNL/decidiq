# Tasks: voting-named-paper-vote-entry

### Task 1: Declare the roll call method and the recorded vote fields

- **spec_ref**: `openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-001-a-chair-opens-a-roll-call-round-whose-votes-are-recorded-by-name`
- **files**: `lib/Settings/register.d/NN-named-paper-vote-entry.json` (new), `lib/Settings/profiles/municipality.json` (seed), `tests/Unit/RegisterJsonTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `voting-round.votingMethod` contains `roll-call` and still contains the four existing values
  - GIVEN the `vote` schema WHEN read THEN it declares `entryMode` (enum `cast`, `recorded`, default `cast`) and `recordedBy`, each with a `title`
  - GIVEN the municipality example set WHEN imported THEN the roll-call round and its three recorded votes exist
  - Verification: `RegisterJsonTest` case written red first, then green
- [ ] Implement
- [ ] Test

### Task 2: Record named votes on a roll call round

- **spec_ref**: `openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice`
- **files**: `lib/Controller/VotingController.php` (`recordVotes()`), `appinfo/routes.php` (`voting#recordVotes`), `lib/Service/VotingService.php`, `lib/Service/RecordedVoteWriter.php` (new), `tests/Unit/Service/RecordedVoteWriterTest.php` (new), `tests/newman/`
- **acceptance_criteria**:
  - GIVEN an open roll-call round and a list of three members WHEN the meeting secretary posts it THEN three votes exist with `entryMode: recorded` and `recordedBy` set
  - GIVEN the same list posted twice with one value changed WHEN the round is read THEN it still holds three votes, one of them changed
  - GIVEN the round then closes WHEN its totals are read THEN they match the recorded votes (REQ-NPV-004)
  - Verification: PHPUnit red-then-green; a Newman request that records and closes on a seeded round
- [ ] Implement
- [ ] Test

### Task 3: Refuse a wrong list as a whole

- **spec_ref**: `openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-003-the-recorded-list-is-refused-as-a-whole-when-any-entry-is-wrong`
- **files**: `lib/Service/RecordedVoteWriter.php`, `tests/Unit/Service/RecordedVoteWriterTest.php`
- **acceptance_criteria**:
  - GIVEN one unknown participant in a list of four WHEN posted THEN 400 names it and zero votes are written
  - GIVEN a member who cast their own vote WHEN the list records them THEN 409 names them and their vote is unchanged
  - GIVEN a secret round, a closed round or a non roll-call round WHEN posted THEN the request is refused and nothing is written
  - GIVEN a caller without a presiding role in the round's meeting WHEN posted THEN 403
  - Verification: one PHPUnit case per refusal, each asserting the write count is zero
- [ ] Implement
- [ ] Test

### Task 4: Closing keeps a show-of-hands result

- **spec_ref**: `openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-005-closing-a-show-of-hands-round-keeps-the-totals-the-chair-entered`
- **files**: `lib/Service/VotingRoundResults.php` (`tally()`), `tests/Unit/Service/VotingServiceTallyMatrixTest.php`
- **acceptance_criteria**:
  - GIVEN a show-of-hands round with saved totals 14, 9, 2 WHEN it closes THEN the totals are unchanged and the result is `adopted`
  - GIVEN a for-against-abstain round WHEN it closes THEN it is still counted from its votes
  - Verification: the show-of-hands close case is written first and fails on today's code (totals become zero, result `invalid`), then passes
- [ ] Implement
- [ ] Test

### Task 5: The entry sheet on the voting round panel

- **spec_ref**: `openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-001-a-chair-opens-a-roll-call-round-whose-votes-are-recorded-by-name`
- **files**: `src/components/VotingRoundPanel.vue` (method option, hide cast buttons, open the modal), `src/modals/RecordedVotesModal.vue` (new), `tests/e2e/spec-coverage/voting-rules.spec.ts`
- **acceptance_criteria**:
  - GIVEN a presiding user on an open roll-call round WHEN they open "Record votes by name" THEN each active participant is listed with name and party
  - GIVEN a party control set to for WHEN saved THEN every member of that party has a `for` vote
  - GIVEN a refused list WHEN the server answers 400 or 409 THEN the modal names the members concerned and keeps the entries
  - GIVEN a member WHEN they view the round THEN no vote buttons show
  - Verification: Playwright on a seeded meeting, red first (no roll-call option), then green; every `NcSelect` has an `inputLabel`
- [ ] Implement
- [ ] Test
