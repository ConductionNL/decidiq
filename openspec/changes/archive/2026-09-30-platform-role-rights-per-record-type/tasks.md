# Tasks: platform-role-rights-per-record-type

## Implementation tasks

### Task 1: Rights overview and role mapping
- **spec_ref**: `openspec/changes/platform-role-rights-per-record-type/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type`
- **files**: `lib/Controller/Settings/`, `src/views/settings/`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN the admin settings WHEN the admin opens Rights per record type THEN each record type shows who may read and change it
  - GIVEN griffie mapped to group Griffie WHEN a Griffie member edits a meeting THEN it saves
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
