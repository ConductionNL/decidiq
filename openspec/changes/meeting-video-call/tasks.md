# Tasks: meeting-video-call

## Implementation tasks

### Task 1: Video call on the meeting page
- **spec_ref**: `openspec/changes/meeting-video-call/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a hybrid meeting WHEN a participant opens it THEN the Video call widget shows
  - GIVEN an in-person meeting THEN the widget is hidden
- [ ] Implement
- [ ] Test (red first)

### Task 2: Create and join the room
- **spec_ref**: `openspec/changes/meeting-video-call/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call`
- **files**: `src/manifest.json`, `src/components/tabs/`
- **acceptance_criteria**:
  - GIVEN a digital meeting without a room WHEN the secretary presses Create video call THEN a Talk room with the participants is linked and members see Join video call
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
