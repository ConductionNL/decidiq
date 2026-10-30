# Tasks: voting-per-body-chair-and-staff

### Task 1: A body declares whether its chair votes; staff never vote
- **spec_ref**: `openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote`
- **files**: `lib/Settings/register.d/124-chair-has-vote.json`, `lib/Service/VoterEligibility.php`, `lib/Service/PublicVotingRecords.php`, `lib/Settings/profiles/municipality.json`, `lib/Settings/example-sets/zuiddrecht.json`, `appinfo/info.xml`, `tests/Unit/Portal/PortalPublicRecordsTest.php`
- **acceptance_criteria**:
  - GIVEN a council with `chairHasVote` false WHEN the member list is read THEN neither its chair nor its secretary is listed and neither has a record
  - GIVEN a committee that does not set `chairHasVote` WHEN the member list is read THEN its chair is listed
  - GIVEN the shipped example set WHEN every voting councillor's record is read THEN each holds one named vote per public round and the votes add up to each round's totals
- [x] Implement
- [x] Test

### Task 2: A dangling reference skips one object, not the whole list
- **spec_ref**: `openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-002-a-dangling-reference-skips-one-object-not-the-whole-list`
- **files**: `lib/Service/VoteContextReader.php`, `tests/Unit/Portal/PortalPublicRecordsTest.php`
- **acceptance_criteria**:
  - GIVEN a round whose stage names a decision that does not exist WHEN a member's record is read THEN that round is skipped and her other votes show
- [x] Implement
- [x] Test (the test's OpenRegister fake now throws for a missing object, as OpenRegister does)
