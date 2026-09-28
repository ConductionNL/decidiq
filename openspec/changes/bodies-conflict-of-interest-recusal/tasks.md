# Tasks: bodies-conflict-of-interest-recusal

## Implementation tasks

### Task 1: Declare from the page
- **spec_ref**: `openspec/changes/bodies-conflict-of-interest-recusal/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page`
- **files**: `src/dialogs/ConflictDeclareDialog.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a member on a motion page WHEN she declares a conflict with a reason THEN a declaration is stored and listed on the page
- [ ] Implement
- [ ] Test (red first)

### Task 2: Recusal keeps the member out of the vote
- **spec_ref**: `openspec/changes/bodies-conflict-of-interest-recusal/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter`
- **files**: `lib/Service/VoteCastGuard.php`, `tests/Unit/Service/VoteCastGuardTest.php`
- **acceptance_criteria**:
  - GIVEN an active recusal on a motion WHEN that member casts a ballot THEN the cast is refused with a message
  - GIVEN a recused member WHEN totals are computed THEN she is not in the eligible count
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
