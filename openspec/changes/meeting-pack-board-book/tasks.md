# Tasks: meeting-pack-board-book

## Implementation Tasks

### Task 1: Docudesk `PdfService::mergePdfs()` (cross-project, first)
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack`
- **files**: `../docudesk/lib/Service/PdfService.php`, `../docudesk/tests/Unit/Service/PdfServiceMergeTest.php`
- **acceptance_criteria**:
  - GIVEN an array of PDF byte streams and a bookmark list WHEN `mergePdfs()` runs THEN one PDF is returned with continuous pagination and a named outline entry per bookmark (mPDF 8.2 + FPDI import, no new library)
  - GIVEN an unparsable PDF in the input WHEN merging THEN a `PdfMergeException`-style typed failure identifies the offending stream so the caller can substitute a placeholder
  - Existing `renderPdf`/`generatePdfFromHtml` callers are behaviourally unchanged (additive method only)
- [ ] Implement
- [ ] Test

### Task 2: Register — `MeetingPack` schema, `AgendaItem.confidentiality`, declarative notification
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a clean register import WHEN schemas are listed THEN `MeetingPack` exists (`schema:DigitalDocument`; meeting relation, version, changeNote, generatedAt, generatedBy, fingerprint, filePath, pageCount, skipped) and `AgendaItem` has optional `confidentiality` enum public/internal/confidential defaulting to internal
  - GIVEN the `MeetingPack` schema WHEN inspected THEN it declares `x-openregister-notifications` (trigger `created`, channel `nc-notification`, recipients `object-acl` read, bilingual subject) matching the canonical dialect (gate-18) — no imperative dispatch anywhere
  - Schema refs use slugs, notification/lifecycle dialects validate (gates 28/30/51/52 pattern)
- [ ] Implement
- [ ] Test

### Task 3: Seed data for `MeetingPack` + confidential AgendaItem seed
- **spec_ref**: `openspec/changes/meeting-pack-board-book/design.md#seed-data`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a clean install WHEN the register imports THEN the 4 `x-openregister-seeds` MeetingPack objects from design.md exist (vergaderbundel-raad-2025-01-15 v1+v2 with changeNote and a skipped entry, rvc-q1 v1, directieoverleg v1) linked to the existing Meeting seeds
  - GIVEN the AgendaItem seeds WHEN imported THEN `jaarrekening-2024` carries `confidentiality: confidential` and the others remain internal
- [ ] Implement
- [ ] Test

### Task 4: `BoardBookService` — compose, delegate to Docudesk, fingerprint, versioning
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack`
- **files**: `lib/Service/BoardBookService.php`, `tests/Unit/Service/BoardBookServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a meeting with ordered agenda items and PDF attachments WHEN compiled THEN one PDF (cover, TOC with page numbers, sections in orderNumber order, merged attachments, per-item bookmarks) is written versioned into the meeting folder via `MeetingFolderService` and a `MeetingPack` object (version, fingerprint, filePath, pageCount, skipped) is created
  - GIVEN Docudesk absent WHEN compiling THEN the existing `MeetingPackageService` folder package is produced and the result honestly states no PDF was rendered (MinutesDocumentService pattern)
  - GIVEN a prior version WHEN recompiling without a changeNote THEN compilation is refused; with a changeNote THEN version N+1 is created and version N's file is untouched
  - GIVEN an empty agenda WHEN compiling THEN a validation error and no pack
- [ ] Implement
- [ ] Test

### Task 5: Confidential exclusion + defensive merge placeholders
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-005-access-control-and-confidential-item-exclusion`
- **files**: `lib/Service/BoardBookService.php`, `tests/Unit/Service/BoardBookServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an item with `confidentiality: confidential` WHEN compiled THEN its TOC line remains but its section body is only the closed-doors placeholder — no description, no attachments, for every compiled pack (no per-user variants)
  - GIVEN a non-PDF or unmergeable/oversized attachment WHEN compiled THEN compilation succeeds, a placeholder page names the file, and it appears in `skipped`
  - Mutation check: removing the exclusion branch makes the unit test fail (no fake green)
- [ ] Implement
- [ ] Test

### Task 6: `BoardBookController` + routes + `BoardBookCompileJob`
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator`
- **files**: `lib/Controller/BoardBookController.php`, `lib/BackgroundJob/BoardBookCompileJob.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a secretary/chair WHEN POST `/api/meetings/{id}/board-book` THEN a queued job is enqueued (one in-flight per meeting) and 202 returned; GIVEN any other authenticated user THEN 403 (explicit guard in the method body — `#[NoAdminRequired]` alone never suffices; guard resolver denies on failure, never fails open)
  - GIVEN packs exist WHEN GET `/api/meetings/{id}/board-book/status` THEN state, latest/current fingerprints, `outdated`, skip report and delivery failures are returned
  - Both routes registered and reachable (gates: route-auth, semantic-auth, route-reachability); no pass-through CRUD wrappers for reading packs (redundant-controller gate — frontend reads MeetingPack via the OR objects API)
- [ ] Implement
- [ ] Test

### Task 7: Offline delivery to attendees' Files
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-006-offline-delivery-into-attendees-own-files`
- **files**: `lib/Service/BoardBookService.php`, `tests/Unit/Service/BoardBookServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a compiled pack WHEN delivery runs THEN each active attendee (leftAt null, resolvable NC account) gets a read-only, non-reshareable share of the versioned pack file visible in their own Files (native clients can sync it offline)
  - GIVEN one unresolvable attendee among five WHEN delivering THEN the four others receive the pack and the failure is reported per attendee, not fatal
- [ ] Implement
- [ ] Test

### Task 8: Meeting detail page — "Vergaderbundel" section
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page`
- **files**: `src/` meeting detail view + store wiring (MeetingPack via `useObjectStore` against the OR objects API)
- **acceptance_criteria**:
  - GIVEN a secretary WHEN viewing a meeting THEN the CnDetailCard section shows compile/recompile in `#header-actions` (changeNote dialog for v2+), the version list (version, generatedAt, generatedBy, changeNote, pageCount, download per version) and the latest skip report
  - GIVEN a plain participant WHEN viewing THEN the section is read-only (download only, no compile action)
  - GIVEN the agenda changed after the latest pack WHEN the page loads THEN the "Vergaderbundel verouderd" `CnStatusBadge` shows (text + color, WCAG AA); fresh pack shows no badge
  - All strings nl/en with English i18n keys; NL Design System CSS variables only
- [ ] Implement
- [ ] Test

### Task 9: End-to-end coverage for changed scenarios
- **spec_ref**: `openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages`
- **files**: `tests/e2e/` board-book spec(s), Newman collection for the two endpoints
- **acceptance_criteria**:
  - Every Scenario in the change spec is referenced by a Playwright e2e test or carries a reason-bearing `@e2e exclude` (gate-19); at minimum compile-happy-path, outdated badge, confidential exclusion, and participant read-only are browser-tested on the Postgres 8080 instance
  - Newman covers POST compile (202 + 403) and GET status
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean in decidiq and docudesk
- Feature documentation updated in `docs/` (user-facing feature, ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new user-facing strings (ADR-005/ADR-007)
- `openspec validate` passes; hydra gates green (incl. notification-dialect, redundant-controller, orphan-auth, e2e-coverage)
