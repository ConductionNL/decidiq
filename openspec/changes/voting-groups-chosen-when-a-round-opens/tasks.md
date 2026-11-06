# Tasks: voting-groups-chosen-when-a-round-opens

## Implementation Tasks

### Task 1: The open request takes a voting group
- **spec_ref**: `openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground`
- **files**: `lib/Service/VotingOpenRequestParser.php` (accept `votingGroup`), `lib/Service/VotingGroupResolver.php` (new: a group's persons to the meeting's participant ids, active groups of the round's body only), `lib/Service/VotingRoundOpener.php` (resolve the group into `presetParticipantIds` before `seatHoldersFor()`), `tests/Unit/Service/VotingGroupResolverTest.php` (new), `tests/Unit/Controller/VotingControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a group of 11 persons, 10 of them participants of the meeting WHEN a round opens with it THEN 10 participants are eligible and the eleventh is reported as excluded
  - GIVEN a group of another body, or an inactive group WHEN a round opens with it THEN the request is refused with 400
  - GIVEN neither a group nor preset ids WHEN a round opens THEN behaviour is unchanged
- [ ] Implement
- [ ] Test

### Task 2: Who may vote in the open-round panel
- **spec_ref**: `openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground` (+ REQ-VGP-005)
- **files**: `src/components/VotingRoundPanel.vue` (a "Who may vote" choice as DcStemronde draws it, sending `votingGroup`; a notice listing `excludedPresetUuids`), `src/utils/votingGroups.js` (the body's active groups as options), `tests/vitest/votingGroups.spec.js`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a body with two active groups WHEN the panel opens THEN the choice lists all members (default) and the two groups with their member counts
  - GIVEN the server reports one excluded member WHEN the round opens THEN the panel names her
- [ ] Implement
- [ ] Test

### Task 3: End to end
- **spec_ref**: `openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground`
- **files**: `tests/e2e/voting-group-round.spec.ts` (new), `openspec/parity/capabilities.json` (vot-25 to built)
- **acceptance_criteria**:
  - GIVEN a seeded group WHEN the chair opens a round with it on the live instance THEN only its members can cast a vote
- [ ] Implement
- [ ] Test
