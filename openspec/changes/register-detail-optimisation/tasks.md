# Tasks: register-detail-optimisation

## Implementation Tasks

### Task 1: Build the shared version-timeline widget component
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail`
- **files**: `src/components/widgets/RegisterVersionTimelineWidget.vue`
- **acceptance_criteria**:
  - GIVEN a `content` config `{ versionRegister, versionSchema, parentRefField, effectiveDateField, versionNumberField, statusField, decisionRefField, lapseDateField?, extraFields? }` WHEN the widget mounts on a detail page THEN it queries `versionRegister`/`versionSchema` filtered on `parentRefField == currentObjectId` and renders the results sorted ascending by `effectiveDateField`
  - GIVEN a version row with `decisionRefField` set WHEN rendered THEN it shows a working link to that Decision's detail page; GIVEN it is unset THEN no dead link renders
  - GIVEN zero matching versions WHEN rendered THEN an explicit empty-state message renders (never a stuck loading state)
  - GIVEN `extraFields` entries (e.g. `aktedatum`/`notaris`) WHEN present on a row THEN they render on that row's entry
  - All dates render through the shared `formatDate`/`formatDateTime` utilities, never raw `Date` stringification
- [ ] Implement
- [ ] Test

### Task 2: Build the ondermandaat delegation-chain widget component
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **files**: `src/components/widgets/DelegationChainWidget.vue`
- **acceptance_criteria**:
  - GIVEN a toedeling with an ancestor chain via `parentAllocation` WHEN the widget mounts THEN it walks the chain upward and renders an ordered breadcrumb (root → ... → current), each entry a working link
  - GIVEN toedelingen whose `parentAllocation` equals the current object's id WHEN the widget mounts THEN it lists them as direct children with working links
  - GIVEN a toedeling with no parent and no children WHEN rendered THEN the widget shows just that toedeling, no error, no empty grid gap
  - GIVEN a defensive cycle in `parentAllocation` references (never producible via normal flows) WHEN the ancestor walk runs THEN it de-duplicates visited ids and terminates without an infinite loop or duplicate re-fetch
  - The source `decision` link and resolved delegans/delegataris render alongside the chain
- [ ] Implement
- [ ] Test

### Task 3: Build the confidentiality status-timeline widget component
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail`
- **files**: `src/components/widgets/ConfidentialityStatusTimelineWidget.vue`
- **acceptance_criteria**:
  - GIVEN a `geheimhouding` object WHEN the widget mounts THEN it renders exactly three stages — imposed, bekrachtiging, dissolution — each populated when its fields are set and shown as a pending placeholder otherwise
  - GIVEN `ratificationDeadline` in the past and `lifecycle: "imposed"` WHEN rendered THEN the bekrachtiging stage shows an overdue indicator conveyed via icon/text, not colour alone
  - GIVEN a set `ratificationDecision`/`dissolutionDecision` WHEN rendered THEN each shows a working link to that Decision
  - GIVEN the record's `ground` reference WHEN resolved THEN both `citation` and `legacyCitation` (when set) render
  - GIVEN whichever of `targetDocument`/`targetAgendaItem`/`targetDecision` is set WHEN resolved THEN a labelled link to that object's own detail page renders (never a bare UUID)
- [ ] Implement
- [ ] Test

### Task 4: Register the three widgets and wire the import
- **spec_ref**: `openspec/changes/register-detail-optimisation/design.md#d4-declarative-vs-imperative-decision-adr-031`
- **files**: `src/components/widgets/registerDetailWidgets.js`, `src/main.js`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN `registerDetailWidgets.js` runs THEN it calls `registerDashboardWidget()` for `version-timeline`, `delegation-chain`, and `confidentiality-status-timeline`, each with `form: null` and `surfaces: ['detail-page']`
  - GIVEN the dashboard Add-widget picker WHEN opened THEN none of the three new types appear in it (renderer-only, detail-page scoped)
  - GIVEN a manifest detail page declares `{ "type": "version-timeline", ... }` WHEN `CnDetailPage` resolves the widget THEN it renders `RegisterVersionTimelineWidget` (and analogously for the other two types)
