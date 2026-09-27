# Tasks: motions-export-with-attachments

## Implementation tasks

### Task 1: Endpoint and service for a ZIP
- **spec_ref**: `openspec/changes/motions-export-with-attachments/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents`
- **files**: `lib/Controller/ExportBundleController.php`, `lib/Service/ExportBundleService.php`, `appinfo/routes.php`, `tests/Unit/Service/ExportBundleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN three selected motions with two attachments each WHEN a ZIP is asked for THEN `Decidiq exports/Motions <date>.zip` holds three folders with a decision.html and the two files each
  - GIVEN a filter `submittedAt` from 2026-01-01 WHEN all matching is asked for THEN only motions submitted since then are in it
  - GIVEN 501 matching decisions WHEN asked THEN 422 asking to narrow the filter
- [ ] Implement
- [ ] Test

### Task 2: One PDF through filinq
- **spec_ref**: `openspec/changes/motions-export-with-attachments/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments`
- **files**: `lib/Service/ExportBundleService.php`, `tests/Unit/Service/ExportBundleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN two motions WHEN a PDF is asked for THEN filinq's merge receives motion 1's text page, its attachments, motion 2's text page, its attachments, in that order, with bookmarks on
  - GIVEN filinq refuses because an attachment is unreadable WHEN asked THEN the answer names the file and no export file exists
  - GIVEN filinq is not installed WHEN a PDF is asked for THEN 503; a ZIP still works
- [ ] Implement
- [ ] Test

### Task 3: The bulk action and its modal
- **spec_ref**: `openspec/changes/motions-export-with-attachments/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments`
- **files**: `src/manifest.json` (`Motions`, `Decisions` `bulkActions`), `src/modals/ExportBundleModal.vue`, `src/registry.js`, `tests/e2e/export-bundle.spec.ts`
- **acceptance_criteria**:
  - GIVEN the griffier selected three motions WHEN she chooses Export with attachments, one PDF THEN the modal reports the file and links it
  - GIVEN a filtered list WHEN she chooses all rows matching the filter THEN the export holds every matching motion, not only the visible page
  - GIVEN the modal isolation and nc-input-labels gates WHEN run THEN they pass
- [ ] Implement
- [ ] Test (Playwright)

### Task 4: Large exports run in the background
- **spec_ref**: `openspec/changes/motions-export-with-attachments/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready`
- **files**: `lib/Service/ExportBundleService.php`, `tests/Unit/Service/ExportBundleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN filinq's `shouldQueue()` says yes WHEN the export is asked THEN the answer is "queued" at once and a notification with the file link follows when it is done
- [ ] Implement
- [ ] Test

## Verification

- `composer check:strict` and `npm run lint` once before push.
