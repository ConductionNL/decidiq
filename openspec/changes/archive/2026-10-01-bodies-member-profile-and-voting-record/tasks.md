# Tasks: bodies-member-profile-and-voting-record

### Task 1: Declare portfolio and the body's publication choice

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-002-a-membership-carries-the-members-portfolio`
- **files**: `lib/Settings/register.d/NN-member-profile.json` (new), `lib/Settings/profiles/municipality.json`, `lib/Settings/profiles/corporate.json`, `tests/Unit/RegisterJsonTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `membership.portfolio` (array of strings) and `governance-body.publishVotingRecords` (boolean, default false) exist with a `title`
  - GIVEN the example sets WHEN imported THEN the executive board membership carries its portfolio, the council publishes voting records and the corporate board does not (REQ-MPR-005)
  - Verification: `RegisterJsonTest` red first, then green
- [x] Implement
- [x] Test

### Task 2: The profile page and its links

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page`
- **files**: `src/manifest.d/member-profile.json` (new), `src/components/tabs/PersonMembershipsTab.vue` (new), `src/registry.js`, `src/components/tabs/GovernanceBodyMembersTab.vue`, `src/manifest.json` (`ParticipantDetail` action), `tests/e2e/spec-coverage/governance-body.spec.ts`
- **acceptance_criteria**:
  - GIVEN a seeded person WHEN `/people/:id` opens THEN photo or initials, name, biography, current and earlier memberships with portfolio, and public outside positions show
  - GIVEN a body's members widget WHEN a name is clicked THEN the profile opens (REQ-MPR-003)
  - GIVEN the manifest WHEN validated THEN every custom widget has a slot and a layout cell
  - Verification: Playwright red first (no route), then green; `tests/validate-manifest.js`
  - Done as: the Playwright spec is `tests/e2e/member-profile-and-voting-record.spec.ts` (written, not run: no instance serves a branch); red-then-green on `tests/vitest/memberProfile.spec.js` and `tests/validate-manifest.js`. The photo or initials sit in the memberships widget because a data widget renders `image` as text. The example set uses the existing body `college-van-b-en-w-amsterdam`.
- [x] Implement
- [x] Test

### Task 3: The voting record read and widget

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record`
- **files**: `lib/Controller/VotingRecordController.php` (new), `appinfo/routes.php`, `lib/Service/VotingRecordService.php` (new), `lib/Service/PersonParticipantLookup.php` (new), `src/components/tabs/PersonVotingRecordTab.vue` (new), `tests/Unit/Service/VotingRecordServiceTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a person with votes in an open and in a secret round WHEN the record is read THEN only the open round's vote is returned, with date, decision, choice, result and party at the time
  - GIVEN an anonymised round WHEN read THEN its votes are absent
  - GIVEN a person matching no participant WHEN read THEN the list is empty and the object count of person and participant is unchanged
  - Verification: PHPUnit red-then-green, including a count assertion that nothing is created; the widget is covered by the Playwright spec of task 2
- [x] Implement
- [x] Test

### Task 4: Measure the public API before changing it

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter`
- **files**: `tests/newman/` (a collection calling `/api/ori/v1/persons`, `/votes`, `/voteevents` anonymously)
- **acceptance_criteria**:
  - GIVEN a seeded instance on development WHEN the collection runs anonymously THEN the counts per resource are recorded in the PR body
  - GIVEN `persons` returns nothing anonymously WHEN that is found THEN it is reported as its own defect and task 5 does not claim publication
  - Verification: the Newman run output attached to the PR
  - Done as: the collection is `tests/integration/decidiq-ori-public-votes.postman_collection.json` (the folder CI's Newman job runs; `tests/newman/` does not exist). Run anonymously on the local instance (decidiq 1.2.0 of development, 1 Oct): persons 0, votes 0, voteevents 0, unknown vote 404, 12 of 12 assertions. Signed in as admin the same instance lists 7 persons. Anonymous `persons` returning nothing is its own defect: OpenRegister's own object API also answers 0 of 7 anonymously, so the ORI persons resource names no one. Task 5 therefore does not claim that votes are published live.
- [x] Implement
- [x] Test

### Task 5: Publish public votes through ORI

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter`
- **files**: `lib/Controller/OriController.php` (`buildFilters()`, `narrowToPublicVisibility()`, `voter` filter), `lib/Service/OriSerializer.php` (vote and vote event shapes), `lib/Service/OriVotePublicationRule.php` (new), `tests/Unit/Controller/OriControllerTest.php`, `tests/newman/`
- **acceptance_criteria**:
  - GIVEN the rule's five conditions WHEN each is broken in turn THEN the vote is absent from the collection and 404 by id
  - GIVEN a public vote WHEN listed THEN it carries voter, option, vote event and group, and `?voter=` narrows to one person
  - GIVEN a body with `publishVotingRecords: false` WHEN its votes are listed THEN none are returned (REQ-MPR-005)
  - GIVEN `/voteevents` WHEN listed THEN closed rounds on published decisions appear with totals and no per-member values
  - Verification: PHPUnit per condition, written red first; the Newman collection of task 4 rerun and its new counts added to the PR
  - Done as: `OriVotePublicationRule` decides both resources and reads in system context; `OriController` sends `votes` and `voteevents` to it instead of the lifecycle filter (the vote and voting round schemas have no lifecycle, so the filter listed nothing and refused every id); `OriSerializer::serializeAllowed()` wraps only the rule's allow-listed fields. The body of a round falls back to the assigned body of its decision stage, because the register declares no `decision.meeting`. `tests/Unit/Controller/OriVotePublicationTest.php` (12 tests, fixtures validated with Opis against the merged register) was red on the code of #1572 (11 errors) and is green. The Newman rerun on this branch needs an instance that serves the branch; none does, so the counts after the change are not measured and the change is not claimed live.
- [x] Implement
- [x] Test
