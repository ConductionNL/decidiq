# Tasks: governing-documents-register

## Implementation Tasks

### Task 1: Register fragment — schemas, lifecycles, relations, notifications, decision citation property
- **spec_ref**: `openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-001-governingdocument-schema`
- **files**: `lib/Settings/register.d/55-governing-documents-register.json`
- **acceptance_criteria**:
  - GIVEN a clean instance WHEN the register is imported THEN schemas `governing-document` and `governing-document-versie` exist with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys), relations (document↔versies, versie↔decision, document↔governance-body), the ADR-031-dialect new-effective-version notification (REQ-GDR-008), and the additive optional `decision.citesGoverningDocuments` property (REQ-GDR-005)
  - GIVEN a GoverningDocument without citeertitel or governingBody WHEN saved THEN schema validation rejects it (REQ-GDR-001)
  - GIVEN a GoverningDocumentVersie in `in-werking` WHEN its inwerkingtreding, notarial metadata, or consolidated-text reference is edited THEN the write is rejected as sealed (REQ-GDR-003)
  - GIVEN pre-existing decisions without the citation property WHEN the updated schema is imported THEN they validate unchanged
- [ ] Implement
- [ ] Test

### Task 2: Seed data — vereniging statuten, gemeenteraad reglement van orde, VvE splitsingsakte
- **spec_ref**: `openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-002-governingdocumentversie-traced-to-its-enacting-decision`
- **files**: `lib/Settings/register.d/55-governing-documents-register.json` (x-openregister.seedData block)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seed data is planted THEN the three governing documents from the design Seed Data tables exist (statuten-vng with `isPublic=true`, reglement-van-orde-gemeenteraad-amsterdam, splitsingsakte-vve-parkstaete) plus the `vve-parkstaete` governance body
  - GIVEN the four seed versions WHEN inspected THEN the statuten chain v1(`vervangen`, constitutive with aktedatum/notaris)→v2(`in-werking`, vastgesteldDoor `besluit-statutenwijziging-vng-2021`) resolves, rvo-amsterdam-v1 traces to `besluit-vaststelling-rvo-amsterdam`, and the splitsingsakte v1 carries notarial metadata without a decision link
  - GIVEN the seeded statutenwijziging decision WHEN its detail is opened THEN its `citesGoverningDocuments` entry renders as a link to the statuten
- [ ] Implement
- [ ] Test

### Task 3: GoverningDocumentConsolidationService — in-force resolution, activation guard, trace rule
- **spec_ref**: `openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-004-in-force-resolution-per-date`
- **files**: `lib/Service/GoverningDocumentConsolidationService.php`, `lib/Controller/GoverningDocumentController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN sealed versions at 1990-05-01 and 2022-01-01 WHEN resolving for 2021-06-15 THEN version 1 is returned; for 1985-01-01 THEN "no version in force"; boundary dates (day of inwerkingtreding, day of vervaldatum) resolve per spec
  - GIVEN a latest sealed version at 2022-01-01 WHEN sealing a new version dated 2021-06-01 THEN activation is refused (ordering guard)
  - GIVEN a document with a sealed version WHEN an amendment version is created without `vastgesteldDoor` THEN it is rejected; GIVEN a document with no versions WHEN a constitutive version omits `vastgesteldDoor` THEN it is accepted (REQ-GDR-002)
  - GIVEN the GET in-force endpoint WHEN called with a malformed date THEN a validation error is returned; the route carries explicit auth attributes and a per-object guard
- [ ] Implement
- [ ] Test

### Task 4: List/detail pages + version timeline (manifest fragment)
- **spec_ref**: `openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-006-governing-documents-list-and-detail-pages`
- **files**: `src/manifest.d/governing-documents-register.json`, `src/views/governingdocuments/`
- **acceptance_criteria**:
  - GIVEN eight seeded/created documents WHEN filtering on type `statuten` + status `geldend` THEN only matches are listed with citeertitel, type, governing body, and current inwerkingtreding (no N+1 per row)
  - GIVEN a document detail page WHEN opened THEN the version timeline shows states, notarial metadata on constitutive versions, and each enacting-decision link navigates to the Decision detail page; a "geldend op" date control drives the Task 3 resolution
  - Manifest schema refs use slugs (`governing-document`, `governing-document-versie`), never PascalCase; WCAG 2.1 AA keyboard navigation on the timeline
- [ ] Implement
- [ ] Test

### Task 5: Decision citations — edit UI and cited-by reverse lookup
- **spec_ref**: `openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-005-governing-document-reference-shape-and-decision-citations`
- **files**: `src/views/governingdocuments/`, decision detail manifest/view wiring
- **acceptance_criteria**:
  - GIVEN a Decision detail page WHEN a secretary adds a citation `{document, artikel}` THEN it is stored on `citesGoverningDocuments` and rendered as a navigable link; the citation never blocks any decision workflow
  - GIVEN two decisions citing a document WHEN its detail page is opened THEN both appear in the cited-by list with artikel strings, navigating to the Decision detail pages
  - GIVEN a citation pinning a sealed `versie` WHEN resolved later THEN identical version content is returned (REQ-GDR-003 guarantee)
- [ ] Implement
- [ ] Test

### Task 6: Access — member default, public predicate, notification verification
- **spec_ref**: `openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-007-member-access-by-default-optional-public-predicate`
- **files**: `lib/Settings/register.d/55-governing-documents-register.json` (RBAC/published-predicate config), `src/views/governingdocuments/`
- **acceptance_criteria**:
  - GIVEN `statuten-vng` with `isPublic=true` WHEN an anonymous visitor requests the public view THEN citeertitel, type, current inwerkingtreding, and consolidated text are accessible while `notaris`, `toelichting`, and `concept` versions are structurally absent (negative test)
  - GIVEN an internal document (`isPublic=false`) or a public document with only a `concept` version WHEN anonymously requested THEN access is denied / nothing is exposed (server-side eligibility; public-publication's eligibility-gates requirement is not modified)
  - GIVEN a version transitioning to `in-werking` THEN the declarative notification fires to `object-acl` readers + `decidesk-members` with nl/en subjects; no notification on `concept → vastgesteld`
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria on Postgres (8080) instance
- [ ] Code review against spec requirements (REQ-GDR-001…008)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — consolidation boundary dates, activation ordering, conditional trace rule, immutability
- New/changed API endpoints covered by Newman/Postman tests (in-force GET incl. malformed date, public-view payload stripping)
- UI changes covered by Playwright browser tests (list filters, version timeline, citation add + cited-by list, public/internal access)
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` (ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) strings added for all new user-facing strings (ADR-005); Dutch legal terms stay untranslated domain vocabulary
- `openspec validate` passes
