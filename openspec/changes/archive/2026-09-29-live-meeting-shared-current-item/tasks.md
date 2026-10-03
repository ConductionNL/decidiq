# Tasks: live-meeting-shared-current-item

## Implementation tasks

### Task 1: Shared current item
- **spec_ref**: `openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item`
- **files**: `lib/Settings/register.d/`, `src/views/LiveMeeting.vue`
- **acceptance_criteria**:
  - GIVEN the chair activates item 5 WHEN a member has the live screen open THEN within 5 seconds item 5 is marked current on her screen
- [x] Implement
- [x] Test (red first)

### Task 2: Room screen
- **spec_ref**: `openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-002-a-room-screen-shows-the-current-item-and-vote`
- **files**: `src/manifest.json`, `src/views/MeetingScreen.vue`
- **acceptance_criteria**:
  - GIVEN a meeting in progress WHEN the screen page is open in the room THEN it shows the current item and an open vote's state
- [x] Implement
- [x] Test (red first)

### Task 3: Record a decision live
- **spec_ref**: `openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-003-a-decision-is-recorded-when-it-is-taken`
- **files**: `src/views/LiveMeeting.vue`, `src/dialogs/LiveDecisionDialog.vue`
- **acceptance_criteria**:
  - GIVEN the current item WHEN the secretary records decision Adopted THEN a decision linked to the item and meeting is saved
- [x] Implement
- [x] Test (red first)

### Task 4: Speeches and questions per item
- **spec_ref**: `openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-004-speeches-and-questions-are-logged-per-item`
- **files**: `lib/Service/EngagementService.php`, `src/components/liveMeeting/SpeakerQueuePanel.vue`
- **acceptance_criteria**:
  - GIVEN item 5 is current WHEN Anna speaks and Pieter raises a question THEN both records carry item 5
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
