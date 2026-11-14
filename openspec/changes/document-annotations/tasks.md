# Tasks: document-annotations

## Implementation Tasks

### Task 1: Annotation schema + Dutch municipal seed data in the register
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN imported THEN an `Annotation` schema (slug `annotation`, `hardDelete: false`, property titles, relation properties `document`/`agendaItem`/`meeting`) exists with the four seed objects from design.md (`hl-bestemmingsplan-bouwhoogte`, `note-begroting-dekking`, `draw-situatietekening-kruising`, `note-notulen-actiepunt-griffie`)
  - GIVEN the seeds WHEN inspected THEN authors use only the nil UUID placeholder and `fileId: 0`, and no existing schema's required fields changed
  - GIVEN the register gates (28/30/51/52, schema-property-titles, relation-dialect) WHEN run THEN they pass
- [x] Implement
- [x] Test
- Build note: the schema is the fragment `lib/Settings/register.d/132-document-annotations.json` (ADR-037), not an edit of `decidesk_register.json`, and the seeds are in `lib/Settings/profiles/municipality.json` (a fragment declares no objects). Its read rule admits administrators and, for members, only their own annotations (`author` = `$userId`); writes are omitted, so the object API denies them to members and task 2's endpoints write behind their guard. The seeds bind to agenda items and meetings the municipality set already seeds; only one document is seeded, and its slug is too long for a reference, so no seed names a document. Tested by `tests/Unit/Settings/AnnotationRegisterTest.php`.

### Task 2: AnnotationController CRUD with server-side visibility enforcement
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side`
- **files**: `lib/Controller/AnnotationController.php`, `lib/Service/AnnotationVisibilityService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN annotations of mixed visibility WHEN a non-author lists a document's annotations THEN private annotations of others are absent from the response regardless of query parameters
  - GIVEN a faction-shared annotation WHEN a faction colleague and a non-member each query THEN only the colleague receives it; unresolved membership denies (fail closed)
  - GIVEN a create request WHEN saved THEN author comes from the session, `visibility` defaults to `private`, and `visibility: faction` is rejected when the author has no faction membership; update/delete by a non-author returns 403
  - GIVEN the routes WHEN gates run THEN route-auth, no-admin-idor, orphan-auth, and route-reachability pass (guards invoked from the controller)
