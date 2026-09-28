# Tasks: followup-public-progress

## Implementation tasks

### Task 1: Commitments on the public API and publication
- **spec_ref**: `openspec/changes/followup-public-progress/specs/ori-api/spec.md#requirement-req-fpp-001-the-public-sees-commitment-and-motion-progress`
- **files**: `lib/Controller/OriController.php`, `lib/Service/PublicationService.php`, `lib/Service/PublicationPayloadService.php`
- **acceptance_criteria**:
  - GIVEN a commitment with two progress entries WHEN a citizen reads /api/ori/v1/commitments THEN it lists status, deadline and progress without internal fields
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
