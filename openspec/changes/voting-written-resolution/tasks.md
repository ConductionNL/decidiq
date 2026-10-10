# Tasks: voting-written-resolution

### Task 1: Declare the written round fields

- **spec_ref**: `openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing`
- **files**: `lib/Settings/register.d/NN-written-resolution.json` (new), `lib/Settings/profiles/corporate.json` (seed), `tests/Unit/RegisterJsonTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `voting-round` declares `procedure` (default `in-meeting`), `governanceBody`, `electorate`, `electorateUserIds`, `writtenRule` (default `unanimous`) and `closedReason`, each with a `title`
  - GIVEN `vote` WHEN read THEN `value` allows `objection` and a `membership` relation is declared
  - GIVEN the corporate example set WHEN imported THEN the written decision, its round and three answers exist, and the three seeded board persons carry a user id
  - Verification: `RegisterJsonTest` red first, then green
- [ ] Implement
- [ ] Test

### Task 2: Open a written round on a decision

- **spec_ref**: `openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing`
- **files**: `lib/Service/VotingOpenRequestParser.php`, `lib/Service/VotingRoundOpener.php`, `lib/Service/VotingRoundPreflight.php`, `lib/Service/WrittenElectorateResolver.php` (new), `lib/Controller/VotingController.php` (`open()` guard branch), `tests/Unit/Service/WrittenElectorateResolverTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a signatory of the body and a decision without a meeting in `proposed` WHEN they open a written round THEN it stores the body, the deadline and the active members except observers and guests
  - GIVEN a caller outside the signatory scope WHEN they open THEN 403
  - GIVEN a member without a user id, a past deadline, a meeting id, or a decision with a meeting WHEN opened THEN 400 with the reason and no round
  - Verification: PHPUnit, the electorate cases written red first
- [ ] Implement
- [ ] Test

### Task 3: Answer a written round

- **spec_ref**: `openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page`
- **files**: `lib/Controller/VotingController.php` (`cast()`), `lib/Service/VoteCastGuard.php`, `lib/Service/VoteCastingService.php`, `lib/Service/VoteBallotFactory.php`, `tests/Unit/Service/WrittenVoteCastTest.php` (new), `tests/newman/`
- **acceptance_criteria**:
  - GIVEN an entitled member WHEN they answer for THEN a vote related to their membership is stored
  - GIVEN they answer again with against WHEN the round is read THEN it holds one answer from them, against
  - GIVEN a user outside the electorate WHEN they answer THEN 403 and nothing is stored, even though the round has no meeting
  - GIVEN `objection` on an in-meeting round WHEN posted THEN 400
  - Verification: PHPUnit red-then-green; a Newman request proving the outsider refusal on a live instance
- [ ] Implement
- [ ] Test

### Task 4: Count against the whole electorate and close early

- **spec_ref**: `openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-003-the-result-is-counted-against-the-whole-electorate`
- **files**: `lib/Service/VotingResultCalculator.php`, `lib/Service/VotingRoundResults.php`, `lib/Service/VoteCastingService.php` (settle check after a save), `lib/Service/VotingRoundCloser.php` (`subjectOf()` learns `decision`, `decisionDate`), `tests/Unit/Service/VotingServiceTallyMatrixTest.php`
- **acceptance_criteria**:
  - GIVEN the three scenarios of REQ-WRS-003 WHEN counted THEN rejected, adopted and rejected with reason objection
  - GIVEN the last missing for answer WHEN saved THEN the round closes with `all-answered` and the decision is decided with the last answer's time as its date (REQ-WRS-004)
  - GIVEN a first against answer under `unanimous` WHEN saved THEN the round closes with `outcome-settled`
  - GIVEN a decision with an active vote stage for the body WHEN the round closes THEN the stage is decided with the same outcome
  - Verification: tally matrix cases red first; one close-path test per reason
- [ ] Implement
- [ ] Test

### Task 5: Close at the deadline

- **spec_ref**: `openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline`
- **files**: `lib/BackgroundJob/WrittenResolutionDeadlineJob.php` (new), `appinfo/info.xml`, `tests/Unit/BackgroundJob/WrittenResolutionDeadlineJobTest.php` (new)
- **acceptance_criteria**:
  - GIVEN an open written round past its deadline WHEN the job runs THEN it closes with reason `deadline` and silence counts as not agreeing
  - GIVEN the job runs twice WHEN the round is read THEN it was closed once
  - GIVEN an in-meeting round with a deadline WHEN the job runs THEN it is untouched
  - Verification: PHPUnit red-then-green; the job is listed in `info.xml` and a test asserts it
- [ ] Implement
- [ ] Test

### Task 6: The written resolution widget and its notification

- **spec_ref**: `openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure`
- **files**: `src/components/tabs/WrittenResolutionTab.vue` (new), `src/modals/WrittenResolutionModal.vue` (new), `src/registry.js`, `src/manifest.json` (`DecisionDetail` widget, layout cell, slot), `lib/Settings/register.d/NN-written-resolution.json` (notification), `tests/e2e/workflows/written-resolution.spec.ts` (new)
- **acceptance_criteria**:
  - GIVEN a signatory on a decision without a meeting WHEN the page renders THEN "Decide in writing" opens the modal
  - GIVEN an entitled member WHEN they open the page THEN they see the four answers and the deadline
  - GIVEN a closed round WHEN anyone who can read the decision opens it THEN the record lists every member, answer and time, and the close reason (REQ-WRS-005)
  - GIVEN the round opens WHEN the notifications are read THEN each entitled member has one linking to the decision (REQ-WRS-006); if the field recipient cannot take a list, the fallback in design D6 is used and an openregister issue is linked in the PR
  - Verification: Playwright with three seeded users: secretary opens, two answer for, third answers for, the page shows adopted; red first (no widget)
- [ ] Implement
- [ ] Test
