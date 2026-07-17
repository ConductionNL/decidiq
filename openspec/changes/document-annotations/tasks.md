# Tasks: document-annotations

## Implementation Tasks

### Task 1: Annotation schema + Dutch municipal seed data in the register
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN imported THEN an `Annotation` schema (slug `annotation`, `hardDelete: false`, property titles, relation properties `document`/`agendaItem`/`meeting`) exists with the four seed objects from design.md (`hl-bestemmingsplan-bouwhoogte`, `note-begroting-dekking`, `draw-situatietekening-kruising`, `note-notulen-actiepunt-griffie`)
  - GIVEN the seeds WHEN inspected THEN authors use only the nil UUID placeholder and `fileId: 0`, and no existing schema's required fields changed
  - GIVEN the register gates (28/30/51/52, schema-property-titles, relation-dialect) WHEN run THEN they pass
- [ ] Implement
- [ ] Test

### Task 2: AnnotationController CRUD with server-side visibility enforcement
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side`
- **files**: `lib/Controller/AnnotationController.php`, `lib/Service/AnnotationVisibilityService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN annotations of mixed visibility WHEN a non-author lists a document's annotations THEN private annotations of others are absent from the response regardless of query parameters
  - GIVEN a faction-shared annotation WHEN a faction colleague and a non-member each query THEN only the colleague receives it; unresolved membership denies (fail closed)
  - GIVEN a create request WHEN saved THEN author comes from the session, `visibility` defaults to `private`, and `visibility: faction` is rejected when the author has no faction membership; update/delete by a non-author returns 403
  - GIVEN the routes WHEN gates run THEN route-auth, no-admin-idor, orphan-auth, and route-reachability pass (guards invoked from the controller)
- [ ] Implement
- [ ] Test

### Task 3: Version capture and visibility-filtered agenda counts
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version`
- **files**: `lib/Service/AnnotationService.php`, `lib/Controller/AnnotationController.php`
- **acceptance_criteria**:
  - GIVEN a create request WHEN saved THEN `fileVersion` and `versionLabel` are captured server-side from files_versions (client-supplied values ignored)
  - GIVEN a file re-uploaded as v2 WHEN listing THEN v1 annotations are returned flagged non-current with their `versionLabel`
  - GIVEN `GET /api/meetings/{id}/annotation-counts` WHEN called THEN per-agenda-item counts include only annotations visible to the caller (REQ-ANN-005 leak check)
- [ ] Implement
- [ ] Test

### Task 4: GDPR own-annotation export and bulk delete
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr`
- **files**: `lib/Controller/AnnotationController.php`, `lib/Service/AnnotationService.php`
- **acceptance_criteria**:
  - GIVEN a user with annotations WHEN they export JSON/CSV THEN only their own annotations appear with document, page, type, text, visibility, and date
  - GIVEN a bulk delete WHEN confirmed THEN all of the user's annotations (including shared ones) are removed and subsequent lists/counts reflect it
- [ ] Implement
- [ ] Test

### Task 5: Annotation view — pdf.js rendering and overlay authoring
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents`
- **files**: `src/views/AnnotationView.vue`, `src/components/annotation/AnnotationOverlay.vue`, `src/store/annotation.js`, `package.json` (pdfjs-dist, lazy chunk)
- **acceptance_criteria**:
  - GIVEN a PDF attachment WHEN the annotation view opens THEN pages render via pdfjs-dist (lazy-loaded chunk) with a per-page SVG overlay
  - GIVEN text selection, note placement, and drawing mode WHEN each is confirmed THEN a highlight/sticky-note/drawing is persisted with normalized page-relative anchors and re-renders at the same position after reload
- [ ] Implement
- [ ] Test

### Task 6: Annotation panel, visibility selector, prior-version handling
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents`
- **files**: `src/components/annotation/AnnotationPanel.vue`, `src/modals/AnnotationDeleteDialog.vue`, `src/views/AnnotationView.vue`
- **acceptance_criteria**:
  - GIVEN visible annotations WHEN the panel renders THEN entries show author, type, page, visibility badge, and body text, filterable by visibility/author; activating an entry scrolls to and emphasizes the mark
  - GIVEN a prior-version annotation WHEN listed THEN it shows "gemaakt op v(N)", is not drawn on current pages, and can be viewed against its own version when available
  - GIVEN the visibility selector WHEN the author has no faction membership THEN only Privé/Hele orgaan are offered; keyboard reachability and NcSelect `inputLabel` verified
- [ ] Implement
- [ ] Test

### Task 7: Entry points, agenda-row count badges, own-annotations settings UI
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-005-annotation-counts-on-agenda-item-rows`
- **files**: `src/manifest.json`, `src/components/tabs/MeetingAgendaTab.vue`, `src/views/userSettings/`, `src/store/annotation.js`, `l10n/`
- **acceptance_criteria**:
  - GIVEN a PDF row on the Documents leaf (or the documented fallback surface from design.md) WHEN actions render THEN "Annoteren" opens the annotation view; non-PDF rows get no action
  - GIVEN the meeting agenda tab WHEN it renders THEN per-item badges show the visibility-correct counts from one batched request, and zero-count items show no badge
  - GIVEN user settings WHEN opened THEN "Mijn notities exporteren" (JSON/CSV) and "Alle eigen notities verwijderen" work end-to-end; all new strings have `nl_NL` and `en_US` translations with English keys
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off and `openspec validate` passes
- [ ] Manual run-through of every spec scenario on the Postgres dev instance (localhost:8080), including the private-annotation leak checks as a second user
- [ ] Hydra gates green (`scripts/run-hydra-gates.sh`), including spec/e2e coverage on the new scenarios

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — visibility service tested through the controller, not only in isolation
- New/changed API endpoints covered by Newman/Postman tests (list/create/update/delete/counts/export)
- UI changes covered by Playwright browser tests (create each annotation type, panel navigation, badge counts)
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- `openspec validate` passes
