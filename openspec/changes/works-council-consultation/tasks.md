# Tasks: works-council-consultation

## Implementation Tasks

### Task 1: Register fragment 47 — ConsultationRequest schema with declarative dialects + bodyType enum value
- **spec_ref**: `openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-001-consultationrequest-schema-on-openregister` (+ REQ-WCC-002/REQ-WCC-006/REQ-WCC-007/REQ-WCC-009)
- **files**: `lib/Settings/register.d/47-works-council-consultation.json`, `lib/Settings/decidesk_register.json` (one additive `bodyType` enum value)
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN the `consultation-request` schema exists with all required fields, property titles, and the `x-schema-org` annotation, no existing schema is modified by the fragment, and the GovernanceBody `bodyType` enum includes `works-council`
  - GIVEN the schema WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with the specced states/transitions/terminals (achterbanraadpleging optional, repeat-overleg loop, `ingetrokken` terminal), `x-openregister-notifications` declares the pre-deadline, overdue, opschorting-expiry, and afwijkend-besluit triggers (nl+en subjects) with no imperative dispatch anywhere, and `x-openregister-calculations` derives `opschortingTot` = `besluitDate` + 1 month only for adviesaanvragen with `besluitOutcome=afwijkend-van-advies` (or the documented D3 fallback is applied, never a silently wrong value)
  - GIVEN a create missing `type`, `subject`, `bestuurder`, or `receivedDate` WHEN saved THEN OpenRegister validation rejects it
- [ ] Implement
- [ ] Test

### Task 2: Seed data — realistic Dutch WOR trajecten and the ondernemingsraad body
- **spec_ref**: `openspec/changes/works-council-consultation/design.md#seed-data`
- **files**: `lib/Settings/register.d/47-works-council-consultation.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN the "Ondernemingsraad Voorbeeldingen B.V." governance body (`bodyType=works-council`) exists with Person + Membership members, and 3 consultation-request objects per the design tables (one overdue non-terminal adviesaanvraag, one instemmingsverzoek in achterbanraadpleging, one afgerond adviesaanvraag with response, afwijkend besluit, and derived `opschortingTot`) exist with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded data WHEN the dashboard renders THEN both KPIs are non-zero (ADR-016 testability)
- [ ] Implement
- [ ] Test

### Task 3: Manifest fragment — WOR-trajecten index/detail pages + menu
- **spec_ref**: `openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-008-list-and-detail-pages-plus-dashboard-kpis` (+ REQ-WCC-003/REQ-WCC-004)
- **files**: `src/manifest.d/works-council-consultation.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN the WOR-trajecten index renders with the specced columns and quick filters (type, lifecycle, governance body), and row click opens the detail page (schema ref by slug `consultation-request`, never PascalCase)
  - GIVEN the detail page WHEN opened THEN linked overlegvergadering/agenda item/raadpleging/decision render as navigable references, the Files leaf shows submitted documents, the running opschortingstermijn is surfaced when set, and an empty `achterbanraadpleging` reference renders without error
- [ ] Implement
- [ ] Test

### Task 4: Dashboard KPIs — open trajecten and responses past requested date
- **spec_ref**: `openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-008-list-and-detail-pages-plus-dashboard-kpis`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN "Open WOR-trajecten" counts non-terminal trajecten and "Reactie over gevraagde datum" counts non-terminal trajecten before `verzonden` with `requestedResponseDate` in the past, both via declarative source aggregations (no imperative counting endpoint), and clicking each routes to the pre-filtered index
  - GIVEN the widget filter DSL lacks a relative-now token WHEN implementing THEN the toezeggingen D6-style documented fallback is applied — never a silently wrong count
- [ ] Implement
- [ ] Test

### Task 5: ConsultationResponseDocumentService — formal advies/instemming document generation
- **spec_ref**: `openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage`
- **files**: `lib/Service/ConsultationResponseDocumentService.php`, controller wiring, `appinfo/routes.php`, `tests/Unit/Service/ConsultationResponseDocumentServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a traject in `vastgesteld` with response fields WHEN generation is triggered THEN a markdown document persists to the traject's Files folder, Docudesk PDF renders when available, the document links as `responseDocument`, and the save carries all fields forward (PUT-semantic)
  - GIVEN an instance without Docudesk WHEN generation is triggered with PDF format THEN the markdown persists and the response honestly states the fallback (never a silent fake PDF)
  - GIVEN a user without governance-body scope on the traject WHEN calling the endpoint THEN it is rejected (per-object guard + correct auth attributes — no-admin-idor/semantic-auth/route-reachability gates pass)
- [ ] Implement
- [ ] Test

### Task 6: UI wiring — besluit recording, achterban link, and advisory-stage handoff
- **spec_ref**: `openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn` (+ REQ-WCC-003/REQ-WCC-005)
- **files**: `src/` (detail actions, dialogs in `src/dialogs`/`src/modals` per modal-isolation gate)
- **acceptance_criteria**:
  - GIVEN a traject in `verzonden` WHEN the secretaris records the bestuurder besluit via an explicit dialog THEN `besluitOutcome`/`besluitText`/`besluitDate` are stored with the transition to `besluit-ontvangen`, an afwijkend besluit on an adviesaanvraag shows the derived opschortingstermijn, and the OR-members notification fires declaratively
  - GIVEN a traject in `achterbanraadpleging` WHEN the OR links a constituency-consultation raadpleging THEN it renders as a navigable reference and no poll mechanics are stored on the traject
  - GIVEN a traject with `relatedDecision` set WHEN the formal response is recorded THEN the advisory stage flow (existing `method=advice` semantics) is reachable from the traject detail and no new derivation mechanism is introduced
- [ ] Implement
- [ ] Test

### Task 7: E2E coverage — Playwright scenarios for the WOR traject
- **spec_ref**: `openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md`
- **files**: `tests/e2e/works-council-consultation.spec.ts`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude`
  - GIVEN the seeded environment WHEN the e2e suite runs THEN register → behandel → agendeer op overlegvergadering → stel advies vast → genereer document → verzend → registreer afwijkend besluit (opschorting zichtbaar) → afgerond passes end-to-end, and the KPI deep-links land on correctly pre-filtered indexes
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/wor-trajecten.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle)
- `openspec validate` passes
