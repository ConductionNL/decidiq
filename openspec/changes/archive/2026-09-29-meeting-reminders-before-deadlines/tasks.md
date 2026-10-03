# Tasks: meeting-reminders-before-deadlines

## Implementation tasks

### Task 1: Meeting created and reminder notices
- **spec_ref**: `openspec/changes/meeting-reminders-before-deadlines/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches`
- **files**: `lib/BackgroundJob/MeetingReminderJob.php`, `appinfo/info.xml`, `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN a meeting tomorrow at 19:00 WHEN the job runs today at 19:00 THEN each member with meeting reminder on gets one reminder
  - GIVEN a member who switched it off THEN she gets none
- [x] Implement
- [x] Test (red first)

### Task 2: Submission deadline reminder
- **spec_ref**: `openspec/changes/meeting-reminders-before-deadlines/specs/decidesk-notifications/spec.md#requirement-req-mrd-002-a-reminder-before-the-submission-deadline`
- **files**: `lib/BackgroundJob/MeetingReminderJob.php`
- **acceptance_criteria**:
  - GIVEN a submission deadline in 40 hours WHEN the job runs THEN members get one reminder naming the deadline
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
