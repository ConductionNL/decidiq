# Tasks: meeting-rules-from-body-and-type

## Implementation tasks

### Task 1: Meeting type defaults fill a new meeting
- **spec_ref**: `openspec/changes/meeting-rules-from-body-and-type/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults`
- **files**: `lib/Listener/MeetingDefaultsListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - GIVEN meeting type Commissie with 90 minutes and quorum 5 WHEN a meeting of that type is created without them THEN it is saved with 90 minutes and quorum 5
- [x] Implement
- [x] Test (red first)

### Task 2: Votes follow the body rules
- **spec_ref**: `openspec/changes/meeting-rules-from-body-and-type/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules`
- **files**: `src/components/VotingRoundPanel.vue`, `lib/Service/VotingRoundOpener.php`, `lib/Service/VotingRoundPreflight.php`
- **acceptance_criteria**:
  - GIVEN a body with two-thirds majority WHEN a round opens from the panel THEN the round carries the two-thirds rule
  - GIVEN too few members present for the body quorum rule WHEN the chair opens a round THEN it is refused
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
