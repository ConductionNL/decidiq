# document-accessibility-check Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- document-accessibility-check (active)

## Purpose

Validates the accessibility of uploaded meeting/agenda documents (PDF first) before they reach the public surface. Provides an honest heuristic scan — explicitly not a certified PDF/UA or EN 301 549 audit — a per-document status badge, a configurable publication gate with recorded overrides, remediation guidance, and a per-body aggregate report supporting the organisation's toegankelijkheidsverklaring (Besluit digitale toegankelijkheid overheid; WCAG 2.1 AA). Distinct from the `accessibility-baseline` spec, which covers decidiq's own UI, not uploaded documents. Precedent: `p3-citizen-participation` already mandates tagged PDF/UA output for generated voting forms; this capability closes the gap for user-uploaded documents. Follows ADR-022 (all persistence via OpenRegister objects) and ADR-031 (declarative aggregations/notifications; parsing is a justified imperative exception).

## ADDED Requirements

### Requirement: REQ-001 Heuristic accessibility scan of uploaded PDF documents

The system SHALL scan uploaded meeting/agenda document attachments of MIME type `application/pdf` (read via OpenRegister's `FileService`) with `DocumentAccessibilityScanService`, performing at minimum these heuristic checks: tagged-PDF structure present (`StructTreeRoot` / `MarkInfo.Marked`), document language set (`/Lang`), document title set, bookmarks (outlines) present for documents longer than 20 pages, text layer present (scanned-image detection: near-empty extracted text alongside page-filling image XObjects), and image alternative text where extractable from structure elements. Every result surface SHALL label the outcome as a heuristic scan and SHALL NOT present it as a certified PDF/UA or EN 301 549 conformance claim. A file exceeding the configured size cap or failing to parse SHALL yield status `not-scanned` with an explanatory finding — never a crash and never a silent `pass`.

#### Scenario: Untagged scanned PDF fails the scan

- GIVEN a secretary uploads a PDF that is a scanned image without a text layer and without a structure tree
- WHEN the scan runs
- THEN the resulting status is `fail`
- AND the findings include `no-text-layer` and `not-tagged`, each with the evidence observed (e.g. "0 extractable characters across 12 pages")

#### Scenario: Well-formed tagged PDF passes

- GIVEN an uploaded PDF with a structure tree, `/Lang` set to `nl`, a document title, and extractable text on every page
- WHEN the scan runs
- THEN the resulting status is `pass`
- AND the report records which checks were evaluated and the scanner version

#### Scenario: Malformed PDF yields not-scanned, not a crash

- GIVEN an uploaded file with a `.pdf` extension whose content the parser cannot read
- WHEN the scan runs
- THEN no exception escapes the scan job
- AND the resulting status is `not-scanned` with a `parse-failure` finding

#### Scenario: Non-PDF attachment gets a basic sanity report only

- GIVEN a secretary uploads a `.docx` attachment
- WHEN the scan runs
- THEN the resulting status is `not-scanned`
- AND the report carries only a basic size/type note stating that content-level checks apply to PDF only

---

### Requirement: REQ-002 Per-document accessibility status stored and badged

The system SHALL persist each scan result as an `AccessibilityScanReport` object in the decidesk register (no app-local tables, ADR-022), carrying at minimum: the source object reference (meeting/agenda-item UUID), file identifier and name, status (`pass` / `warnings` / `fail` / `not-scanned`), the list of findings with per-finding severity, scanner version, and scan timestamp. Wherever attachments are listed (agenda builder rows, meeting and agenda-item Files tabs), each attachment SHALL show an accessibility status badge reflecting its latest report; attachments without any report SHALL show `not-scanned`. Re-uploading or re-scanning a file SHALL supersede the previous report as the badge source.

#### Scenario: Badge shown in the agenda builder

- GIVEN an AgendaItem with two attachments, one with status `pass` and one with status `fail`
- WHEN the secretary opens the agenda builder
- THEN each attachment row shows its accessibility badge (`pass` green, `fail` red)
- AND clicking the `fail` badge opens the scan detail with the findings

#### Scenario: Unscanned attachment is visibly unscanned

- GIVEN an attachment uploaded while scan-on-upload was disabled
- WHEN a participant views the Files tab of the agenda item
- THEN the attachment shows a `not-scanned` badge with an action to scan it now

---

### Requirement: REQ-003 Publication gate on failing documents with recorded override

When an agenda, meeting, or publication payload referencing document attachments is published to the public surface (the `AgendaService::publishAgenda()` path and the public-publication payload path), the system SHALL evaluate the accessibility status of every referenced attachment against the admin-configured enforcement mode: `off` (no gate, current behaviour), `warn` (publication proceeds only after the user acknowledges the listed failing/unscanned documents), or `block` (publication is refused while any referenced document has status `fail`, unless an authorised user records an override). An override SHALL require a non-empty reason and SHALL be recorded — actor, reason, timestamp, and the affected report references — on the `PublicationRecord` (or, for agenda publication without a `PublicationRecord`, in the source object's audit trail). Attachments with status `not-scanned` at publish time SHALL be scanned synchronously before gate evaluation, subject to the size cap. The gate SHALL be enforced server-side, independent of UI state.

#### Scenario: Block mode refuses publication of a failing document

- GIVEN enforcement mode `block` and a meeting agenda whose only attachment has status `fail`
- WHEN the secretary attempts to publish the agenda without an override
- THEN the publication is refused with a message listing the failing document and its findings
- AND nothing is published

#### Scenario: Override publishes and records the reason

- GIVEN enforcement mode `block` and a failing attachment
- WHEN an authorised user publishes with override reason "Origineel bij leverancier opgevraagd, publicatie wettelijk termijngebonden"
- THEN the publication proceeds
- AND the override actor, reason, timestamp, and failing report references are recorded on the `PublicationRecord`

#### Scenario: Warn mode requires acknowledgement, not override

- GIVEN enforcement mode `warn` and an agenda with one `fail` and one `not-scanned` attachment
- WHEN the secretary clicks publish
- THEN a warning dialog lists both documents with their statuses and remediation guidance
- AND publication proceeds only after explicit acknowledgement

#### Scenario: Gate enforced server-side

@e2e exclude API contract — covered by Newman/PHPUnit against the publish endpoint with a crafted direct request
- GIVEN enforcement mode `block` and a failing attachment
- WHEN a publish request is made directly against the API without override data
- THEN the request is rejected regardless of any UI state

#### Scenario: Mode off preserves current behaviour

- GIVEN enforcement mode `off`
- WHEN an agenda with a failing attachment is published
- THEN publication behaves exactly as specified in `agenda-publication` with no accessibility dialog or refusal

---

### Requirement: REQ-004 Remediation guidance per finding

Each finding in a scan report SHALL carry remediation guidance describing what is wrong and how to fix it at the source (e.g. `no-text-layer`: "Dit lijkt een gescand document zonder tekstlaag — exporteer opnieuw vanuit het bronbestand of pas OCR toe voordat u uploadt"). The guidance SHALL be shown in the scan detail view and in the publication-gate warning dialog, and SHALL be translated (Dutch and English).

#### Scenario: Warning dialog explains how to fix

- GIVEN a publication gate warning for a document with findings `not-tagged` and `no-language`
- WHEN the secretary opens the warning detail
- THEN each finding shows a human-readable explanation and a concrete source-level fix instruction

---

### Requirement: REQ-005 Aggregate accessibility report per body and period

The system SHALL provide an aggregate accessibility report per governance body and selectable period, computed declaratively from `AccessibilityScanReport` objects (`x-openregister-aggregations`, ADR-031): counts and percentages of `pass` / `warnings` / `fail` / `not-scanned` documents, and the number of publication overrides in the period. The report SHALL be exportable to CSV so it can support the organisation's toegankelijkheidsverklaring. The report SHALL state that figures derive from a heuristic scan.

#### Scenario: Body report over a quarter

- GIVEN a governance body with 40 scanned attachments in Q1 (30 pass, 6 warnings, 3 fail, 1 not-scanned) and 2 recorded overrides
- WHEN staff open the accessibility report for that body and period
- THEN the report shows those counts and percentages and the 2 overrides
- AND a CSV export downloads the same figures with the heuristic-scan disclaimer

---

### Requirement: REQ-006 Admin settings for enforcement and scanning

Admins SHALL configure, via decidiq admin settings stored in `IAppConfig` (per the existing `SettingsService` pattern): the enforcement mode (`off` / `warn` / `block`; default `warn`) and the scan-on-upload toggle (default on). When scan-on-upload is enabled, newly uploaded attachments SHALL be queued for scanning via a background job; when disabled, documents remain `not-scanned` until scanned on demand or at publish time.

#### Scenario: Admin switches to block mode

- GIVEN an admin on the decidiq admin settings page
- WHEN they set enforcement mode to `block` and save
- THEN subsequent publish attempts with failing documents are refused per REQ-003 without any code deployment

#### Scenario: Scan-on-upload queues a background scan

- GIVEN scan-on-upload enabled
- WHEN a secretary uploads a PDF to an agenda item
- THEN a scan job is queued and, after it runs, the attachment badge reflects the scan status without a page rebuild being required

## Non-Functional Requirements

- **Performance:** Upload-time scans run asynchronously (background job); a synchronous publish-time scan of a single ≤ 25 MB PDF SHALL complete within 10 seconds or yield `not-scanned` (never hang the publish request).
- **Privacy/Security:** Scanning is fully local to the Nextcloud instance — no document content leaves the server; parser failures fail closed to `not-scanned`, never to `pass`.
- **Accessibility:** Badges use text + colour (never colour alone); gate dialogs meet the `accessibility-baseline` spec (WCAG 2.1 AA).
- **Internationalization:** Dutch and English MUST be supported for all statuses, findings, and remediation guidance (ADR-005).

## Acceptance Criteria

- [ ] An uploaded untagged scanned PDF receives status `fail` with `no-text-layer` and `not-tagged` findings visible as a badge in the agenda builder.
- [ ] With enforcement mode `block`, publishing an agenda with a failing attachment is refused server-side; an override with reason publishes and the override is on the `PublicationRecord`.
- [ ] With enforcement mode `off`, publish flows are byte-for-byte the pre-change behaviour.
- [ ] The per-body aggregate report shows correct counts against seeded reports and exports to CSV with the heuristic disclaimer.
- [ ] Malformed and oversized PDFs yield `not-scanned` without job failure.
- [ ] All new persistence is OR objects/`IAppConfig` — no new tables.

## Notes

- Interplay: this capability adds a pre-condition to `agenda-publication` REQ-PUB-001 and the `public-publication` eligibility gates without modifying their requirement text — with mode `off` those specs' behaviour is unchanged, so this is an ADDED capability, not a MODIFIED delta on those specs.
- Bulk scanning of legacy already-published archives is a deliberate follow-up change; `AccessibilityScanReport` is designed so a backfill job can reuse it unchanged.
- ADRs: ADR-022 (consume OR abstractions), ADR-031 (declarative dialects; imperative parsing exception documented in design.md), ADR-005 (i18n).
