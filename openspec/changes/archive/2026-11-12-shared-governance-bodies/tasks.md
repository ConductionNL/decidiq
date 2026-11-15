# Tasks: shared-governance-bodies

## Implementation Tasks

### Task 1: Register fragment 56 — three schemas with declarative dialects + two additive base edits
- **spec_ref**: `openspec/changes/shared-governance-bodies/specs/shared-governance-bodies/spec.md#requirement-req-sgb-001-bodyparticipation-schema-on-openregister` (+ REQ-SGB-002/REQ-SGB-004/REQ-SGB-005/REQ-SGB-009)
- **files**: `lib/Settings/register.d/56-shared-governance-bodies.json`, `lib/Settings/decidesk_register.json` (additive `shared-body` bodyType enum value + optional Membership `namens` property)
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN the `body-participation`, `zienswijzeronde`, and `zienswijze` schemas exist with required fields, property titles, and `x-schema-org` annotations, and no existing schema is modified by the fragment
  - GIVEN the base file WHEN diffed against the merge base THEN the only changes are the added `shared-body` enum value and the added optional `namens` property (union merge with the works-council sibling's `works-council` value — never drop a sibling's addition)
  - GIVEN the schemas WHEN inspected THEN both lifecycles use the canonical `initial` keyword with the specced states/transitions/terminals, and `x-openregister-notifications` on Zienswijze declares the deadline-approaching, deadline-passed, and created triggers (nl+en subjects) with no imperative dispatch anywhere
  - GIVEN a create missing `sharedBody`/`participant` (participation) or `title`/`sharedBody`/`subjectType`/`deadline` (ronde) or `ronde`/`participant` (zienswijze) WHEN saved THEN OpenRegister validation rejects it
- [ ] Implement
- [ ] Test

### Task 2: Seed data — three-municipality GR (SED-style) with participations, provenance, and a running zienswijzeronde
- **spec_ref**: `openspec/changes/shared-governance-bodies/design.md#seed-data`
- **files**: `lib/Settings/register.d/56-shared-governance-bodies.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN the "Bestuur NOZ organisatie" shared body (`bodyType=shared-body`), three municipal councils, three BodyParticipations (seats/weights 2/2/1, toetredingsDatum set), and two board memberships carrying `namens` exist per the design tables
  - GIVEN the seeded rondes WHEN listed THEN ronde 1 (ontwerpbegroting, `verwerking`, past deadline, pc-cyclus placeholder link) has two `verwerkt` and one `niet-ingediend` zienswijzen, and ronde 2 (`geopend`) has one `uitstaand` zienswijze so the dashboard KPI is non-zero (ADR-016 testability)
- [ ] Implement
- [ ] Test

### Task 3: ZienswijzerondeService — idempotent fan-out generation + deadline propagation + endpoint
- **spec_ref**: `openspec/changes/shared-governance-bodies/specs/shared-governance-bodies/spec.md#requirement-req-sgb-005-zienswijze-schema-and-per-participant-generation-on-opening`
- **files**: `lib/Service/ZienswijzerondeService.php`, controller wiring, `appinfo/routes.php`, `tests/Unit/Service/ZienswijzerondeServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a ronde in `concept` on a shared body with three active and one withdrawn participation WHEN openRonde runs THEN exactly three zienswijzen are created in `uitstaand` with the ronde's deadline copied, and the ronde transitions to `geopend` through the declared lifecycle map
  - GIVEN a retry after partial failure WHEN openRonde runs again THEN no duplicate zienswijze exists for any ronde + participant pair
  - GIVEN a moved ronde deadline WHEN propagation runs THEN non-terminal zienswijzen carry the new date, terminal ones are untouched, and every save carries all fields forward (PUT-semantic)
  - GIVEN a user without governance-body scope on the shared body WHEN calling the endpoint THEN it is rejected (per-object guard + correct auth attributes — no-admin-idor/semantic-auth/route-reachability gates pass)
- [ ] Implement
- [ ] Test

### Task 4: Manifest fragment — zienswijzeronde index/detail, zienswijzen index, menu
- **spec_ref**: `openspec/changes/shared-governance-bodies/specs/shared-governance-bodies/spec.md#requirement-req-sgb-010-participation-and-zienswijze-views-plus-dashboard-kpi` (+ REQ-SGB-006/REQ-SGB-007)
- **files**: `src/manifest.d/shared-governance-bodies.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN the zienswijzerondes index renders with filters on sharedBody/subjectType/status, and the ronde detail shows the aggregated zienswijzen overview (participant, status, standpunt, ingediendDatum, verwerking) via reverse lookup, with Files leaf and navigable cyclusStap/decision references (schema refs by slug, never PascalCase)
  - GIVEN the zienswijzen index WHEN filtered on a participant organisation THEN only that organisation's zienswijzen list with status and deadline
- [ ] Implement
- [ ] Test

### Task 5: Base manifest edits — GovernanceBodyDetail participation section + dashboard KPI
- **spec_ref**: `openspec/changes/shared-governance-bodies/specs/shared-governance-bodies/spec.md#requirement-req-sgb-010-participation-and-zienswijze-views-plus-dashboard-kpi` (+ REQ-SGB-003)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the seeded shared body WHEN its detail page opens THEN the "Deelnemende organisaties" section lists all participations (participant, seats, votingWeight, toetreding/uittreding) via reverse lookup on `sharedBody`, and the roster shows each member's namens-organisation
  - GIVEN seeded data WHEN the dashboard renders THEN "Openstaande zienswijzen" counts `uitstaand`/`in-voorbereiding` zienswijzen via a declarative source aggregation (no imperative counting endpoint) and deep-links to the pre-filtered zienswijzen index
