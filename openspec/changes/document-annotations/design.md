# Design: document-annotations

## Architecture Overview

decidiq stays a thin client over OpenRegister (ADR-022): annotations are objects of a new `Annotation` schema in the decidesk register, no app tables. Two things cannot be thin, and the design is explicit about both:

1. **Visibility filtering must be server-side.** The frontend normally queries OR directly, but "private by default, optionally shared with faction/body" is per-object, per-relationship authorization that OR property-RBAC cannot express (it evaluates literal NC group ids and cannot template a group name with a per-object id — the documented deviation in `x-decidesk-rbac-scopes`). All annotation reads and writes therefore go through a decidiq `AnnotationController` + `AnnotationVisibilityService` that filters/guards before delegating persistence to OR's ObjectService. This is not a redundant pass-through (hydra gate 22): every method adds an authorization decision.
2. **The overlay cannot live inside the Nextcloud Viewer.** NC's Viewer delegates PDFs to files_pdfviewer, which renders pdf.js inside a sandboxed iframe decidiq cannot reach into, and neither app exposes an annotation-layer API. decidiq therefore ships its own annotation view (`AnnotationView.vue`, lazy-loaded route) that renders the PDF with `pdfjs-dist` (canvas + text layer) and draws a decidiq-owned SVG overlay per page. Plain viewing stays with NC Viewer; the annotator is opened deliberately, only for PDFs.

Flow: Documents leaf (meeting / agenda-item detail) → "Annoteren" → `#/annotate/{fileId}` → view loads file content via WebDAV, current version identity via files_versions, and visible annotations via `GET /api/documents/{fileId}/annotations` → overlay renders anchored marks for the current version; the side panel lists all visible annotations including prior-version ones with a "gemaakt op v(N)" state.

