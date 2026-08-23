---
kind: code
---

# Proposal: document-annotations

## Summary

Add private and shared annotations on meeting documents to decidiq: board members and council members can highlight passages, place sticky notes, and draw freehand marks on the PDF documents attached to agenda items and meetings while preparing for a meeting. Annotations are private by default and can optionally be shared with the author's faction (fractie) or the whole governance body. They are stored as a new `Annotation` OpenRegister schema anchored to a specific document version, surfaced in a decidiq annotation view (panel + overlay) opened from the existing Documents leaves, counted on agenda-item rows, and deletable/exportable by their author (GDPR: they are personal notes).

## Motivation

Document annotation is the single largest unresolved must-have in the board-portal market evidence (intelligence-DB deep-dive 2026-07-16): `document-annotation` (demand 334), `digital-inline-annotations-highlights-sticky-notes-drawing-synced-across-devices` (310), `board-directors-to-annotate-materials` (244), `annotate-meeting-documents` (226). Every serious competitor — iBabs, OnBoard, Diligent — ships annotation, because it matches how governance actually works: members prepare by reading the stukken and marking them up, then bring their notes to the debate. decidiq today attaches files to agenda items and meetings (REQ-PUB-003, manifest `files` integration leaves on the meeting, agenda-item, decision, and motion detail pages) but offers no way to mark anything up — members fall back to printing PDFs or annotating in a separate tool, which breaks the "one preparation surface" promise and blocks decidiq from displacing incumbent board portals. This applies across all five governance domains: a raadslid preparing a bestemmingsplan debate, an RvC member marking an investment memo, and an association board member noting questions on the jaarrekening all need the same capability.

## Affected Projects

- [ ] Project: `decidiq` — new `Annotation` OR schema + seeds, annotation view (overlay + panel), visibility enforcement, agenda-row counts, GDPR delete/export

## Scope

### In Scope

