# Tasks: meeting-attendance-per-meeting

## Implementation tasks

### Task 1: Attendance schema and widget
- **spec_ref**: `openspec/changes/meeting-attendance-per-meeting/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting`
- **files**: `lib/Settings/register.d/`, `src/components/tabs/MeetingParticipantsTab.vue`
- **acceptance_criteria**:
  - GIVEN a meeting with 5 participants WHEN the clerk marks Anna as sent apologies THEN an attendance record for this meeting says excused and earlier meetings keep theirs
- [ ] Implement
- [ ] Test (red first)

### Task 2: Report reads per-meeting attendance
- **spec_ref**: `openspec/changes/meeting-attendance-per-meeting/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN two meetings with different attendance WHEN the report opens THEN each meeting counts its own absentees
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
