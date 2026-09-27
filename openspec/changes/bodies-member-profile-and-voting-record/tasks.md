# Tasks: bodies-member-profile-and-voting-record

### Task 1: Declare portfolio and the body's publication choice

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-002-a-membership-carries-the-members-portfolio`
- **files**: `lib/Settings/register.d/NN-member-profile.json` (new), `lib/Settings/profiles/municipality.json`, `lib/Settings/profiles/corporate.json`, `tests/Unit/RegisterJsonTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `membership.portfolio` (array of strings) and `governance-body.publishVotingRecords` (boolean, default false) exist with a `title`
  - GIVEN the example sets WHEN imported THEN the executive board membership carries its portfolio, the council publishes voting records and the corporate board does not (REQ-MPR-005)
  - Verification: `RegisterJsonTest` red first, then green
- [ ] Implement
- [ ] Test

### Task 2: The profile page and its links

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page`
- **files**: `src/manifest.d/member-profile.json` (new), `src/components/tabs/PersonMembershipsTab.vue` (new), `src/registry.js`, `src/components/tabs/GovernanceBodyMembersTab.vue`, `src/manifest.json` (`ParticipantDetail` action), `tests/e2e/spec-coverage/governance-body.spec.ts`
- **acceptance_criteria**:
  - GIVEN a seeded person WHEN `/people/:id` opens THEN photo or initials, name, biography, current and earlier memberships with portfolio, and public outside positions show
  - GIVEN a body's members widget WHEN a name is clicked THEN the profile opens (REQ-MPR-003)
  - GIVEN the manifest WHEN validated THEN every custom widget has a slot and a layout cell
  - Verification: Playwright red first (no route), then green; `tests/validate-manifest.js`
- [ ] Implement
- [ ] Test

### Task 3: The voting record read and widget

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record`
- **files**: `lib/Controller/VotingRecordController.php` (new), `appinfo/routes.php`, `lib/Service/VotingRecordService.php` (new), `lib/Service/PersonParticipantLookup.php` (new), `src/components/tabs/PersonVotingRecordTab.vue` (new), `tests/Unit/Service/VotingRecordServiceTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a person with votes in an open and in a secret round WHEN the record is read THEN only the open round's vote is returned, with date, decision, choice, result and party at the time
  - GIVEN an anonymised round WHEN read THEN its votes are absent
  - GIVEN a person matching no participant WHEN read THEN the list is empty and the object count of person and participant is unchanged
  - Verification: PHPUnit red-then-green, including a count assertion that nothing is created; the widget is covered by the Playwright spec of task 2
- [ ] Implement
- [ ] Test

### Task 4: Measure the public API before changing it

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter`
- **files**: `tests/newman/` (a collection calling `/api/ori/v1/persons`, `/votes`, `/voteevents` anonymously)
- **acceptance_criteria**:
  - GIVEN a seeded instance on development WHEN the collection runs anonymously THEN the counts per resource are recorded in the PR body
  - GIVEN `persons` returns nothing anonymously WHEN that is found THEN it is reported as its own defect and task 5 does not claim publication
  - Verification: the Newman run output attached to the PR
- [ ] Implement
- [ ] Test

### Task 5: Publish public votes through ORI

- **spec_ref**: `openspec/changes/bodies-member-profile-and-voting-record/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter`
- **files**: `lib/Controller/OriController.php` (`buildFilters()`, `narrowToPublicVisibility()`, `voter` filter), `lib/Service/OriSerializer.php` (vote and vote event shapes), `lib/Service/OriVotePublicationRule.php` (new), `tests/Unit/Controller/OriControllerTest.php`, `tests/newman/`
- **acceptance_criteria**:
  - GIVEN the rule's five conditions WHEN each is broken in turn THEN the vote is absent from the collection and 404 by id
  - GIVEN a public vote WHEN listed THEN it carries voter, option, vote event and group, and `?voter=` narrows to one person
  - GIVEN a body with `publishVotingRecords: false` WHEN its votes are listed THEN none are returned (REQ-MPR-005)
  - GIVEN `/voteevents` WHEN listed THEN closed rounds on published decisions appear with totals and no per-member values
  - Verification: PHPUnit per condition, written red first; the Newman collection of task 4 rerun and its new counts added to the PR
- [ ] Implement
- [ ] Test
