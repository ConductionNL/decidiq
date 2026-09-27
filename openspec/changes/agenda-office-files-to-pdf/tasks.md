# Tasks: agenda-office-files-to-pdf

## Implementation tasks

### Task 1: Record renditions on agenda items and meetings
- **spec_ref**: `openspec/changes/agenda-office-files-to-pdf/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf`
- **files**: `lib/Settings/register.d/92-office-papers-to-pdf.json`, `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN `agenda-item` and `meeting` are read THEN both carry `paperRenditions`
  - GIVEN the municipality example set WHEN loaded THEN one converted and one failed rendition show on Begroting 2027
- [ ] Implement
- [ ] Test (schema validation test in `tests/schemas`)

### Task 2: Detect a new Office paper and queue its conversion
- **spec_ref**: `openspec/changes/agenda-office-files-to-pdf/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf`
- **files**: `lib/Listener/OfficePaperAddedListener.php`, `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php`, `tests/Unit/Listener/OfficePaperAddedListenerTest.php`
- **acceptance_criteria**:
  - GIVEN a docx created in an agenda item's folder WHEN the real `NodeCreatedEvent` is dispatched THEN one `ConvertPaperToPdfJob` is queued with the file id and the item uuid
  - GIVEN a docx in a folder whose name is no decidiq object uuid WHEN dispatched THEN nothing is queued
  - GIVEN a PDF WHEN dispatched THEN nothing is queued
  - GIVEN `convert_office_papers` is false WHEN dispatched THEN nothing is queued
- [ ] Implement
- [ ] Test (construct the real OCP event)

### Task 3: Convert through filinq and record the outcome
- **spec_ref**: `openspec/changes/agenda-office-files-to-pdf/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays`
- **files**: `lib/BackgroundJob/ConvertPaperToPdfJob.php`, `tests/Unit/BackgroundJob/ConvertPaperToPdfJobTest.php`
- **acceptance_criteria**:
  - GIVEN filinq converts WHEN the job runs THEN the PDF sits beside the original and `paperRenditions` holds both ids and the backend
  - GIVEN filinq throws ConversionFailedException WHEN the job runs THEN `failedAt` and the attempts are recorded and the original is untouched
  - GIVEN filinq is not installed WHEN the job runs THEN nothing is written and one info line is logged
  - GIVEN a newer PDF sibling exists WHEN the job runs THEN it does not convert again
- [ ] Implement
- [ ] Test

### Task 4: Show the PDF as the paper and bundle it
- **spec_ref**: `openspec/changes/agenda-office-files-to-pdf/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf`
- **files**: `src/components/tabs/AgendaPaperRenditionsTab.vue`, `src/registry.js`, `src/manifest.json`, `lib/Service/MeetingPackageService.php`, `tests/e2e/office-papers-to-pdf.spec.ts`
- **acceptance_criteria**:
  - GIVEN a converted paper WHEN a member opens the agenda item THEN he sees one entry linking the PDF
  - GIVEN the secretariat WHEN it opens the same item THEN the original is offered too
  - GIVEN a meeting package is assembled WHEN an item has a rendition THEN the package holds the PDF, not the docx
- [ ] Implement
- [ ] Test (PHPUnit on the package preference, Playwright on the widget)

### Task 5: Administrator switch
- **spec_ref**: `openspec/changes/agenda-office-files-to-pdf/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off`
- **files**: `lib/Service/SettingsService.php`, `src/views/settings/AdminRoot.vue`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN she turns conversion off THEN new Office papers are no longer converted
  - GIVEN filinq is absent WHEN the admin page renders THEN it says conversion needs filinq
- [ ] Implement
- [ ] Test

## Verification

- `composer check:strict` and `npm run lint` once before push.
- The e2e spec runs where filinq is installed in CI and skips with a named reason where it is not.
