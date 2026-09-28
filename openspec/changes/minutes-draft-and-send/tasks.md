# Tasks: minutes-draft-and-send

## Implementation tasks

### Task 1: Draft from the meeting with attendance
- **spec_ref**: `openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting`
- **files**: `lib/Service/MinutesDraftRenderer.php`, `src/components/tabs/MinutesActionsTab.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a meeting with attendance, votes and decisions WHEN the secretary presses Draft from the meeting THEN the minutes content lists attendees, items, votes and decisions
- [ ] Implement
- [ ] Test (red first)

### Task 2: AI draft into the minutes
- **spec_ref**: `openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-002-use-the-ai-draft-as-the-minutes`
- **files**: `src/components/tabs/MeetingTranscriptionTab.vue`
- **acceptance_criteria**:
  - GIVEN an AI draft with one section discarded WHEN the secretary presses Use as minutes THEN the kept sections are in the minutes
- [ ] Implement
- [ ] Test (red first)

### Task 3: Send approved minutes
- **spec_ref**: `openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members`
- **files**: `src/components/tabs/MinutesActionsTab.vue`, `lib/Service/ALVMinutesService.php`
- **acceptance_criteria**:
  - GIVEN approved minutes WHEN the secretary presses Send to members THEN each body member is notified with a link
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
