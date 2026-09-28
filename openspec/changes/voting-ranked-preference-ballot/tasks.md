# Tasks: voting-ranked-preference-ballot

### Task 1: Declare options, ranking and the ranked result fields

- **spec_ref**: `openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice`
- **files**: `lib/Settings/register.d/NN-ranked-preference-ballot.json` (new), `lib/Settings/profiles/association.json` (seed), `tests/Unit/RegisterJsonTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `voting-round` declares `options`, `rankingResult` and `winningOption`, and `vote` declares `ranking`, each with a `title`
  - GIVEN `vote.value` WHEN read THEN its enum is `for`, `against`, `abstain`, `ranked`
  - GIVEN the association example set WHEN imported THEN the ranked round and its three ballots exist, and recomputing the seed ballots gives the seeded points
  - Verification: `RegisterJsonTest` red first, then green
- [x] Implement (fragment `93-ranked-preference-ballot.json`; the association example set does not seed the ranked round yet)
- [x] Test (`RankedPreferenceBallotRegisterTest`, not `RegisterJsonTest`; red first)

### Task 2: Open a ranked round with options

- **spec_ref**: `openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice`
- **files**: `lib/Service/VotingOpenRequestParser.php`, `lib/Service/VotingRoundPreflight.php` (`buildRoundPayload()`), `tests/Unit/Service/VotingOpenRequestParserTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a ranked request with three options WHEN parsed and opened THEN the round carries the three options
  - GIVEN one option, twenty-one options or a duplicate key WHEN parsed THEN 400 with the reason
  - GIVEN `options` on a for-against-abstain request, or `chair-decides` on a ranked one WHEN parsed THEN 400
  - Verification: PHPUnit, one case per refusal, written red first
- [x] Implement (the parser passes `options` through; `RankedBallotRules` refuses the bad cases in `VotingRoundOpener`, so the 400 comes from the opener)
- [x] Test (`VotingServiceRankedBallotTest`, red first)

### Task 3: Cast a ranked ballot

- **spec_ref**: `openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting`
- **files**: `lib/Controller/VotingController.php` (`cast()`), `lib/Service/VoteCastingService.php`, `lib/Service/VoteBallotFactory.php`, `tests/Unit/Service/VoteCastingServiceRankedTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a ranked round WHEN a member posts a full ranking THEN one vote exists with `value: ranked` and the ranking
  - GIVEN a partial or duplicated ranking WHEN posted THEN 400 and no vote
  - GIVEN a second full ranking by the same member WHEN posted THEN the round still holds one ballot for that member
  - GIVEN a secret ranked round WHEN a ballot is stored THEN it carries no participant relation
  - Verification: PHPUnit red-then-green
- [x] Implement
- [x] Test (`VotingServiceRankedBallotTest`, red first)

### Task 4: Count with Borda on close

- **spec_ref**: `openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner`
- **files**: `lib/Service/BordaCount.php` (new), `lib/Service/VotingRoundResults.php` (`tally()`), `tests/Unit/Service/BordaCountTest.php` (new), `tests/Unit/Service/VotingServiceTallyMatrixTest.php`
- **acceptance_criteria**:
  - GIVEN the three seed ballots WHEN counted THEN 5, 3 and 1 points, winner `renoveren`, result `adopted`
  - GIVEN two ballots that tie at the top WHEN counted THEN result `tied` and no winner
  - GIVEN no ballots WHEN counted THEN result `invalid`
  - GIVEN a for-against-abstain round WHEN closed THEN its tally is unchanged
  - Verification: `BordaCountTest` on plain arrays; one tally matrix case for the ranked branch, red first
- [x] Implement
- [x] Test (`BordaCountTest` and `VotingServiceRankedBallotTest`, red first)

### Task 5: Revote between tied options

- **spec_ref**: `openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-rpb-001-a-tie-in-a-ranked-round-follows-the-rounds-tie-break-rule`
- **files**: `lib/Service/VotingRoundPreflight.php` (`assertRevoteAllowed()`), `src/components/VotingRoundPanel.vue` (`startRevote()`), `tests/Unit/Service/VotingServiceTallyMatrixTest.php`
- **acceptance_criteria**:
  - GIVEN a tied ranked round under `revote` WHEN the chair starts the revote THEN the new round's options are exactly the tied ones
  - GIVEN the revote ties again WHEN closed THEN it stays `tied` and no further revote is allowed
  - Verification: PHPUnit on the preflight; the panel path is covered by the Playwright spec in task 6
- [x] Implement (server side: the revote takes the tied options from the tied round, whatever the request sends)
- [x] Test (`VotingServiceRankedBallotTest::testARevoteOffersTheTiedOptionsOnly`)

### Task 6: Ballot, option editor, results table and vote lists

- **spec_ref**: `openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-004-ranked-results-are-displayed-as-a-ranking-table`
- **files**: `src/components/VotingRoundPanel.vue`, `src/components/RankedBallot.vue` (new), `src/components/RankedResultsCard.vue` (new), `src/components/tabs/MotionVotesTab.vue`, `src/components/tabs/DecisionVotingTab.vue`, `tests/e2e/spec-coverage/voting-rules.spec.ts`
- **acceptance_criteria**:
  - GIVEN the open dialog WHEN "Ranked preference (Borda count)" is picked THEN the option editor shows and accepts Person picks
  - GIVEN an open ranked round WHEN a member ranks with the keyboard only and submits THEN the vote is recorded
  - GIVEN a closed ranked round WHEN the motion page opens THEN the ranking table shows rank, option and points and the winner badge (REQ-PRF-004); a secret round shows points only (REQ-PRF-005)
  - GIVEN a ranked ballot in the "Votes" widget WHEN listed THEN it reads as its ordered options (REQ-RPB-002)
  - Verification: Playwright red first (no ranked option in the dialog), then green, including a keyboard-only ranking; vitest for the ballot's move controls
- [ ] Implement (partly, #1419: method, option editor with labels, keyboard ballot, results table and the ranking in both vote lists are built; Person picks are not)
- [ ] Test (vitest for the ranking text only; the Playwright spec is still to write)
