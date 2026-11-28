# Tasks: routes-absence-substitute

## Implementation tasks

### Task 1: Absence period hands over approvals
- **spec_ref**: `openspec/changes/routes-absence-substitute/specs/decision-route/spec.md#requirement-req-ras-001-a-substitute-approves-while-someone-is-away`
- **files**: `lib/Service/ApprovalStageActivator.php`, `lib/Service/ApprovalStageGuard.php`, `src/components/userSettings/DelegationSection.vue`
- **acceptance_criteria**:
  - GIVEN Anna away 1 to 14 August with substitute Pieter WHEN a step for Anna starts on 5 August THEN the step names Pieter as substitute and Pieter can approve it
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
