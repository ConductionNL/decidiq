# Test Plan: register-detail-optimisation

## Test Cases

### Regulations (verordeningenregister)

### TC-1: Version timeline renders ordered versions with Decision links
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin — reviews register data integrity)
- **preconditions**: A seeded `regeling` with three `regeling-versie` objects effective 2024-01-01, 2024-06-01, 2025-01-01, each with `vastgesteldDoor` set to a Decision
- **steps**: Open `RegelingDetail` for that regeling
- **expected result**: The version-timeline widget lists all three versions in ascending date order, each with a status badge and a working link to its amending Decision
- **test command**: /test-functional

### TC-2: Version timeline empty state when no versions exist
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail`
- **type**: functional
- **preconditions**: A `regeling` with zero `regeling-versie` objects referencing it
- **steps**: Open `RegelingDetail`
- **expected result**: The widget renders an explicit empty-state message, not a stuck spinner or a blank gap
- **test command**: /test-functional

### TC-3: In-force status and CVDR identifier are foregrounded
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-010-in-force-status-and-cvdr-identifier-are-foregrounded-on-regelingdetail`
- **type**: functional
- **preconditions**: A `regeling` with `status: "in-effect"` and a CVDR identifier set
- **steps**: Open `RegelingDetail`
- **expected result**: Status badge and CVDR identifier are visible in the first field group without scrolling/expanding
- **test command**: /test-functional

### TC-4: Computed current-in-force-date column renders formatted
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-011-computed-in-force-date-columns-render-formatted-not-raw`
- **type**: regression
- **preconditions**: A `regeling` whose computed current-in-force date is `2025-03-01T00:00:00Z`
- **steps**: View the `Regelingen` index
- **expected result**: The column shows a locale-formatted date (e.g. `01-03-2025`), never the raw ISO/SQL string
- **test command**: /test-regression

### Governing documents (governing-documents-register)

### TC-5: Version timeline shows notarial-deed metadata when present
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail`
- **type**: functional
- **preconditions**: A `governing-document-versie` with `aktedatum`/`notaris` set (statuten amendment)
- **steps**: Open `GoverningDocumentDetail` for the owning document
- **expected result**: The relevant timeline entry shows the deed date and notary
- **test command**: /test-functional

### TC-6: Version with no enacting Decision renders without a broken link
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail`
- **type**: functional
- **preconditions**: A `governing-document-versie` with no enacting-Decision reference
- **steps**: View the version timeline
- **expected result**: That entry renders normally with no Decision link (no dead link, no error)
- **test command**: /test-functional

### TC-7: GoverningDocuments index shows formatted current-in-force date per row
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-010-current-in-force-date-column-on-the-governingdocuments-index`
- **type**: functional
- **preconditions**: Two `governing-document` objects with different current in-force versions
- **steps**: View the `GoverningDocuments` index
- **expected result**: Each row shows its own current-in-force date, formatted; no N+1 network waterfall (verify via browser network panel — one list-level request, not one per row)
- **test command**: /test-performance

### Delegations & mandates (delegatie-mandaatregister)

### TC-8: Ondermandaat ancestor breadcrumb shows the full chain
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **type**: functional
- **preconditions**: A root toedeling A, level-2 toedeling B (`parentAllocation` = A), level-3 toedeling C (`parentAllocation` = B)
- **steps**: Open `BevoegdheidstoedelingDetail` for C
- **expected result**: Breadcrumb shows A → B → C, each a working link to its own detail page
- **test command**: /test-functional

### TC-9: Toedeling with sub-mandates lists its children
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **type**: functional
- **preconditions**: A toedeling with two child toedelingen referencing it as `parentAllocation`
- **steps**: Open its detail page
- **expected result**: Both children listed with working links
- **test command**: /test-functional

### TC-10: Root toedeling with no parent/children renders a minimal, error-free chain
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **type**: functional
- **preconditions**: A toedeling with no `parentAllocation` and no children referencing it
- **steps**: Open its detail page
- **expected result**: Widget renders just that toedeling, no console error, no empty grid gap
- **test command**: /test-functional

### TC-11: Chain widget is keyboard-navigable
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **type**: accessibility
- **persona**: Jasper (screen-reader-primary)
- **preconditions**: A toedeling with a 2-level ancestor chain and one child
- **steps**: Tab through the chain widget using keyboard only, with a screen reader active
- **expected result**: Every breadcrumb entry and child link is reachable and announced with an accessible name; focus order matches visual order
- **test command**: /test-accessibility

