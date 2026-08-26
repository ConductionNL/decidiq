# document-annotations Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- document-annotations

## Purpose

Lets members of a governance body annotate the PDF documents attached to agenda items and meetings while preparing: highlights, sticky notes, and freehand drawings, private by default and optionally shared with the author's faction or the whole body. Annotations are OpenRegister objects bound to the exact document version they were made on, surfaced in a decidiq annotation view (overlay + panel), counted on agenda-item rows, and deletable/exportable by their author as personal notes (GDPR). Complements file attachments (REQ-PUB-003, agenda-publication) and follows the OR-RBAC authorization pattern (authorization-via-or-rbac, ADR-022).

## ADDED Requirements

### Requirement: REQ-ANN-001 Annotation is an OpenRegister object anchored to a document version

The system SHALL store each annotation as an object of a new `Annotation` OpenRegister schema in the decidesk register. An annotation MUST carry: the Nextcloud file id of the annotated document, an immutable document-version identifier captured at creation time, the page number, a geometric anchor (bounding rectangle or quad points in page coordinates for `highlight` and `drawing`; a point for `sticky-note`), the quoted text range for text-anchored highlights, `annotationType` (`highlight` | `sticky-note` | `drawing`), an optional body text, a color, the author, a `visibility` value, and optional agenda-item and meeting context references. The schema SHALL declare `hardDelete: false` and SHALL NOT change any existing schema's required fields.

#### Scenario: Highlight is persisted with full anchor

- GIVEN a raadslid viewing page 3 of "Raadsvoorstel Bestemmingsplan Centrum.pdf" attached to an agenda item in the annotation view
- WHEN they select the passage "maximale bouwhoogte van 12 meter" and choose "Markeren"
- THEN an `Annotation` object is saved with `annotationType: highlight`, the file id, the document-version identifier of the currently rendered version, `page: 3`, the selection's quad points, and the quoted text
- AND the annotation carries the current user as author and `visibility: private`

#### Scenario: Sticky note carries body text at a point anchor

- GIVEN the annotation view open on an attached PDF
- WHEN the user places a sticky note on page 2 and types "Navragen bij wethouder: dekking uit reserve?"
- THEN an `Annotation` object is saved with `annotationType: sticky-note`, `page: 2`, the point anchor, and the note body

#### Scenario: Freehand drawing stores its stroke path

- GIVEN the annotation view open in drawing mode
- WHEN the user draws a mark around a table on page 5 and confirms
- THEN an `Annotation` object is saved with `annotationType: drawing`, `page: 5`, and the stroke path in page coordinates

### Requirement: REQ-ANN-002 Visibility is private by default and enforced server-side

Every annotation SHALL have `visibility` ∈ {`private`, `faction`, `body`}, defaulting to `private`. Annotation reads MUST be filtered server-side: a private annotation is returned only to its author; a `faction` annotation only to members of the author's faction (resolved via the Fractie/FractieLidmaatschap data when present); a `body` annotation only to members of the governance body the document's meeting belongs to. The system SHALL NOT rely on client-side filtering for this. Only the author SHALL be able to update or delete an annotation. When no faction data exists for the author, the `faction` visibility option SHALL be hidden in the UI and rejected by the server.

#### Scenario: Private annotation invisible to a colleague

- GIVEN raadslid A saved a private sticky note on a meeting document
- WHEN raadslid B of the same body opens the annotation view or queries the annotation list endpoint for that document (including with manipulated query parameters)
- THEN A's private annotation is not present in any response returned to B

#### Scenario: Faction-shared annotation visible to faction members only

- GIVEN raadslid A of fractie "Groen Perspectief" shares a highlight with `visibility: faction`
- WHEN faction colleague B and non-faction member C each open the same document's annotation view
- THEN B sees the highlight labeled with A as author
- AND C does not receive it

#### Scenario: Body-shared annotation visible to the whole body

- GIVEN a secretary shares a sticky note with `visibility: body` on a document of a gemeenteraad meeting
- WHEN any active participant of that gemeenteraad opens the document's annotation view
- THEN the note is visible and attributed to the secretary

#### Scenario: Non-author cannot modify a shared annotation

- GIVEN a body-shared annotation authored by A
- WHEN user B attempts to update or delete it
- THEN the write is denied (403) and the annotation is unchanged

#### Scenario: Faction option absent without faction data

- GIVEN an instance where the author has no faction membership record
- WHEN the user opens the visibility selector on a new annotation
- THEN only "Privé" and "Hele orgaan" are offered
- AND a direct API write with `visibility: faction` is rejected with a validation error

### Requirement: REQ-ANN-003 Annotation view with overlay and panel on meeting and agenda-item documents

The system SHALL provide a decidiq annotation view that renders a PDF attached to a meeting or agenda item with an annotation overlay (creating and displaying highlights, sticky notes, and drawings in place) and a side panel listing all annotations visible to the current user, ordered by page, with author, type, visibility badge, and body text, filterable by visibility and author. The view SHALL be reachable via an "Annoteren" action on the Documents leaves of the meeting and agenda-item detail pages. Activating a panel entry SHALL scroll the overlay to that annotation. Plain document viewing SHALL remain with the Nextcloud Viewer; the annotation view SHALL NOT attempt to inject an overlay into the Nextcloud Viewer or files_pdfviewer. For non-PDF attachments the "Annoteren" action SHALL NOT be offered.

