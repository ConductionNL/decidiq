# Tasks: agenda-incoming-documents-list

## Implementation tasks

### Task 1: Routed documents widget reads agenda items
- **spec_ref**: `openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda`
- **files**: `src/components/tabs/MeetingRoutedDocumentsTab.vue`, `src/utils/incomingDocuments.js`
- **acceptance_criteria**:
  - vitest red first
- [x] Implement
- [x] Test (red first)

### Task 2: Incoming documents list and Put on agenda
- **spec_ref**: `openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda`
- **files**: `src/manifest.d/incoming-documents.json`, `src/dialogs/PutOnAgendaDialog.vue`
- **acceptance_criteria**:
  - payload valid against agenda-item
- [x] Implement
- [x] Test (red first)
- [ ] Playwright `tests/e2e/incoming-documents.spec.ts` green on the municipality example set (owed live)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