### Confidentiality register (embargo-geheimhouding)

### TC-12: Imposed-only record shows two pending timeline stages
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail`
- **type**: functional
- **preconditions**: A `geheimhouding` with `lifecycle: "imposed"`, `imposedAt` set, no ratification/dissolution fields
- **steps**: Open `GeheimhoudingDetail`
- **expected result**: Imposed stage populated; bekrachtiging and dissolution stages render as pending placeholders
- **test command**: /test-functional

### TC-13: Ratified record links to its ratification Decision
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail`
- **type**: functional
- **preconditions**: A `geheimhouding` with `ratificationDate`/`ratificationDecision` set
- **steps**: View the timeline
- **expected result**: Bekrachtiging stage shows the date and a working Decision link
- **test command**: /test-functional

### TC-14: Overdue bekrachtiging is distinguishable without colour alone
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail`
- **type**: accessibility
- **persona**: Jasper (screen-reader-primary) / colour-blind reviewer perspective
- **preconditions**: A `geheimhouding` with `lifecycle: "imposed"` and `ratificationDeadline` in the past
- **steps**: View the timeline as a screen-reader user and in a colour-blindness simulation
- **expected result**: Overdue is conveyed via icon/text label in addition to colour; a screen reader announces "overdue", not just a coloured dot
- **test command**: /test-accessibility

### TC-15: Ground resolves with legacy citation
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail`
- **type**: functional
- **preconditions**: A `geheimhouding` whose `ground` resolves to a `GeheimhoudingGrond` with both `citation` and `legacyCitation` set
- **steps**: Open `GeheimhoudingDetail`
- **expected result**: Both citations render
- **test command**: /test-functional

### TC-16: Target reference resolves to the correct object type
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-012-target-reference-resolves-to-its-actual-object-type`
- **type**: functional
- **preconditions**: A `geheimhouding` with `targetDocument` set, `targetAgendaItem`/`targetDecision` empty
- **steps**: Open `GeheimhoudingDetail`
- **expected result**: A labelled, working link to the document's detail page renders — not a bare UUID
- **test command**: /test-functional

### TC-17: Geheimhoudingen index bekrachtiging-deadline column shows real data
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-013-geheimhoudingen-index-bekrachtiging-deadline-column-references-the-correct-schema-field`
- **type**: regression
- **preconditions**: A `geheimhouding` with `ratificationDeadline` set
- **steps**: View the `Geheimhoudingen` index
- **expected result**: The bekrachtiging-deadline column shows that date, formatted (prior to the fix it rendered blank — the column key did not exist on the schema)
- **test command**: /test-regression

### Cross-cutting

### TC-18: No console errors or dead requests introduced across the four detail pages
- **spec_ref**: all four spec files (regression sweep)
- **type**: regression
- **preconditions**: Seeded data for at least one object per schema across all four registers
- **steps**: Visit `RegelingDetail`, `RegelingVersieDetail`, `GoverningDocumentDetail`, `BevoegdheidstoedelingDetail`, `GeheimhoudingDetail` in sequence
- **expected result**: No new browser console errors, no failed network requests, no regression to the existing `data`/`files`/`related` widgets already on these pages
- **test command**: /test-regression

## Coverage Summary

| Requirement | Covered by | Status |
|---|---|---|
| REQ-VOR-009 | TC-1, TC-2 | Covered |
| REQ-VOR-010 | TC-3 | Covered |
| REQ-VOR-011 | TC-4 | Covered |
| REQ-GDR-009 | TC-5, TC-6 | Covered |
| REQ-GDR-010 | TC-7 | Covered |
| REQ-DMR-008 | TC-8, TC-9, TC-10, TC-11 | Covered |
| REQ-EMB-010 | TC-12, TC-13, TC-14 | Covered |
| REQ-EMB-011 | TC-15 | Covered |
| REQ-EMB-012 | TC-16 | Covered |
| REQ-EMB-013 | TC-17 | Covered |

## Out of Scope

- The Delegations & mandates index empty-state defect (proposal.md Out of Scope / design.md D5) — not a requirement of this change, so not tested here. A follow-up change should add its own test plan once filed.
- The "geldig/geldend op" (in-force-on-date) date-picker filters and CSV export on all four registers — owned by the sibling changes' own follow-up tasks, not retested here.
- Backend/API testing (`/test-api`, `/test-security`) — this change has no backend surface; all four registers' existing OpenRegister RBAC is exercised unchanged and is covered by the sibling changes' own test plans.
