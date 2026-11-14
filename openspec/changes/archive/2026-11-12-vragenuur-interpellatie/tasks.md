# Tasks: vragenuur-interpellatie

## Implementation Tasks

### Task 1: Register fragment 49 — MondelingeVraag, Interpellatieverzoek, VragenuurConfiguratie with declarative dialects
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/specs/mondelinge-vragen-register/spec.md#requirement-req-vri-001-mondelingevraag-schema-on-openregister` (+ REQ-VRI-002/REQ-VRI-003/REQ-VRI-007), `openspec/changes/vragenuur-interpellatie/specs/interpellatie-register/spec.md#requirement-req-vri-009-interpellatieverzoek-schema-on-openregister` (+ REQ-VRI-010/REQ-VRI-014)
- **files**: `lib/Settings/register.d/49-vragenuur-interpellatie.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `mondelinge-vraag`, `interpellatieverzoek`, and `vragenuur-configuratie` schemas exist with all required fields, property titles, and `x-schema-org` annotations, and no existing schema is modified (fragment number 49 exactly; 40–48/50–65 untouched)
  - GIVEN both lifecycle declarations WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with the specced states/transitions/terminals, and `x-openregister-notifications` declares created/admission/scheduling triggers with nl+en subjects and no imperative dispatch anywhere (gate-18)
  - GIVEN schema RBAC WHEN a raadslid creates and a non-griffie user attempts an admission transition THEN creation succeeds and the transition is refused by OR authorization
- [ ] Implement
- [ ] Test

### Task 2: Seed data — realistic Dutch municipal objects for all three schemas
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/design.md#seed-data`
- **files**: `lib/Settings/register.d/49-vragenuur-interpellatie.json` (seedData section)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 1 vragenuur-configuratie, 4 mondelinge vragen (beantwoord-with-escalation+toezegging, ingepland, ingediend, afgewezen-with-reason), and 3 interpellatieverzoeken (behandeld, ingediend below threshold, geagendeerd) exist per the design tables, with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded raadsvergadering WHEN opened THEN it carries a "Vragenuur" agenda item with the two scheduled questions and the geagendeerd interpellation's own agenda item, so admission/scheduling/answer flows are demoable on install (ADR-016)
- [ ] Implement
- [ ] Test

### Task 3: OralQuestionService — numbering, submission deadline, override
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/specs/mondelinge-vragen-register/spec.md#requirement-req-vri-003-per-body-vragenuur-configuration-and-submission-deadline` (+ REQ-VRI-001 numbering, REQ-VRI-009 numbering)
- **files**: `lib/Service/OralQuestionService.php`, `appinfo/routes.php`, controller wiring, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN a submission WHEN accepted THEN `MV-{jaar}-{volgnummer}` / `INT-{jaar}-{volgnummer}` is assigned from a per-body per-year sequence (uniqueness tested, collision retried)
  - GIVEN a target meeting starting within the body's `indieningstermijnUren` WHEN a raadslid submits THEN the submission is refused server-side; WHEN the griffier submits with the explicit override flag THEN it is created and the override is auditable
  - GIVEN the endpoints WHEN gated THEN auth attributes match the actual requirement with per-object guards (no-admin-idor/semantic-auth gates pass); no CRUD pass-through wrappers exist (redundant-controller gate)
- [ ] Implement
- [ ] Test

### Task 4: Escalation and answer side effects — SV status flip and toezegging pre-fill
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/specs/mondelinge-vragen-register/spec.md#requirement-req-vri-006-escalation-linkage-between-written-and-oral-questions` (+ REQ-VRI-005)
- **files**: `lib/Service/OralQuestionService.php`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN a MondelingeVraag with `bronSchriftelijkeVraag` WHEN it reaches `beantwoord` THEN the linked SchriftelijkeVraag becomes `vervallen-door-mondelinge-beantwoording` via a PUT-semantic save, and a unit test asserts an unrelated SV field survives; WHEN no bron is set THEN no SV is touched
  - GIVEN an answer with a commitment WHEN the griffier registers the follow-up THEN a Toezegging is created pre-filled (meeting, agendaItem, madeBy) in the toezeggingen register and linked via `vervolgToezegging`, with no execution log on the question
  - GIVEN escalation creation in either direction WHEN triggered from an SV or from a niet-behandeld/beantwoord MV THEN the new object is pre-filled and cross-linked per spec
