# Tasks: planning-cycle-generate-from-template

## Implementation tasks

### Task 1: Generator and listener
- **spec_ref**: `openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps`
- **files**: `lib/Service/PlanningCycleGenerator.php`, `lib/Listener/PlanningCycleCreatedListener.php`, `lib/AppInfo/Registrar/`
- **acceptance_criteria**:
  - steps valid against the schema, created once
- [ ] Implement
- [ ] Test (red first)

### Task 2: Steps widget
- **spec_ref**: `openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps`
- **files**: `src/components/tabs/PlanningCycleStepsTab.vue`, `src/manifest.d/pc-cyclus.json`
- **acceptance_criteria**:
  - vitest red first
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
