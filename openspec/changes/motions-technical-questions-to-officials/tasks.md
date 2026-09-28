# Tasks: motions-technical-questions-to-officials

## Implementation tasks

### Task 1: Assign and notify
- **spec_ref**: `openspec/changes/motions-technical-questions-to-officials/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline`
- **files**: `lib/Settings/profiles/municipality.json`, `lib/Listener/TechnicalQuestionListener.php`
- **acceptance_criteria**:
  - GIVEN a technical question WHEN the griffier assigns it to official Jan THEN Jan is notified with a link and the deadline
- [ ] Implement
- [ ] Test (red first)

### Task 2: Open and overdue list
- **spec_ref**: `openspec/changes/motions-technical-questions-to-officials/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a question past its deadline without an answer WHEN the list opens THEN it shows as overdue
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
