# Tasks: bodies-shared-body-participations

## Implementation tasks

### Task 1: Participations widget and dialog
- **spec_ref**: `openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body`
- **files**: `src/components/tabs/BodyParticipationsTab.vue`, `src/dialogs/BodyParticipationDialog.vue`, `src/utils/bodyParticipations.js`, `src/manifest.json`
- **acceptance_criteria**:
  - vitest red first, payload valid against the schema
- [ ] Implement
- [ ] Test (red first)

### Task 2: On behalf of in the add-member dialog
- **spec_ref**: `openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body`
- **files**: `src/modals/MemberAddDialog.vue`, `src/components/tabs/useRelationStore.js`
- **acceptance_criteria**:
  - membership payload carries onBehalfOf
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
