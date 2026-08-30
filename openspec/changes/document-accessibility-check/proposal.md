---
kind: code
---

# Proposal: document-accessibility-check

## Summary

Add pre-publication accessibility validation for uploaded meeting/agenda documents (PDF first) to decidiq: an honest heuristic scan (tagged-PDF structure, document language, title, bookmarks for long documents, text-layer presence / scanned-image detection, extractable image alt text) producing a per-document accessibility status (`pass` / `warnings` / `fail` / `not-scanned`) stored as an OpenRegister object and shown as a badge wherever attachments are listed; a publication gate on the existing agenda-publication and public-publication paths that blocks or warns-with-override (per admin setting) on failing documents with the override reason recorded; remediation guidance per finding; and a declarative aggregate accessibility report per governance body/period that supports the organisation's toegankelijkheidsverklaring.

## Motivation

Publishing accessible documents is a legal duty (Besluit digitale toegankelijkheid overheid; EN 301 549 / WCAG 2.1 AA) that municipalities systematically fail: Digimonitor January 2025 grades the accessibility declarations of Dutch council-information systems at status A 1%, B 22%, C 8%, D 24%, E 46% — an improvement from 75%-missing in May 2022 achieved only under public pressure. The dominant failure mode is inaccessible uploaded PDFs on public portals — exactly the artefact decidiq publishes via agenda-publication and public-publication. Digitoegankelijk publishes reusable vendor accessibility audits for exactly 5 RIS products (Qualigraf web, iBabs Publieksportaal, Politiek Portaal, Notubox, GemeenteOplossingen), making a vendor-supplied accessibility posture a de facto market-entry requirement. The unresolved must-feature `check-document-accessibility-before-publication` (demand 846) has no coverage in decidiq today: the existing `accessibility-baseline` spec covers the app's own UI (WCAG on pages), not the documents users upload — no competitor-visible feature and no decidiq code checks a PDF before it reaches the public surface.

## Affected Projects

- [ ] Project: `decidiq` — new OR schema `AccessibilityScanReport` + declarative aggregations/notifications in `lib/Settings/decidesk_register.json`; `DocumentAccessibilityScanService` (imperative PDF parsing, justified ADR-031 exception); scan-on-upload background job; gate wiring into `AgendaService::publishAgenda()` and `PublicationEligibilityService`/`PublicationService`; override recording on `PublicationRecord`; accessibility badge in attachment lists; per-body aggregate report surface; admin settings (enforcement mode off/warn/block, scan-on-upload toggle); seed data; tests.
- [ ] Project: `openregister` — consumed only: ObjectService, FileService (reading attached file content), per-object RBAC. No OR changes (ADR-022).

## Scope

### In Scope

1. Automated heuristic accessibility scan of uploaded meeting/agenda documents, PDF first: tagged-PDF structure (`StructTreeRoot`/`MarkInfo`), document language (`/Lang`), document title, bookmarks (outlines) for long documents, text-layer presence (scanned-image detection), image alt text where extractable. Clearly reported as a heuristic scan, NOT a certified PDF/UA audit.
2. Per-document accessibility status (`pass` / `warnings` / `fail` / `not-scanned`) stored as an `AccessibilityScanReport` object and shown as a badge wherever attachments are listed (agenda builder rows, meeting/agenda-item Files tabs).
3. Publication gate: on publishing an agenda/meeting/document to the public surface, block or warn-with-override per admin setting on failing documents; override reason, actor, and timestamp recorded on the `PublicationRecord`.
4. Remediation guidance in the warning detail: what is wrong and how to fix it at the source (e.g. "scanned image without text layer — re-export from the source document or run OCR").
5. Aggregate accessibility report per governance body and period (pass/warn/fail/not-scanned counts, override count) supporting the toegankelijkheidsverklaring, with CSV export.
6. Admin settings: enforcement mode (`off` / `warn` / `block`), scan-on-upload toggle.