#### Scenario: Open the annotator from an agenda item

- GIVEN an agenda item detail page with an attached PDF in its Documents leaf
- WHEN the user chooses "Annoteren" on the file row
- THEN the annotation view opens rendering that PDF with the overlay and the panel showing the user's own and shared annotations

#### Scenario: Panel entry navigates to the mark

- GIVEN the annotation view with a highlight on page 7 listed in the panel
- WHEN the user activates that panel entry
- THEN the document scrolls to page 7 and the highlight is visually emphasized

#### Scenario: No annotate action on non-PDF files

- GIVEN a Documents leaf containing a .docx attachment
- WHEN the user opens the file row actions
- THEN no "Annoteren" action is offered for that file

### Requirement: REQ-ANN-004 Annotations survive re-versioning, labeled with their source version

Annotations SHALL remain bound to the document version they were created on. When the annotated file has a newer version than an annotation's version identifier, the system SHALL keep that annotation accessible and SHALL show a clear "gemaakt op v(N)" indicator instead of rendering its anchor onto the newer content. The annotation view SHALL let the user list prior-version annotations and view them against their own version where the version content is still available. The system SHALL NOT re-anchor annotations onto a new version (carry-forward heuristics are out of scope).

#### Scenario: New upload does not orphan existing notes

- GIVEN a meeting document with three annotations made on version 1
- WHEN the secretary uploads a corrected version 2 of the file
- THEN the three annotations remain accessible in the annotation view, each labeled "gemaakt op v1"
- AND none of them is drawn as an anchored mark on version 2's pages

#### Scenario: Viewing a prior-version annotation in context

- GIVEN an annotation labeled "gemaakt op v1" while v2 is current and v1 is still available in Nextcloud file versions
- WHEN the user chooses to view it on its own version
- THEN the annotation view renders version 1 with the annotation anchored in place

### Requirement: REQ-ANN-005 Annotation counts on agenda-item rows

The meeting agenda list SHALL show, per agenda item, a badge with the count of annotations visible to the current user on that item's documents (own plus shared-with-them), e.g. "3 notities". Items with zero visible annotations SHALL show no badge. The count MUST be derived from the same server-side visibility filtering as REQ-ANN-002, so it never reveals the existence of other users' private annotations.

#### Scenario: Badge reflects own and shared annotations

- GIVEN an agenda item whose document carries 2 private annotations by the current user, 1 faction-shared annotation from a faction colleague, and 4 private annotations by other users
- WHEN the meeting agenda list renders
- THEN the item's badge shows 3
- AND an item without visible annotations shows no badge

### Requirement: REQ-ANN-006 Author can delete and export their own annotations (GDPR)

The system SHALL let a user bulk-delete all of their own annotations and export all of their own annotations as JSON or CSV (including document name, page, type, body text, visibility, and creation date). Deletion SHALL remove the annotations from every surface (overlay, panel, counts). The export SHALL contain only the requesting user's own annotations, never other users' annotations regardless of shared visibility.

#### Scenario: Export own annotations

- GIVEN a user with annotations across several meeting documents
- WHEN they trigger "Mijn notities exporteren" and choose CSV
- THEN a file downloads containing exactly their own annotations with document, page, type, text, visibility, and date columns

#### Scenario: Bulk delete clears every surface

- GIVEN a user with 12 annotations including some shared with their faction
- WHEN they confirm "Alle eigen notities verwijderen"
- THEN all 12 are deleted, disappear from panels and overlays for every user, and agenda-row counts update accordingly

## Non-Functional Requirements

- **Performance:** the annotation view loads its PDF renderer lazily (separate chunk); opening a document with ≤ 200 visible annotations renders the overlay without blocking page interaction.
- **Accessibility:** all annotation actions are keyboard-reachable; panel entries are focusable and announce type, author, and page; overlay colors meet WCAG 2.1 AA contrast against the document canvas via the panel fallback (the panel is the accessible representation of every mark).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); i18n keys in English.

## Acceptance Criteria

- [ ] `Annotation` schema present in `lib/Settings/decidesk_register.json` with Dutch municipal seed data and `hardDelete: false`
- [ ] Private annotations never returned to non-authors (authorization test through the controller, not only the service)
- [ ] Highlight, sticky note, and drawing can each be created and re-rendered at the correct position after reload
- [ ] Re-uploading a file version leaves prior annotations accessible with a "gemaakt op v(N)" label
- [ ] Agenda-item rows show visibility-correct annotation counts
- [ ] Own-annotation CSV/JSON export and bulk delete work end-to-end

## Notes

- Follows ADR-022 (thin client over OpenRegister) and the `authorization-via-or-rbac` deviation: OR property-RBAC cannot template per-object group names, so the final visibility evaluation happens at the app boundary consuming OR-projected scope groups.
- Faction resolution depends on the planned `Fractie`/`FractieLidmaatschap` schemas (`fractievoorzitter-fractie-koppeling`); this capability degrades gracefully without them.
- Sibling change `meeting-pack-board-book` owns annotation questions inside compiled board books; this spec deliberately excludes them.