- [ ] Implement
- [ ] Test

### Task 5: Manifest fragment — index/detail pages + menu for both registers
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/specs/mondelinge-vragen-register/spec.md#requirement-req-vri-008-list-and-detail-pages-for-oral-questions`, `openspec/changes/vragenuur-interpellatie/specs/interpellatie-register/spec.md#requirement-req-vri-014-notifications-and-listdetail-pages-for-interpellations`
- **files**: `src/manifest.d/vragenuur-interpellatie.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN Mondelinge vragen and Interpellaties index pages render with the specced columns and quick filters, and row click opens the detail pages (schema refs by slug `mondelinge-vraag` / `interpellatieverzoek`, never PascalCase — gates 28/30/51/52)
  - GIVEN a detail page WHEN opened THEN linked meeting/agenda-item/SV/toezegging render as navigable references, the interpellation detail shows support status against the threshold, and Files leaf + audit-trail sidebar are present
- [ ] Implement
- [ ] Test

### Task 6: UI wiring — submission, admission, scheduling, answer, support, and treatment dialogs
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/specs/mondelinge-vragen-register/spec.md#requirement-req-vri-004-scheduling-into-the-vragenuur-agenda-item` (+ REQ-VRI-005), `openspec/changes/vragenuur-interpellatie/specs/interpellatie-register/spec.md#requirement-req-vri-011-support-recording-against-the-per-body-threshold` (+ REQ-VRI-012/REQ-VRI-013)
- **files**: `src/` (page actions; dialogs in `src/dialogs`/`src/modals` per modal-isolation gate)
- **acceptance_criteria**:
  - GIVEN a griffier on an `ingediend` question WHEN admitting/rejecting THEN explicit dialogs capture the decision (reason mandatory on rejection); scheduling assigns vragenuurAgendaItem + volgorde; answer recording captures summary + optional toezegging follow-up; controls hidden for plain members
  - GIVEN an `ingediend` interpellatieverzoek WHEN raadsleden record support THEN the threshold display updates ("N of M required", ceil rounding); admission records raadsbesluitDatum; scheduling links its own agenda item; treatment captures the behandelingsverslag
  - GIVEN a niet-behandeld question WHEN re-scheduled THEN the carry-over flow works from the detail page
- [ ] Implement
- [ ] Test

### Task 7: Publication extension — MV/INT eligibility and payloads
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/specs/public-publication/spec.md#requirement-req-vri-015-publication-of-oral-questions-and-interpellation-requests`
- **files**: `lib/Service/PublicationEligibilityService.php`, `lib/Service/PublicationPayloadService.php`, `lib/Controller/PublicationController.php`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN an `afgewezen`/`ingetrokken` MV or an `ingediend` INT WHEN publish is requested THEN it is refused server-side; eligible lifecycles produce allow-list payloads (answer fields only once answered/treated) routed via the existing predicate/OpenCatalogi machinery
  - GIVEN an interpellation payload WHEN built THEN it carries the support count and no individual supporter reference (PHPUnit asserts absence AND the count flips when a supporter is added — mutation-guarded, no fake green)
- [ ] Implement
- [ ] Test

### Task 8: E2E coverage — Playwright scenarios for both registers
- **spec_ref**: `openspec/changes/vragenuur-interpellatie/specs/mondelinge-vragen-register/spec.md`, `openspec/changes/vragenuur-interpellatie/specs/interpellatie-register/spec.md`
- **files**: `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed specs THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude` (contract scenarios excluded to PHPUnit/Newman as specced)
  - GIVEN the seeded environment WHEN the e2e suite runs THEN submit → admit → schedule → answer-with-toezegging → SV lapses (mondelinge vraag) and submit → support-to-threshold → council admission → own agenda item → treatment (interpellatie) pass end-to-end
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/vragenuur.md` and `docs/features/interpellaties.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle)
- `openspec validate` passes