1. **`Annotation` OR schema** in `lib/Settings/decidesk_register.json`: anchored to a document (Nextcloud file id + document version identifier + page number + position — bounding rect/quad points for highlights and drawings, optional text-range quote for text anchors), with `annotationType` (`highlight` | `sticky-note` | `drawing`), body text, color, author, visibility, and optional agenda-item/meeting context references. Includes Dutch municipal seed data.
2. **Visibility model**: `private` (default), `faction` (author's fractie), `body` (whole governance body). Server-side enforcement following the existing OR-RBAC scope pattern (`authorization-via-or-rbac`); private annotations are never readable by anyone but their author.
3. **Annotation panel + overlay** in a decidiq document-annotation view reachable from the Documents leaves on the meeting and agenda-item detail pages: PDF rendered with an annotation layer for creating/viewing highlights, sticky notes, and freehand drawings, plus a side panel listing annotations (own + shared) with filtering by visibility and author.
4. **Version binding**: annotations bind to the document version they were made on. When a newer file version exists, prior-version annotations remain accessible with a clear "gemaakt op v(N)" indicator; the view can show the older version's annotations without pretending they anchor to the new content.
5. **Annotation counts on agenda-item rows**: the meeting agenda list shows a per-item badge with the number of annotations visible to the current user on that item's documents.
6. **Delete/export own annotations**: bulk delete and JSON/CSV export of the current user's own annotations (GDPR — annotations are personal notes; author stays in control).

### Out of Scope

- Real-time co-annotation (live cursors, simultaneous editing of the same overlay).
- Carry-forward / re-anchoring of annotations across document versions (heuristic re-anchoring is explicitly deferred; v1 only preserves and labels prior-version annotations).
- Annotating inside the compiled board-book PDF (sibling change `meeting-pack-board-book`).
- Annotating non-PDF office formats: v1 anchored annotation (highlight/drawing/positioned note) targets PDFs only; other file types the NC viewer renders get no overlay in this change.
- Modifying or extending Nextcloud's own Viewer/files_pdfviewer apps.

## Approach

Thin-client, declarative-first (details in design.md): one new OR schema (`Annotation`) with lifecycle-free CRUD through OpenRegister; visibility read-gating follows the established `authorization-via-or-rbac` pattern — a per-body member scope projected into NC groups plus an app-boundary guard for the per-object group templating OR cannot express (the documented deviation in `x-decidesk-rbac-scopes`). The overlay cannot be injected into Nextcloud's Viewer (files_pdfviewer renders PDFs in a sandboxed iframe decidiq cannot reach into), so decidiq opens its own annotation view that renders the PDF via `pdfjs-dist` with a decidiq-owned annotation layer; the Documents leaves gain an "Annoteren" row action to open it. Faction sharing keys on the planned `Fractie` schema (`fractievoorzitter-fractie-koppeling`); until that lands, the faction option is hidden when no faction data exists and the model carries a generic `sharedWith` target reference so no schema migration is needed later.

## New Dependencies

- `pdfjs-dist` (frontend, decidiq-local): renders PDF pages onto canvas with a text layer so decidiq can draw its own annotation overlay. No new PHP dependencies; no external services.

## Impact

- `lib/Settings/decidesk_register.json` — new `Annotation` schema + seeds; RBAC-scope documentation extended with the annotation visibility gate.
- `lib/` — annotation read/query endpoint enforcing visibility (the one place client-side filtering is not acceptable), member-scope projection extension, GDPR export endpoint.
- `src/` — new annotation view (PDF canvas + overlay + panel), "Annoteren" action on Documents leaves, annotation-count badge on the meeting agenda tab, own-annotations management in user settings (bulk delete/export).
- `src/manifest.json` — deep link / route for the annotation view where applicable.
- No changes to existing schemas' required fields; `AgendaItem`, `Meeting`, and file attachment flows are untouched.

## Cross-Project Dependencies

- **OpenRegister** (runtime, existing): object storage, FileService-attached files, RBAC scopes. No OR code changes required.
- **`fractievoorzitter-fractie-koppeling`** (decidiq, planned): supplies the `Fractie`/`FractieLidmaatschap` schemas that `visibility: faction` resolves against. This change degrades gracefully (option hidden) while that change is unmerged — soft dependency only.
- **Nextcloud Files / files_versions** (runtime, core): file content via WebDAV and version identity for version binding. Read-only consumption.

## Risks

### Risk 1: Anchoring fragility — annotations drift from the content they mark
**Severity:** High — **Mitigation:** bind every annotation to an immutable document-version identifier (file id + version signature) captured at creation; never render an anchored mark against a different version. Prior-version annotations are shown with an explicit "gemaakt op v(N)" state instead of being force-fitted onto new content. Store both geometric anchors (page + quads) and, for highlights, the quoted text so a human can always relocate the passage.

### Risk 2: Visibility leak — a "private" note reaching colleagues would destroy trust instantly
**Severity:** High — **Mitigation:** server-side enforcement only (annotation reads go through a guard that filters on owner/faction/body membership); private is the default; no list endpoint ever returns another user's private annotations regardless of query parameters. Covered by dedicated authorization tests (hydra gates: no-admin-idor, orphan-auth).

### Risk 3: Building a PDF surface next to NC Viewer duplicates rendering and can diverge
**Severity:** Medium — **Mitigation:** scope the decidiq view strictly to annotation (no generic file browsing); plain viewing still uses NC Viewer. pdfjs-dist is the same engine files_pdfviewer embeds, so rendering fidelity matches. Constraint documented honestly in design.md.

### Risk 4: Faction sharing depends on a schema that does not exist yet
**Severity:** Medium — **Mitigation:** generic `sharedWith` reference + hidden faction option until `Fractie` data exists; body sharing and private notes are fully functional without it.

### Risk 5: pdfjs-dist bundle weight on an already large frontend
**Severity:** Low — **Mitigation:** lazy-load the annotation view chunk; pdf.js is only fetched when a user opens the annotator.

## Rollback Strategy

- Frontend: remove the "Annoteren" actions, agenda badges, and the annotation view route — the rest of the UI is untouched.
- Backend: the annotation endpoints and scope-projection extension are additive; reverting the PRs restores prior behavior.
- Data: set the `Annotation` schema `active: false` in the register (OR soft-deactivation); annotation objects are retained (hardDelete false) so re-enabling loses nothing. No existing schema is modified, so no data migration to unwind.

## Open Questions

- Should `pdfjs-dist` live in decidiq or be promoted to a shared nc-vue dependency once `meeting-pack-board-book`/docudesk also need PDF canvas rendering? (Provisional: decidiq-local now, promote when a second consumer appears.)
- Should the per-body **member** scope (`decidesk:body:{bodyId}:member`) projection introduced here be back-adopted by other read-gated features? (Provisional: introduce it scoped to annotations; generalisation is a follow-up.)
