# Tasks: platform-document-metadata-fields

## Implementation tasks

### Task 1: The document type and the record links
- **spec_ref**: `openspec/changes/platform-document-metadata-fields/specs/document-metadata-fields/spec.md#requirement-req-dmf-001-an-administrator-declares-document-types-and-their-fields`
- **files**: `lib/Settings/register.d/92-document-metadata-fields.json` (`DocumentType`, `DigitalDocument.fileId`, `meeting`, `agendaItem`, `type`, `typeFields`, `fileId` unique, register `schemas` list), `lib/Settings/profiles/municipality.json`, `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN imported THEN `document-type` is attached and the descriptor test passes
  - GIVEN two records with one `fileId` WHEN saved (Newman) THEN the second is refused, red before the rule and green after
- [ ] Implement
- [ ] Test

### Task 2: The Document types settings page
- **spec_ref**: `openspec/changes/platform-document-metadata-fields/specs/document-metadata-fields/spec.md#requirement-req-dmf-001-an-administrator-declares-document-types-and-their-fields`
- **files**: `src/manifest.d/configurable-types.json` (index and detail for `document-type`, in the settings gear beside Agenda item types), `tests/e2e/document-metadata-fields.spec.ts`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they add a field to a type THEN it is saved and shown in the dialog (Playwright)
  - GIVEN the hydra gate `admin-router` WHEN it runs THEN it passes
- [ ] Implement
- [ ] Test

### Task 3: Generalise the field renderer
- **spec_ref**: `openspec/changes/platform-document-metadata-fields/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages`
- **files**: `src/components/TypeFieldsForm.vue` (from `AgendaItemTypeFields.vue`), `src/components/AgendaItemTypeFields.vue`, `src/utils/agendaItemTypeFields.js`, `tests/vitest/agendaItemTypeFields.spec.js`
- **acceptance_criteria**:
  - GIVEN the existing vitest WHEN it runs after the split THEN it passes unchanged
  - GIVEN a document field list WHEN rendered THEN each field type gets its input (new vitest case)
- [ ] Implement
- [ ] Test

### Task 4: The Document details widget and dialog
- **spec_ref**: `openspec/changes/platform-document-metadata-fields/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages`
- **files**: `src/components/tabs/DocumentMetadataTab.vue`, `src/modals/DocumentMetadataModal.vue`, `src/registry.js`, `src/manifest.json` (`MeetingDetail` and `AgendaItemDetail` widgets, layout and slots), `tests/e2e/document-metadata-fields.spec.ts`
- **acceptance_criteria**:
  - GIVEN the seeded agenda item WHEN the griffier fills in details THEN the widget shows type and zaaknummer (Playwright, red then green)
  - GIVEN a meeting-only type WHEN the dialog opens on an agenda item file THEN it is not offered (Playwright)
  - GIVEN hydra gates `modal-isolation` and `nc-input-labels` WHEN they run THEN they pass
- [ ] Implement
- [ ] Test

### Task 5: Required fields on save
- **spec_ref**: `openspec/changes/platform-document-metadata-fields/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save`
- **files**: `lib/Listener/DocumentTypeFieldsGuardListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - GIVEN the real `ObjectCreatingEvent` with an empty required field WHEN handled THEN refused with the field named (PHPUnit, red then green)
  - GIVEN a record without a type WHEN saved THEN accepted
- [ ] Implement
- [ ] Test

### Task 6: Strings and docs
- Dutch and English strings for the page, the widget and the dialog (`test:l10n`, `check:schema-l10n` green).
- `docs/features/document-metadata-fields.md` (new): declaring a document type, filling in details, and what happens to values when a field key is renamed.
- [ ] Implement
- [ ] Test