- [ ] Implement
- [ ] Test

### Task 6: UI wiring — zienswijze recording, verwerking, and membership weight prefill
- **spec_ref**: `openspec/changes/shared-governance-bodies/specs/shared-governance-bodies/spec.md#requirement-req-sgb-006-participant-organisation-records-its-zienswijze` (+ REQ-SGB-007/REQ-SGB-008)
- **files**: `src/` (detail actions, dialogs in `src/dialogs`/`src/modals` per modal-isolation gate)
- **acceptance_criteria**:
  - GIVEN a zienswijze in `uitstaand`/`in-voorbereiding` WHEN the participant records standpunt, text, ingediendDatum, attaches the response document, and optionally links its raadsbesluit THEN the object transitions to `ingediend` with all fields stored; marking a lapsed one moves it to `niet-ingediend`
  - GIVEN an `ingediend` zienswijze WHEN the shared body records verwerking + toelichting and links the ronde's vaststellingsbesluit THEN the zienswijze is `verwerkt` and the ronde can close `verwerking → afgerond`
  - GIVEN a new Membership in the shared body with `namens` set WHEN the form renders THEN the participation's votingWeight is suggested as an overridable default, and no vote-computation path reads BodyParticipation (REQ-MAT-006 machinery unchanged)
- [ ] Implement
- [ ] Test

### Task 7: E2E coverage — Playwright scenarios for the zienswijzeprocedure
- **spec_ref**: `openspec/changes/shared-governance-bodies/specs/shared-governance-bodies/spec.md`
- **files**: `tests/e2e/shared-governance-bodies.spec.ts`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude`
  - GIVEN the seeded environment WHEN the e2e suite runs THEN open ronde → fan-out to three organisaties → dien zienswijze in (met raadsbesluit-link) → markeer niet-ingediend → verwerk zienswijzen → koppel vaststellingsbesluit → rond ronde af passes end-to-end, and the KPI deep-link lands on the correctly pre-filtered index
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/gemeenschappelijke-regelingen.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle)
- `openspec validate` passes
