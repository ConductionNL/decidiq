# Tasks: toezeggingen-ingekomen-stukken

## Implementation Tasks

### Task 1: Register fragment — Toezegging + IngekomenStuk schemas with declarative dialects
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-001-toezegging-schema-on-openregister` (+ REQ-002/REQ-004/REQ-005), `openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md#requirement-req-001-ingekomenstuk-schema-on-openregister` (+ REQ-002)
- **files**: `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `toezegging` and `ingekomen-stuk` schemas exist with all required fields, property titles, and `x-schema-org` annotations, and no existing schema is modified
  - GIVEN both schemas WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with the specced states/transitions/terminals, and `x-openregister-notifications` declares created/afgedaan triggers plus scheduled pre-deadline and overdue rappels (nl+en subjects) with no imperative dispatch anywhere
  - GIVEN a Toezegging with `publicatiedatum` in the past WHEN read anonymously via the OR predicate surface THEN it is returned; without the predicate it is not
  - GIVEN an IngekomenStuk with routingAdvice `betrekken-bij-agendapunt` and no targetAgendaItem WHEN saved THEN validation rejects it
- [ ] Implement
- [ ] Test

### Task 2: Seed data — realistic Dutch municipal objects for both schemas
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/design.md#seed-data`
- **files**: `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 3 toezeggingen (one overdue+open, one in-uitvoering, one afgedaan with afdoening note + relatedMotion) and 4 ingekomen stukken (mixed senderType/category/lifecycle per design tables) exist, linked to seeded meeting/body/agenda-item objects with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded LIS agenda item (tagged `hamerstuk`) on the upcoming raadsvergadering WHEN opened THEN the placed stukken are visible, so placement and bulk-confirm are demoable on install
- [ ] Implement
- [ ] Test

### Task 3: Manifest fragment — index/detail pages + menu for both registers
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-006-list-page-detail-page-and-csv-export`, `openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md#requirement-req-006-list-page-detail-page-and-export`
- **files**: `src/manifest.d/toezeggingen-ingekomen-stukken.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN Toezeggingen and IngekomenStukken index pages render with the specced columns and quick filters, and row click opens the detail pages (schema refs by slug `toezegging` / `ingekomen-stuk`, never PascalCase)
  - GIVEN a detail page WHEN opened THEN linked meeting/agenda-item/motion render as navigable references, Files leaf and audit-trail sidebar are present, and CSV export works via the mass-export dialog on both indexes
- [ ] Implement
- [ ] Test

### Task 4: Dashboard KPI — open toezeggingen past deadline
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-007-dashboard-kpi-for-overdue-open-commitments`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN the "Open toezeggingen over deadline" stat widget shows the count of non-terminal toezeggingen with a past deadline via a declarative source aggregation, and clicking routes to the pre-filtered Toezeggingen index
  - GIVEN the widget filter DSL lacks a relative-now token WHEN implementing THEN the documented D6 fallback is applied (never a silently wrong count) and the design open question is resolved in the PR
- [ ] Implement
- [ ] Test

### Task 5: Publication extension — IngekomenStuk eligibility + anonymised payload
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md#requirement-req-005-public-publication-with-woo-aware-anonymisation`, `openspec/changes/toezeggingen-ingekomen-stukken/specs/public-publication/spec.md#requirement-publication-eligibility-gates`
- **files**: `lib/Service/PublicationEligibilityService.php`, `lib/Service/PublicationPayloadService.php`, `lib/Controller/PublicationController.php`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN an IngekomenStuk in `geregistreerd`/`geagendeerd` WHEN publish is requested THEN it is refused server-side; in `routering-vastgesteld`/`afgedaan` with a public meeting THEN an immutable allow-list payload is created and predicate-published (OpenCatalogi routed when configured)
  - GIVEN senderType `natuurlijk-persoon` WHEN the payload is built THEN sender renders as "Inwoner" and the person's name/contact appear nowhere in the payload (PHPUnit asserts absence AND flips when senderType changes to `organisatie` — mutation-guarded, no fake green)
- [ ] Implement
- [ ] Test

### Task 6: Routing service — place-on-list and chair bulk confirmation
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md#requirement-req-003-placement-on-the-lijst-ingekomen-stukken-agenda-item` (+ REQ-004)
- **files**: `lib/Service/IngekomenStukRoutingService.php`, `appinfo/routes.php`, controller wiring, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN stukken in `geregistreerd` WHEN the griffie places them on the LIS agenda item of the next meeting THEN each stuk references it and becomes `geagendeerd` (saveObject carries all fields forward — PUT-semantic)
  - GIVEN an opened meeting WHEN the chair bulk-confirms THEN all placed `geagendeerd` stukken become `routering-vastgesteld`; a pulled stuk becomes `aangehouden` and is excluded; a mid-batch failure reports per-stuk and re-run confirms only the remainder (idempotent)
  - GIVEN a non-chair member WHEN calling the bulk-confirm endpoint THEN it is rejected (per-object governance guard, correct auth attributes — no-admin-idor/semantic-auth gates pass)
- [ ] Implement
- [ ] Test

### Task 7: UI wiring — placement/bulk-confirm actions and toezegging publish/afdoening flows
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md#requirement-req-004-bulk-council-confirmation-via-the-hamerstuk-flow`, `openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate`
- **files**: `src/` (list actions, dialogs in `src/dialogs`/`src/modals` per modal-isolation gate, LIS agenda-item tab)
- **acceptance_criteria**:
  - GIVEN the IngekomenStukken index WHEN the griffie selects stukken THEN a placement action targets the next meeting's LIS item; the LIS agenda-item detail shows placed stukken with advice and (for the chair in an opened meeting) the bulk-confirm control with confirmation dialog; controls hidden for members
  - GIVEN a ToezeggingDetail page WHEN staff publish/withdraw or record afdoening (note + evidence) THEN the actions use explicit dialogs and the public list reflects lifecycle live
- [ ] Implement
- [ ] Test

### Task 8: E2E coverage — Playwright scenarios for both registers
- **spec_ref**: `openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md`, `openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md`
- **files**: `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed specs THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude` (eligibility/payload contracts excluded to PHPUnit/Newman as specced)
  - GIVEN the seeded environment WHEN the e2e suite runs THEN register → place → bulk-confirm → publish (ingekomen stuk) and register → rappel-visible → afdoen → public list (toezegging) pass end-to-end
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/toezeggingen.md` and `docs/features/ingekomen-stukken.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle)
- `openspec validate` passes
