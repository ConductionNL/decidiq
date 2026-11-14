# Tasks: followup-public-progress

## Implementation tasks

### Task 1: Commitments on the public API and publication
- **spec_ref**: `openspec/changes/followup-public-progress/specs/ori-api/spec.md#requirement-req-fpp-001-the-public-sees-commitment-and-motion-progress`
- **files**: `lib/Controller/OriController.php`, `lib/Service/OriSerializer.php`, `lib/Settings/register.d/107-commitment-progress.json`
- **acceptance_criteria**:
  - GIVEN a commitment with two progress entries WHEN a citizen reads /api/ori/v1/commitments THEN it lists status, deadline and progress without internal fields
- [x] Implement
- [x] Test (red first): tests/Unit/Controller/OriCommitmentsTest.php (3 red before, 404 on the unknown resource)

### Task 2: The clerk adds a progress entry
- **spec_ref**: `openspec/changes/followup-public-progress/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry`
- **files**: `lib/Service/CommitmentProgressService.php`, `lib/Controller/CommitmentController.php`, `appinfo/routes.php`, `src/components/tabs/CommitmentProgressTab.vue`, `src/utils/commitmentProgress.js`, `src/manifest.d/toezeggingen-ingekomen-stukken.json`
- **acceptance_criteria**:
  - GIVEN the secretary of the commitment's meeting WHEN she adds a note THEN the commitment carries it dated today; a member who is neither chair nor secretary is refused
- [x] Implement
- [x] Test (red first): tests/Unit/Controller/CommitmentProgressTest.php (3), tests/vitest/commitmentProgress.spec.js (6, the stored entry validated against the merged governance-commitment schema)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
