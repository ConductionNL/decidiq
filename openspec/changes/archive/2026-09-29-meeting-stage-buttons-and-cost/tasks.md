# Tasks: meeting-stage-buttons-and-cost

## Implementation tasks

### Task 1: Stage buttons on the meeting page
- **spec_ref**: `openspec/changes/meeting-stage-buttons-and-cost/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages`
- **files**: `lib/Controller/MeetingController.php`, `appinfo/routes.php`, `src/components/tabs/MeetingStageTab.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a scheduled meeting and its chair WHEN the chair presses Open meeting THEN the stage becomes opened
  - GIVEN a member who is not chair WHEN she opens the page THEN no stage buttons show
- [x] Implement
- [x] Test (red first)

### Task 2: Close stamps the cost
- **spec_ref**: `openspec/changes/meeting-stage-buttons-and-cost/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost`
- **files**: `src/components/tabs/MeetingStageTab.vue`, `tests/Unit/Service/MeetingServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an opened meeting of 2 hours with 10 attendees WHEN the chair closes it THEN meetingCost is filled and shown
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