### Out of Scope

- Automatic remediation or conversion of documents (no OCR, no re-tagging).
- Bulk scanning of legacy already-published archives (deliberate follow-up change; the report schema is designed so a backfill job can be added later).
- Non-PDF formats beyond a basic size/type sanity report (`not-scanned` with format note).
- Certified PDF/UA or EN 301 549 conformance claims — the scan is explicitly labelled heuristic.

## Approach

A `DocumentAccessibilityScanService` parses PDF bytes fetched through OpenRegister's `FileService` and emits findings; results are persisted as `AccessibilityScanReport` OR objects keyed by file id + source object (thin-client pattern preserved: no app tables). Scanning runs from a background job on upload (when the toggle is on) and on-demand at publication time for any `not-scanned` attachment. The gate hooks the two existing publication chokepoints — `AgendaService::publishAgenda()` and the `PublicationEligibilityService`/`PublicationService` payload path — consulting the enforcement mode from `IAppConfig` via `SettingsService`. Aggregations for the per-body report and the dashboard are declarative (`x-openregister-aggregations` on the report schema); parsing/validation is the imperative exception. Details in design.md.

## New Dependencies

- `smalot/pdfparser` (composer, pure-PHP PDF parsing, LGPL) — provisional choice for reading PDF structure without a binary dependency. No external services; scanning is fully local (no document leaves the instance).

## Impact

- `lib/Settings/decidesk_register.json`: new `AccessibilityScanReport` schema (+ aggregations, notifications, seeds); `PublicationRecord` gains override fields.
- `lib/Service/`: new `DocumentAccessibilityScanService`; gate wiring in `AgendaService`, `PublicationEligibilityService`/`PublicationService`; `SettingsService` config keys.
- `lib/BackgroundJob/`: new scan-on-upload job.
- Frontend: badge component in attachment lists, scan-detail/remediation panel, publication-gate dialog with override reason, per-body report view, admin settings fields.
- Existing publish flows change behaviour only when enforcement mode ≠ `off` (default `warn`, see Open Questions).

## Cross-Project Dependencies

Consumes OpenRegister ObjectService/FileService abstractions only (ADR-022); no changes to other apps. Docudesk is NOT required — unlike PDF generation (which delegates to Docudesk's PdfService when resolvable), scanning is decidiq-local so the compliance gate works on every install.

## Risks

### Risk 1: Heuristic scan over- or under-reports, eroding trust or blocking legitimate publications
**Severity:** Medium — **Mitigation:** every surface labels the result "heuristic scan, not a certified audit"; `block` mode always offers admin-configurable override with recorded reason; checks are conservative (structural presence checks, not conformance judgements); findings carry the raw evidence (e.g. "no StructTreeRoot").

### Risk 2: Parsing untrusted PDFs in PHP (malformed/hostile files, memory blowups)
**Severity:** Medium — **Mitigation:** scan runs in a background job with a file-size cap; parser exceptions are caught and yield `not-scanned` with a parse-failure finding (never a crash, never fail-open into `pass`); publication gate treats `not-scanned` per enforcement mode.

### Risk 3: Publication gate slows or breaks the existing publish flows
**Severity:** Low — **Mitigation:** gate reads pre-computed report objects; only `not-scanned` attachments trigger a synchronous scan at publish time, capped and skippable via override; enforcement mode `off` restores exact current behaviour.

## Rollback Strategy

Set enforcement mode to `off` and scan-on-upload to off — publish flows behave exactly as before (config-level rollback, no deploy). Full rollback: revert the change; `AccessibilityScanReport` objects and `PublicationRecord` override fields are additive OR data that can remain inert or be removed via register cleanup; no migrations, no tables.

## Open Questions

1. Default enforcement mode on upgrade: provisional `warn` (visible but non-breaking); `block` would surprise existing installs.
2. "Long document" threshold for the bookmarks check: provisional 20 pages, admin-tunable later if needed.
