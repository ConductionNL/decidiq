# Tasks: motions-submission-window

## Implementation tasks

### Task 1: The opening time on a meeting
- **spec_ref**: `openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-001-a-meeting-can-open-submission-at-a-set-time`
- **files**: `lib/Settings/register.d/92-motion-submission-window.json`, `lib/Settings/profiles/municipality.json`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN `meeting` is read THEN `submissionOpensAt` exists
  - GIVEN the meeting page WHEN a clerk opens a meeting THEN the Planning widget shows the opening time and the deadline
- [ ] Implement
- [ ] Test (schema test, manifest validation)

### Task 2: Refuse a motion before the window opens
- **spec_ref**: `openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused`
- **files**: `lib/Listener/SubmissionDeadlineListener.php`, `tests/Unit/Listener/SubmissionDeadlineListenerTest.php`, `tests/newman/motion-submission-window.json`
- **acceptance_criteria**:
  - GIVEN a meeting opening tomorrow WHEN a member creates a motion for it THEN 422 with the opening time in the message (red before the change: 201)
  - GIVEN the window is open WHEN a member creates a motion THEN 201
  - GIVEN a meeting without an opening time WHEN a motion is created before the deadline THEN 201, as today
- [ ] Implement
- [ ] Test (real `ObjectCreatingEvent`; Newman against a seeded instance)

### Task 3: Refuse an inverted window
- **spec_ref**: `openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused`
- **files**: `lib/Listener/SubmissionDeadlineListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`, `tests/Unit/Listener/SubmissionDeadlineListenerTest.php`
- **acceptance_criteria**:
  - GIVEN a clerk sets the opening after the deadline WHEN she saves the meeting THEN 422 "The submission window opens after it closes."
- [ ] Implement
- [ ] Test

## Verification

- `composer check:strict` and `npm run lint` once before push.
