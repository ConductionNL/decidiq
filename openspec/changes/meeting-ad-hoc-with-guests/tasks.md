# Tasks: meeting-ad-hoc-with-guests

## Implementation tasks

### Task 1: The organiser edits their meeting
- **spec_ref**: `openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting`
- **files**: `lib/Settings/register.d/`, `tests/Unit/RegisterAuthorizationTest.php`
- **acceptance_criteria**:
  - GIVEN a meeting created by Anna WHEN Anna edits its title THEN it saves
  - GIVEN Pieter who did not create it WHEN he edits THEN it is refused
- [ ] Implement
- [ ] Test (red first)

### Task 2: Invite a guest by email
- **spec_ref**: `openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting`
- **files**: `src/dialogs/MeetingParticipantAddDialog.vue`, `lib/Service/GuestInvitationService.php`
- **acceptance_criteria**:
  - GIVEN an ad hoc meeting WHEN Anna invites guest@example.org THEN a guest participant is added and one invitation mail is sent with a link to the papers
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
