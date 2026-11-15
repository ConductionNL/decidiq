# Tasks: platform-full-data-export

## Implementation tasks

### Task 1: Export all data
- **spec_ref**: `openspec/changes/platform-full-data-export/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data`
- **files**: `lib/BackgroundJob/FullExportJob.php`, `lib/Service/FullExportService.php`, `src/views/settings/`
- **acceptance_criteria**:
  - GIVEN an instance with meetings and decisions WHEN the admin exports all data THEN the ZIP holds a JSON per schema and the meeting files, and the admin is notified
- [x] Implement
- [x] Test (red first): tests/Unit/Service/FullExportTest.php (5), NotifierTest::testTheExportNoticeLinksToTheDownload, tests/vitest/fullExport.spec.js (4)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
