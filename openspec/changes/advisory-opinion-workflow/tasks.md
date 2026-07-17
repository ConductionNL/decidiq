# Tasks: advisory-opinion-workflow

## Implementation Tasks

### Task 1: Register fragment 60 — Adviesaanvraag + Advies schemas with declarative dialects + additive base edits
- **spec_ref**: `openspec/changes/advisory-opinion-workflow/specs/advisory-opinion-workflow/spec.md#requirement-req-aow-001-adviesaanvraag-schema-on-openregister` (+ REQ-AOW-002/REQ-AOW-003/REQ-AOW-006/REQ-AOW-007/REQ-AOW-009)
- **files**: `lib/Settings/register.d/60-advisory-opinion-workflow.json`, `lib/Settings/decidesk_register.json` (additive `advisory-body` bodyType enum value + optional Decision verantwoording fields)
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN the `adviesaanvraag` and `advies` schemas exist with all required fields, property titles, and `x-schema-org` annotations, no existing schema is modified by the fragment, the GovernanceBody `bodyType` enum includes `advisory-body`, and Decision carries the optional verantwoording fields
  - GIVEN the schemas WHEN inspected THEN `x-openregister-lifecycle` on Adviesaanvraag uses the canonical `initial` keyword with the specced states/transitions/terminals (conform shortcut `advies-uitgebracht → afgerond`, `niet-uitgebracht` terminal), `x-openregister-notifications` declares the pre-deadline, overdue, and verantwoording triggers (nl+en subjects) with no imperative dispatch anywhere, and both schemas declare the `authorization.read` public predicate on `publicatiedatum <= $now`
  - GIVEN a create missing a required field on either schema WHEN saved THEN OpenRegister validation rejects it
- [ ] Implement
- [ ] Test

### Task 2: Seed data — advisory bodies and realistic Dutch trajecten
- **spec_ref**: `openspec/changes/advisory-opinion-workflow/design.md#seed-data`
- **files**: `lib/Settings/register.d/60-advisory-opinion-workflow.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN the "Jongerenraad" and "Adviesraad Sociaal Domein" governance bodies (`bodyType=advisory-body`) exist with Person + Membership members, and the 3 adviesaanvraag + 2 advies objects per the design tables (one overdue non-terminal, one advies-uitgebracht wrapping an in-route stage, one afgerond with deviating besluit, verantwoording on both objects, and publication) exist with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded data WHEN the dashboard renders THEN both KPIs are non-zero (ADR-016 testability)
- [ ] Implement
- [ ] Test

### Task 3: Manifest fragment — Adviesaanvragen index/detail pages + menu
- **spec_ref**: `openspec/changes/advisory-opinion-workflow/specs/advisory-opinion-workflow/spec.md#requirement-req-aow-008-list-and-detail-pages-plus-dashboard-kpis` (+ REQ-AOW-004/REQ-AOW-006)
- **files**: `src/manifest.d/advisory-opinion-workflow.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN the Adviesaanvragen index renders with the specced columns and quick filters (advisory body, requesting body, lifecycle), and row click opens the detail page (schema refs by slug `adviesaanvraag`/`advies`, never PascalCase)
  - GIVEN the detail page WHEN opened THEN the question, linked decision/advisory stage/agenda item render as navigable references, the Files leaf shows submitted documents, the recorded advies (strekking, samenvatting, document) and verantwoording render when set, and an out-of-route aanvraag with empty references renders without error
- [ ] Implement
- [ ] Test

### Task 4: Dashboard KPIs — open aanvragen and adviezen awaiting afdoening
- **spec_ref**: `openspec/changes/advisory-opinion-workflow/specs/advisory-opinion-workflow/spec.md#requirement-req-aow-008-list-and-detail-pages-plus-dashboard-kpis`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN "Open adviesaanvragen" counts non-terminal aanvragen and "Adviezen wachtend op afdoening" counts aanvragen in `advies-uitgebracht`, both via declarative source aggregations (no imperative counting endpoint), and clicking each routes to the pre-filtered index
- [ ] Implement
- [ ] Test

### Task 5: AdviceAccountabilityGuard — fail-closed verantwoording on deviation
- **spec_ref**: `openspec/changes/advisory-opinion-workflow/specs/advisory-opinion-workflow/spec.md#requirement-req-aow-005-mandatory-verantwoording-on-deviation-is-fail-closed`
- **files**: `lib/Service/AdviceAccountabilityGuard.php`, wiring into the existing decision status-transition path, `appinfo/routes.php` (only if a thin verantwoording endpoint is needed), `tests/Unit/Service/AdviceAccountabilityGuardTest.php`
- **acceptance_criteria**:
  - GIVEN a decision with a linked advies whose strekking deviates from the intended outcome and no verantwoording WHEN the completing transition runs THEN it is refused with an error naming the aanvraag and the missing motivering, and no partial state is written
  - GIVEN conform outcomes, `geen-advies`, `niet-uitgebracht` trajecten, or no linked aanvraag WHEN the completing transition runs THEN the guard never blocks (full deviation matrix PHPUnit-covered, mutation-guarded — the tests fail against unfixed code)
  - GIVEN the guard cannot resolve the aanvraag/advies WHEN the transition runs THEN it is refused (fail-closed branch tested with a failing relation resolver); any new endpoint carries per-object guards and correct auth attributes (no-admin-idor/semantic-auth/route-reachability gates pass)
- [ ] Implement
- [ ] Test

### Task 6: UI wiring — advies recording, verantwoording dialog, publication actions
- **spec_ref**: `openspec/changes/advisory-opinion-workflow/specs/advisory-opinion-workflow/spec.md#requirement-req-aow-003-advies-artifact-schema` (+ REQ-AOW-005/REQ-AOW-007)
- **files**: `src/` (detail actions, dialogs in `src/dialogs`/`src/modals` per modal-isolation gate)
- **acceptance_criteria**:
  - GIVEN an aanvraag in `in-behandeling` WHEN the advisory body's secretary records the advies via an explicit dialog THEN the `advies` object is created with strekking/samenvatting/date/recordedBy and the aanvraag transitions to `advies-uitgebracht`
  - GIVEN a blocked deviating decision WHEN the griffie records the verantwoording via an explicit dialog THEN the motivering lands on BOTH the Decision fields and the aanvraag (`verantwoordingText` + `verantwoord` transition), the save carries all fields forward (PUT-semantic), and the declarative notification to the advisory body fires
  - GIVEN a staff publish action WHEN `publicatiedatum` is set on the advies/aanvraag THEN the objects become anonymously readable via the OR predicate surface, and a later verantwoording is live-visible without republication
- [ ] Implement
- [ ] Test

### Task 7: E2E coverage — Playwright scenarios for the advisory-opinion traject
- **spec_ref**: `openspec/changes/advisory-opinion-workflow/specs/advisory-opinion-workflow/spec.md`
- **files**: `tests/e2e/advisory-opinion-workflow.spec.ts`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude`
  - GIVEN the seeded environment WHEN the e2e suite runs THEN registreer aanvraag → in behandeling → registreer advies (negatief) → afwijkend besluit geblokkeerd → verantwoording vastgelegd → besluit voltooid → publiceer advies + verantwoording → afgerond passes end-to-end, and the KPI deep-links land on correctly pre-filtered indexes
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/adviesaanvragen.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle)
- `openspec validate` passes
