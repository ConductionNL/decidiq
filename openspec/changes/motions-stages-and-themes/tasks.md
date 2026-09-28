# Tasks: motions-stages-and-themes

## Implementation tasks

### Task 1: Stages on the motion page
- **spec_ref**: `openspec/changes/motions-stages-and-themes/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page`
- **files**: `src/components/tabs/DecisionLifecycleTab.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a submitted motion WHEN its submitter presses Withdraw THEN the motion shows withdrawn
- [ ] Implement
- [ ] Test (red first)

### Task 2: Themes and filter
- **spec_ref**: `openspec/changes/motions-stages-and-themes/specs/motion-status-management/spec.md#requirement-req-mst-002-tag-motions-by-theme-and-filter`
- **files**: `lib/Settings/register.d/`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN motions tagged Housing and Climate WHEN the user filters on Housing THEN only Housing motions are listed
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