**Anchor model.** Coordinates are stored page-relative and normalized (0–1 against the page's PDF-point width/height), so they are zoom- and DPI-independent: `page` + `quads[]` (highlight), `point` (sticky note), `path[]` (drawing stroke), plus `quote` (the highlighted text) as a human-relocatable fallback. Version identity is captured at creation: `fileId` + `fileVersion` (the files_versions revision id, i.e. its mtime-based identifier) + `versionLabel` (sequential v1, v2 … derived from the version list at capture time). An annotation whose `fileVersion` differs from the file's current revision is *prior-version*: listed, never drawn on the current pages, viewable against its own version when files_versions still has it.

**Counts.** `GET /api/meetings/{meetingId}/annotation-counts` returns `{agendaItemId: count}` computed with the same visibility filter, batched per meeting so the agenda tab issues one request.

## Declarative-vs-imperative decision (ADR-031)

Per ADR-031, capabilities are declared in the register dialects wherever a canonical dialect exists; imperative code is the exception and must be justified:

- **Declarative:** the `Annotation` schema itself, its seeds (`x-openregister-seeds`), property titles, relation properties (`document`, `agendaItem`, `meeting` as UUID references so OR's relation machinery and the related-objects widgets see them), `hardDelete: false`, and searchability. No lifecycle dialect is declared — an annotation has no status workflow (it is created, optionally edited by its author, deleted); inventing states here would be dialect noise. No notification dialect — annotations deliberately do not notify (a shared note is discovered in context, not pushed; pushing would turn private preparation into messaging).
- **Imperative (justified):** (a) `AnnotationVisibilityService` — there is no declarative per-object visibility dialect in OR, and the RBAC-scope dialect cannot template per-object faction/body groups; this is the same app-boundary deviation `authorization-via-or-rbac` already documents, applied to reads. (b) The counts endpoint — a declarative manifest object-list count keyed on `agendaItem` would count *all* annotation objects and thereby leak the existence of other users' private notes; the count must run behind the visibility filter. (c) GDPR export/bulk-delete — cross-object, author-scoped operations with no dialect equivalent. Each imperative piece is documented in the register's `x-decidesk-rbac-scopes` block alongside the existing deviation.

If OR later grows a declarative visibility dialect, (a) and (b) collapse into it; the schema is written so nothing else would change.

## API Design

### `GET /api/documents/{fileId}/annotations`
Visible annotations (own + shared-with-me) for a document, all versions. `#[NoAdminRequired]`, per-object guard inside.
**Response:** `{ "results": [ { "id": "…", "annotationType": "highlight", "page": 3, "quads": […], "quote": "…", "visibility": "private", "author": "…", "fileVersion": "…", "versionLabel": "v1", "current": false, … } ] }`

### `POST /api/documents/{fileId}/annotations` / `PUT|DELETE /api/annotations/{id}`
Create stamps author + captures `fileVersion`/`versionLabel` server-side (client values ignored); rejects `visibility: faction` when the author has no faction membership. Update/delete guarded author-only.

### `GET /api/meetings/{meetingId}/annotation-counts`
**Response:** `{ "counts": { "<agendaItemUuid>": 3 } }` — visibility-filtered.

### `GET /api/annotations/export?format=json|csv` and `DELETE /api/annotations/own`
Own annotations only, always scoped to the authenticated user.

## Nextcloud Integration

- Controllers: `AnnotationController` (routes above, `#[NoAdminRequired]` + per-object guards; registered in `appinfo/routes.php`).
- Services: `AnnotationVisibilityService` (owner / faction / body resolution; fails closed), `AnnotationService` (version capture via files_versions `IVersionManager`, export serialization); both delegate persistence to OR ObjectService.
- Mappers/Entities: none — OR objects only.
- Events/Hooks: none required; no listener dialects. File deletion: annotations keep `hardDelete: false` and become inert if their file disappears (surfaced as "document niet meer beschikbaar" in the export/panel).

## Security Considerations

- **Fail closed everywhere:** unresolved faction/body membership denies; no `catch (\Throwable) { return null; }` resolver pattern (gate: unsafe-auth-resolver); guards are invoked from the controller, not merely defined (gate: orphan-auth); every `#[NoAdminRequired]` method carries a per-object check (gate: no-admin-idor).
- Private annotations must be untestable-for-existence: list responses, counts, and error shapes never differ based on other users' private annotations.
- Author is taken from the session, never from the request body; `fileVersion` is captured server-side to prevent forged version labels.
- Input validation: page ≥ 1, normalized coordinates within [0,1], bounded path/quad array sizes, body-text length cap; standard NC CSRF applies (no `#[NoCSRFRequired]`).
- WebDAV file access uses the user's own permissions — a user who cannot read the file cannot fetch it or its annotations (guard checks file readability first).
- GDPR: annotations are personal data of the author; export and bulk delete give the author control; `hardDelete: false` keeps OR's audit trail intact while removing content from every surface.

## NL Design System

CSS variables only (no hardcoded colors) for panel, badges, and default annotation palette; highlight colors come from a fixed token-based palette with AA-checked contrast documentation; standard NC components (`NcSelect` with `inputLabel`, dialogs in `src/modals/`/`src/dialogs/` per modal-isolation); the panel is the accessible (keyboard/screen-reader) representation of every canvas mark.

## File Structure

```
lib/
  Controller/AnnotationController.php
  Service/AnnotationVisibilityService.php
  Service/AnnotationService.php
  Settings/decidesk_register.json          (Annotation schema + seeds + rbac-scopes doc)
appinfo/routes.php                          (annotation routes)
src/
  views/AnnotationView.vue                  (lazy chunk: pdfjs-dist + overlay + panel)
  components/annotation/AnnotationOverlay.vue
  components/annotation/AnnotationPanel.vue
  modals/AnnotationDeleteDialog.vue
  store/annotation.js                       (Pinia, Options API)
  manifest.json                             (deep link to the annotator; agenda-tab count badge wiring)
```

## Seed Data

Seeds make the panel, counts, visibility filtering, and export testable on install (ADR-016). Annotations anchor to Nextcloud file ids that cannot be known at seed time, so seeds use the placeholder `fileId: 0` and bind context through the seeded `DigitalDocument` / `AgendaItem` objects; the annotation view will show them as "document niet meer beschikbaar" while lists, counts, and export behave fully. Author placeholders use the nil UUID `00000000-0000-0000-0000-000000000000` (replaced by the installing admin's demo users where the seed runner supports it).

### Schema: `annotation`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | `hl-bestemmingsplan-bouwhoogte` | `note-begroting-dekking` | `draw-situatietekening-kruising` | `note-notulen-actiepunt-griffie` |
| annotationType | `highlight` | `sticky-note` | `drawing` | `sticky-note` |
| fileId | 0 | 0 | 0 | 0 |
| fileVersion | `seed-v1` | `seed-v1` | `seed-v1` | `seed-v2` |
| versionLabel | `v1` | `v1` | `v1` | `v2` |
| page | 3 | 2 | 5 | 1 |
| quote | "maximale bouwhoogte van 12 meter" | — | — | — |
| bodyText | "Strijdig met eerdere toezegging aan omwonenden?" | "Navragen bij wethouder: dekking uit de algemene reserve?" | "Kruising Stationsweg — zichtlijnen controleren" | "Actiepunt griffie: raadsbrief vóór 1 mei versturen" |
| color | `yellow` | `blue` | `red` | `green` |
| visibility | `private` | `faction` | `private` | `body` |
| author | nil UUID | nil UUID | nil UUID | nil UUID |
| document (context) | Raadsvoorstel "Bestemmingsplan Centrum" | "Programmabegroting 2026" | Bijlage "Situatietekening herinrichting Stationsweg" | Concept-notulen raadsvergadering 15 januari |
| agendaItem | seeded agenda-item ref | seeded agenda-item ref | seeded agenda-item ref | seeded agenda-item ref |

`@self` envelope for each: `{ "register": "decidesk", "schema": "Annotation", "slug": "<slug>" }`.

**Related items per object:** Files: the referenced seeded document (placeholder binding as above). Notes/Tasks/Contacts: none — the annotation *is* the note.

## Trade-offs / Decisions

- **Own pdf.js surface vs extending NC Viewer:** extending Viewer/files_pdfviewer would mean patching core apps decidiq does not own (rejected). A dedicated annotator duplicates PDF rendering but uses the same engine (pdf.js), is lazily loaded, and is scoped to annotation only. Alternative "sidecar panel without overlay" (page-anchored notes only) was rejected: the market demand is explicitly inline highlights/sticky/drawing.
- **Controller-mediated reads vs direct OR queries:** direct OR queries with client-side filtering would leak private notes to anyone with the API; a per-object visibility guard needs the app boundary. Accepted cost: one more imperative service, documented next to the existing RBAC deviation.
- **Bind-to-version + label vs carry-forward:** carry-forward re-anchoring is genuinely hard (text may move or vanish) and incumbents get it wrong; v1 chooses honest labeling ("gemaakt op v(N)") over silently misplaced marks. Re-anchoring stays out of scope.
- **`pdfjs-dist` in decidiq vs shared via nc-vue:** decidiq-local for now (single consumer, lazy chunk); promote to nc-vue when a second consumer (board book, docudesk) materializes.
- **Documents-leaf row action:** the `files` integration leaf is registry-driven from nc-vue; if it cannot host a custom "Annoteren" row action without an nc-vue change, the fallback is an "Annoteren" entry point in the annotation panel widget/agenda tab listing the item's PDFs. Provisional: try the integration-registry action hook first, fall back without blocking the change.

## Migration Plan

Additive only: register import adds the schema + seeds (idempotent via the Repair-step import); no existing schema or object changes. Rollback = revert PRs + set the schema `active: false`; objects are retained (`hardDelete: false`).

## Open Questions

- Whether the nc-vue files leaf exposes a per-row custom action hook (fallback documented above).
- Whether seeded annotations should be pruned by a cleanup step on production installs or kept as demo content (provisional: keep, consistent with other decidiq seeds).