- [x] Implement
- [x] Test
- Build note: `AnnotationController` + `AnnotationVisibilityService` (author always; faction = a current `Membership.faction` shared with the author, resolved user → `Person.nextcloudUserId` → memberships; body = participant of the annotation's meeting via `ParticipantResolver`; anything unresolved admits only the author). A stranger's private annotation answers 404 on update/delete, the same as a missing one, so its existence cannot be probed; a visible one by someone else answers 403. Body sharing needs a meeting (taken from the agenda item when not given) the author can read. Tested through the controller in `tests/Unit/Controller/AnnotationControllerTest.php`.

### Task 3: Version capture and visibility-filtered agenda counts
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-004-annotations-survive-re-versioning-labeled-with-their-source-version`
- **files**: `lib/Service/AnnotationService.php`, `lib/Controller/AnnotationController.php`
- **acceptance_criteria**:
  - GIVEN a create request WHEN saved THEN `fileVersion` and `versionLabel` are captured server-side from files_versions (client-supplied values ignored)
  - GIVEN a file re-uploaded as v2 WHEN listing THEN v1 annotations are returned flagged non-current with their `versionLabel`
  - GIVEN `GET /api/meetings/{id}/annotation-counts` WHEN called THEN per-agenda-item counts include only annotations visible to the caller (REQ-ANN-005 leak check)
- [x] Implement
- [x] Test
- Build note: `DocumentVersionReader` reads the file as the user (unreadable = 404): `fileVersion` is its modification time (the identifier Nextcloud file versions use), `versionLabel` counts the stored `files_versions` entries before it. The schema gained `documentName` and `createdAt` (both server-set) for the panel and the export. List results carry `current`; counts run behind the same visibility filter.

### Task 4: GDPR own-annotation export and bulk delete
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr`
- **files**: `lib/Controller/AnnotationController.php`, `lib/Service/AnnotationService.php`
- **acceptance_criteria**:
  - GIVEN a user with annotations WHEN they export JSON/CSV THEN only their own annotations appear with document, page, type, text, visibility, and date
  - GIVEN a bulk delete WHEN confirmed THEN all of the user's annotations (including shared ones) are removed and subsequent lists/counts reflect it
- [x] Implement
- [x] Test
- Build note: `GET /api/annotations/export?format=json|csv` and `DELETE /api/annotations/own`; both read with an author filter re-checked after the query.

### Task 5: Annotation view — pdf.js rendering and overlay authoring
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents`
- **files**: `src/views/AnnotationView.vue`, `src/components/annotation/AnnotationOverlay.vue`, `src/store/annotation.js`, `package.json` (pdfjs-dist, lazy chunk)
- **acceptance_criteria**:
  - GIVEN a PDF attachment WHEN the annotation view opens THEN pages render via pdfjs-dist (lazy-loaded chunk) with a per-page SVG overlay
  - GIVEN text selection, note placement, and drawing mode WHEN each is confirmed THEN a highlight/sticky-note/drawing is persisted with normalized page-relative anchors and re-renders at the same position after reload
- [x] Implement
- [x] Test
- Build note: `src/views/AnnotationView.vue` (route `/annotate/:fileId`, page `AnnotateDocument`) loads `pdfjs-dist` 4.10.38 in its own `annotator` chunk and the pdf.js worker as a web worker of its own (`src/utils/pdfWorker.js`); each page gets a canvas, a text layer and `AnnotationOverlay.vue`. Selection → highlight (quads + quote), click → sticky note (point), drag → drawing (thinned stroke); anchors are page-relative (`src/utils/annotations.js`, tested in `tests/vitest/documentAnnotations.spec.js`). The file is read over WebDAV by the path the list endpoint returns. Re-rendering at the same place after a reload is the browser half of this task and is not verified here.

### Task 6: Annotation panel, visibility selector, prior-version handling
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents`
- **files**: `src/components/annotation/AnnotationPanel.vue`, `src/modals/AnnotationDeleteDialog.vue`, `src/views/AnnotationView.vue`
- **acceptance_criteria**:
  - GIVEN visible annotations WHEN the panel renders THEN entries show author, type, page, visibility badge, and body text, filterable by visibility/author; activating an entry scrolls to and emphasizes the mark
  - GIVEN a prior-version annotation WHEN listed THEN it shows "gemaakt op v(N)", is not drawn on current pages, and can be viewed against its own version when available
  - GIVEN the visibility selector WHEN the author has no faction membership THEN only Privé/Hele orgaan are offered; keyboard reachability and NcSelect `inputLabel` verified
- [x] Implement
- [x] Test
- Build note: `AnnotationPanel.vue` (author, type, page, visibility badge, quote, text; NcSelect filters with `inputLabel`; entries are buttons, keyboard-reachable), `src/modals/AnnotationDeleteDialog.vue`. A prior-version entry shows "gemaakt op v(N)", is not drawn, and activating it opens that version from `dav/versions` with its own marks. The composer offers Privé/Hele orgaan, and Mijn fractie only when the server says the author sits in a faction; Hele orgaan only when the annotator knows the meeting or agenda item.

### Task 7: Entry points, agenda-row count badges, own-annotations settings UI
- **spec_ref**: `openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-005-annotation-counts-on-agenda-item-rows`
- **files**: `src/manifest.json`, `src/components/tabs/MeetingAgendaTab.vue`, `src/views/userSettings/`, `src/store/annotation.js`, `l10n/`
- **acceptance_criteria**:
  - GIVEN a PDF row on the Documents leaf (or the documented fallback surface from design.md) WHEN actions render THEN "Annoteren" opens the annotation view; non-PDF rows get no action
  - GIVEN the meeting agenda tab WHEN it renders THEN per-item badges show the visibility-correct counts from one batched request, and zero-count items show no badge
  - GIVEN user settings WHEN opened THEN "Mijn notities exporteren" (JSON/CSV) and "Alle eigen notities verwijderen" work end-to-end; all new strings have `nl_NL` and `en_US` translations with English keys
- [x] Implement
- [x] Test
- Build note: the nc-vue Documents leaf has no per-row action hook, so the design.md fallback is used: an `Annoteren` widget (`DocumentAnnotationsTab.vue`) on the meeting and agenda-item pages (full and simple structure) lists only the PDF papers, each with Annoteren. `MeetingAgendaTab.vue` reads `GET /api/meetings/{id}/annotation-counts` once and shows "{n} notities" per item, none for zero. User settings gained the section Mijn notities (export JSON/CSV, delete all with confirmation). Strings in en and nl.

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
