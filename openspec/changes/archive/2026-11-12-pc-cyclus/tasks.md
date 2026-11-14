# Tasks: pc-cyclus

## Implementation Tasks

### Task 1: Register fragment 52 — PCCyclus, CyclusTemplate, CyclusStap schemas with declarative dialects
- **spec_ref**: `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-001-pccyclus-and-cyclustemplate-schemas-on-openregister` (+ REQ-PCC-003/REQ-PCC-005/REQ-PCC-006)
- **files**: `lib/Settings/register.d/52-pc-cyclus.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `pc-cyclus`, `cyclus-template`, and `cyclus-stap` schemas exist with all required fields, property titles, `x-schema-org` annotations, and declarative progress aggregations, and no existing schema is modified
  - GIVEN the `cyclus-stap` schema WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with `gepland → stukken-ontvangen → in-behandeling → vastgesteld | afgerond` (plus `stukken-ontvangen → afgerond`, both terminals), and `x-openregister-notifications` declares the aanlevering-late and behandeling-unscheduled scheduled rappels (nl+en subjects) with no imperative dispatch anywhere
  - GIVEN a step with a custom template-declared `stepType` WHEN saved THEN validation accepts it (canonical step types are not a closed enum); omitting year/body/template on a cyclus is rejected
- [ ] Implement
- [ ] Test

### Task 2: Seed data — built-in templates plus a municipal P&C year and an association jaarstukken cycle
- **spec_ref**: `openspec/changes/pc-cyclus/design.md#seed-data`, `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-002-built-in-cycle-templates-follow-the-process-configuration-pattern`
- **files**: `lib/Settings/register.d/52-pc-cyclus.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN the `municipal-pc-cyclus` and `association-jaarstukken` built-in templates (`builtIn: true`, full step lists with default dates, subjectYearOffsets, and document slots) plus the two seeded cycli with 5 steps each exist per the design tables, with nil-UUID placeholders only for unresolved refs
  - GIVEN the seeded steps WHEN the dashboard renders THEN at least one non-terminal step is past its aanlever-deadline (KPI non-zero on install) and the begroting step has behandeling dates but no agendaItem (rappel path demoable)
- [ ] Implement
- [ ] Test

### Task 3: Generation service — instantiate steps from template and next-year date shifting
- **spec_ref**: `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-004-steps-are-generated-from-the-template` (+ REQ-PCC-011), `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-002-built-in-cycle-templates-follow-the-process-configuration-pattern`
- **files**: `lib/Service/CyclusGenerationService.php`, `lib/Controller/CyclusController.php`, `appinfo/routes.php`, `tests/Unit/Service/CyclusGenerationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a template and a year WHEN generate runs THEN one CyclusStap per template step is created in order, all `gepland`, with concrete dates resolved from month/day defaults and `betreftJaar = year + subjectYearOffset` (jaarrekening −1, berap 0, kadernota/begroting +1 verified)
  - GIVEN a customised 2026 cyclus WHEN next-year generation runs THEN a 2027 cyclus is created with the source's actual dates shifted +1 year, document slots reset, all steps `gepland`, source unchanged; a duplicate body+year is refused server-side
  - GIVEN a built-in template WHEN edit/delete is attempted THEN it is refused while duplicate yields an editable copy with `builtIn` cleared; generation endpoints carry `#[NoAdminRequired]` plus a per-object governance guard (no-admin-idor/semantic-auth gates pass) and saves are PUT-semantic (all fields carried forward)
- [ ] Implement
- [ ] Test

### Task 4: Manifest fragment — cycli index, cyclus detail, step detail, menu
- **spec_ref**: `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-009-year-view-timeline-per-governance-body`
- **files**: `src/manifest.d/pc-cyclus.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN the P&C-cycli index renders with name/year/body/progress columns and quick filters on year and governance body, and row click opens the cyclus detail (schema refs by slug `pc-cyclus`/`cyclus-stap`, never PascalCase)
  - GIVEN a step detail page WHEN opened THEN all step fields, document slots with their FileService attachments, and navigable agendaItem/decision references render
- [ ] Implement
- [ ] Test

### Task 5: Year-view timeline widget on the cyclus detail
- **spec_ref**: `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-009-year-view-timeline-per-governance-body`
- **files**: `src/widgets/CyclusTimeline.vue` (or equivalent), cyclus detail page wiring
- **acceptance_criteria**:
  - GIVEN the seeded municipal cyclus WHEN the detail opens THEN all steps render in date order across the year with step type, deadlines, technische-vragen window, behandeling targets, status, and links; overdue steps are flagged by icon+text (not colour alone) and the view is keyboard-navigable
  - GIVEN the progress display WHEN rendered THEN it reads `completedStepCount`/`stepCount` from the declarative aggregations and never recomputes client-side; the step list fallback remains a plain manifest grid
- [ ] Implement
- [ ] Test

### Task 6: Dashboard KPI — steps past aanlever-deadline
- **spec_ref**: `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-010-dashboard-kpi-for-steps-past-deadline`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN the "P&C-stappen over deadline" stat widget counts non-terminal steps with a past aanlever-deadline via a declarative source aggregation and clicking routes to the pre-filtered step list
  - GIVEN the widget filter DSL lacks a relative-now token WHEN implementing THEN the documented D6 fallback is applied (never a silently wrong count) and the design open question is resolved in the PR
- [ ] Implement
- [ ] Test

### Task 7: Behandeling linkage and decharge outcome flows
- **spec_ref**: `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md#requirement-req-pcc-007-behandeling-links-to-the-real-agendaitem-and-decision` (+ REQ-PCC-008)
- **files**: `src/` (step detail actions, dialogs in `src/dialogs`/`src/modals` per modal-isolation gate)
- **acceptance_criteria**:
  - GIVEN a step in `stukken-ontvangen` WHEN the griffier links an existing AgendaItem and later the vaststellingsbesluit Decision THEN both render as navigable references, the behandeling-unscheduled rappel condition clears, and no meeting/agenda/decision object is created by this capability
  - GIVEN an association decharge step WHEN resolved THEN its outcome records `decharge-verleend`/`decharge-geweigerd` and its `decision` references the besluit in the normal Decision model (no statutory decision templates — vve-alv-pack boundary respected)
- [ ] Implement
- [ ] Test

### Task 8: E2E coverage — Playwright scenarios for the cycle
- **spec_ref**: `openspec/changes/pc-cyclus/specs/pc-cyclus/spec.md`
- **files**: `tests/e2e/pc-cyclus.spec.ts`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude`
  - GIVEN the seeded environment WHEN the suite runs THEN create-cyclus-from-template → timeline renders → deliver stukken (slot file) → lifecycle to vastgesteld → link agenda item/decision → generate next year passes end-to-end for the municipal template, and the decharge outcome path passes for the association template
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints (generate/next-year) covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/pc-cyclus.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle, custom-widget ratchet justified in design D7)
- `openspec validate` passes
