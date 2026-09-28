# Tasks: live-recording-jump-to-item

## Implementation tasks

### Task 1: Player with jump per item
- **spec_ref**: `openspec/changes/live-recording-jump-to-item/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording`
- **files**: `src/components/tabs/MeetingTranscriptionTab.vue`, `lib/Service/TranscriptionService.php`
- **acceptance_criteria**:
  - GIVEN a transcribed recording WHEN the user presses Play from here on item 5 THEN the player seeks to the start of item 5
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
