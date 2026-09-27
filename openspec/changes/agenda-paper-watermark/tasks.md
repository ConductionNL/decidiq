# Tasks: agenda-paper-watermark

## Implementation tasks

### Task 1: Meeting settings for watermarks
- **spec_ref**: `openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked`
- **files**: `lib/Settings/register.d/92-paper-watermark.json`, `lib/Settings/profiles/municipality.json`, `src/manifest.json` (`MeetingDetail` Planning widget include list)
- **acceptance_criteria**:
  - GIVEN the register WHEN `meeting` is read THEN `watermarkPapers` (default `automatic`) and `watermarkText` exist
  - GIVEN the closed session in the example set WHEN loaded THEN it carries `on` and "Vertrouwelijk"
- [ ] Implement
- [ ] Test (schema test)

### Task 2: The policy
- **spec_ref**: `openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked`
- **files**: `lib/Service/PaperWatermarkPolicy.php`, `tests/Unit/Service/PaperWatermarkPolicyTest.php`
- **acceptance_criteria**:
  - GIVEN `automatic` and a public meeting without restrictions WHEN asked THEN no stamp
  - GIVEN `automatic` and `isPublic` false WHEN asked THEN stamp
  - GIVEN `automatic`, a public meeting and an active restriction on the item WHEN asked THEN stamp
  - GIVEN `off` and a restricted item WHEN asked THEN no stamp
- [ ] Implement
- [ ] Test

### Task 3: Serve papers through the stamp
- **spec_ref**: `openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date`
- **files**: `lib/Controller/PaperController.php`, `lib/Support/FilinqStamp.php`, `appinfo/routes.php`, `tests/Unit/Controller/PaperControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a member who can read the item WHEN he opens a paper that must be stamped THEN the PDF he gets shows his name and the date on every page
  - GIVEN a user who cannot read the item WHEN he asks for the paper THEN 404
  - GIVEN filinq has no stamping method WHEN a stamped paper is asked for THEN 503 with the reason, never the unstamped file
- [ ] Implement (waits on the filinq stamping method)
- [ ] Test

### Task 4: Screens link through the stamp and the package is stamped
- **spec_ref**: `openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-003-the-meeting-package-is-stamped-for-the-person-who-takes-it`
- **files**: `src/components/tabs/AgendaPaperRenditionsTab.vue`, `lib/Service/MeetingPackageService.php`, `tests/e2e/paper-watermark.spec.ts`
- **acceptance_criteria**:
  - GIVEN a closed session WHEN a member opens a paper from the agenda item page THEN the viewer shows the stamped PDF
  - GIVEN the same meeting WHEN a member assembles the package THEN every paper in it carries his name
- [ ] Implement
- [ ] Test (Playwright reads the PDF text layer for the name)

### Task 5: Cache and sweep
- **spec_ref**: `openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date`
- **files**: `lib/Controller/PaperController.php`, `lib/BackgroundJob/WatermarkCacheSweepJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a member opens the same paper twice on one day WHEN the second request arrives THEN it is served from the cache
  - GIVEN cached copies from yesterday WHEN the sweep runs THEN they are removed
- [ ] Implement
- [ ] Test

## Verification

- `composer check:strict` and `npm run lint` once before push.
- Tasks 3 to 5 start once filinq's stamping method is merged; until then Task 3's 503 path is the tested behaviour.
