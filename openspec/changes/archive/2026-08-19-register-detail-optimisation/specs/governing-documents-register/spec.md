# governing-documents-register Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- governing-documents-register
- register-detail-optimisation

## Purpose

Governing documents (`governing-document`/`governing-document-versie`) share the same version-and-consolidation shape as the regulations register: an amendment traces to a Decision, and the reader needs the version history in date order to know which text currently governs. This spec adds the same version-timeline treatment to `GoverningDocumentDetail` that `register-detail-optimisation` adds to `RegelingDetail`, plus the current-in-force-date index column the `governing-documents-register` manifest fragment's own `_note` already promised but never declared.

## ADDED Requirements

### Requirement: REQ-GDR-009 Version timeline widget on GoverningDocumentDetail

The `GoverningDocumentDetail` page MUST render a `version-timeline` widget listing every `governing-document-versie` object referencing the current document, ordered by effective date ascending, each entry showing version number, effective date, status, notarial-deed metadata when present (`aktedatum`/`notaris`), and a resolved link to the enacting Decision where one is set (amendments without a notarial deed or an enacting Decision — e.g. an initial adoption predating decidesk — render the entry without that link rather than omitting the version).

#### Scenario: Governing document with a notarised amendment shows deed metadata
- GIVEN a `governing-document-versie` with `aktedatum` and `notaris` set
- WHEN the user opens `GoverningDocumentDetail` for the owning document
- THEN the version-timeline entry for that version shows the deed date and notary

#### Scenario: Version with no enacting Decision renders without a broken link
- GIVEN a `governing-document-versie` with no enacting-Decision reference set
- WHEN the user views the version-timeline
- THEN that entry renders its other fields normally and shows no Decision link (not a link to nothing)

### Requirement: REQ-GDR-010 Current-in-force-date column on the GoverningDocuments index

The `GoverningDocuments` index MUST include a column showing each document's current in-force version's effective date, resolved as a list-level query (no per-row N+1 lookup), with an explicit date-format hint so the value renders through the locale-aware date formatter.

#### Scenario: Index shows the current-in-force date per document
- GIVEN two `governing-document` objects with different current in-force versions
- WHEN the user views the `GoverningDocuments` index
- THEN each row shows its own current-in-force date, formatted, not raw

## Non-Functional Requirements

- **Performance:** The version-timeline widget MUST NOT issue one request per version row; the index's current-in-force-date column MUST NOT issue one request per document row.
- **Accessibility:** The version-timeline widget and its links MUST be keyboard-navigable (WCAG 2.2 AA, ADR-010).
- **Internationalization:** Dutch and English MUST be supported (ADR-005/025).

## Acceptance Criteria

- [ ] `GoverningDocumentDetail` renders the version-timeline widget for `governing-document-versie` ordered by effective date
- [ ] Notarial-deed metadata renders when present
- [ ] `GoverningDocuments` index shows a formatted current-in-force-date column

## Notes

Builds on `lib/Settings/register.d/55-governing-documents-register.json` and `src/manifest.d/governing-documents-register.json` already shipped by the `governing-documents-register` change. The citing-decisions reverse lookup (REQ-GDR-005) and the "geldend op" date control remain that change's own follow-up work; this spec adds only the version-timeline widget and the current-in-force-date column.