- [ ] Implement
- [ ] Test

### Task 5: Wire the version-timeline widget and fix date formatting on the Regulations register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-010-in-force-status-and-cvdr-identifier-are-foregrounded-on-regelingdetail`
- **files**: `src/manifest.d/verordeningenregister.json`
- **acceptance_criteria**:
  - `RegelingDetail`'s widget layout gains a `version-timeline` entry bound to `regeling-versie` via `regeling`'s id, with `decisionRefField` mapped to `vastgesteldDoor`
  - The `regeling-data` widget's field order/overrides foreground `status` and `cvdrIdentifier`
  - The `Regelingen` index's `currentInwerkingtreding` column declares `"format": "date"`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [ ] Implement
- [ ] Test

### Task 6: Wire the version-timeline widget and the current-in-force-date column on the Governing documents register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-010-current-in-force-date-column-on-the-governingdocuments-index`
- **files**: `src/manifest.d/governing-documents-register.json`
- **acceptance_criteria**:
  - `GoverningDocumentDetail`'s widget layout gains a `version-timeline` entry bound to `governing-document-versie`, with `extraFields` covering `aktedatum`/`notaris`
  - The `GoverningDocuments` index gains a current-in-force-date column resolved as a single list-level query (no per-row lookup), with `"format": "date"`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [ ] Implement
- [ ] Test

### Task 7: Wire the delegation-chain widget on the Delegations & mandates register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **files**: `src/manifest.d/delegatie-mandaatregister.json`
- **acceptance_criteria**:
  - `BevoegdheidstoedelingDetail`'s widget layout gains a `delegation-chain` entry bound to `bevoegdheidstoedeling` with `parentRefField: "parentAllocation"`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [ ] Implement
- [ ] Test

### Task 8: Wire the confidentiality status-timeline widget and fix the index column key on the Confidentiality register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-013-geheimhoudingen-index-bekrachtiging-deadline-column-references-the-correct-schema-field`
- **files**: `src/manifest.d/embargo-geheimhouding.json`
- **acceptance_criteria**:
  - `GeheimhoudingDetail`'s widget layout gains a `confidentiality-status-timeline` entry bound to `geheimhouding`
  - The `Geheimhoudingen` index's bekrachtiging-deadline column key changes from `bekrachtigingDeadline` to `ratificationDeadline`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [ ] Implement
- [ ] Test

### Task 9: Cross-page regression and accessibility verification
- **spec_ref**: `openspec/changes/register-detail-optimisation/test-plan.md#tc-18-no-console-errors-or-dead-requests-introduced-across-the-four-detail-pages`
- **files**: none (verification task — browser-driven, no new source files)
- **acceptance_criteria**:
  - GIVEN seeded data across all four registers WHEN each of `RegelingDetail`, `RegelingVersieDetail`, `GoverningDocumentDetail`, `BevoegdheidstoedelingDetail`, `GeheimhoudingDetail` is visited THEN no new console errors or failed requests appear and the existing `data`/`files`/`related` widgets on those pages still render correctly
  - GIVEN keyboard-only + screen-reader navigation WHEN traversing the three new widgets THEN every interactive element (timeline links, chain breadcrumb/children, resolved reference links) is reachable with a correct accessible name (WCAG 2.2 AA)
  - Key flows worth ongoing regression coverage (version-timeline navigation, ondermandaat chain traversal, confidentiality status timeline) are promoted to reusable test scenarios via `/test-scenario-create`
- [ ] Implement
- [ ] Test

## Quality checklist

- All new Vue components covered by component/unit tests (Vitest) for the resolve/sort/walk logic (version ordering, ancestor/child chain walk with cycle safety, three-stage timeline branching)
- Newman/Postman: N/A — no new or changed API endpoints (pure frontend change reading existing OpenRegister endpoints unchanged)
- UI changes covered by Playwright browser tests (test-plan.md TC-1 through TC-18)
- All tests pass (`npm run test`, relevant Playwright suites)
- Feature documentation updated in `docs/` for the four register detail pages' new presentation (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new widget label (ADR-007)
- `openspec validate` passes
