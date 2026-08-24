# Tasks: verordeningenregister

## Implementation Tasks

### Task 1: Register fragment — schemas, lifecycles, relations, notifications
- **spec_ref**: `openspec/changes/verordeningenregister/specs/verordeningenregister/spec.md#requirement-req-vor-001-regeling-schema`
- **files**: `lib/Settings/register.d/53-verordeningenregister.json`
- **acceptance_criteria**:
  - GIVEN a clean instance WHEN the register is imported THEN schemas `regeling`, `regeling-versie`, `regeling-export-package` exist with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys), relations (regeling↔versies, versie↔decision, regeling↔governance-body), and ADR-031-dialect notifications (14d/3d inwerkingtreding warnings, REQ-VOR-007)
  - GIVEN a Regeling in `in-voorbereiding` WHEN a transition to `vervallen` is attempted THEN OR rejects it (undeclared transition)
  - GIVEN a RegelingVersie without `vastgesteldDoor` or a Regeling without citeertitel WHEN saved THEN schema validation rejects it (REQ-VOR-002)
  - GIVEN a RegelingVersie in `in-werking` WHEN its inwerkingtreding or consolidated-text reference is edited THEN the write is rejected as sealed (REQ-VOR-003)
- [ ] Implement
- [ ] Test

### Task 2: Seed data — regelingen, version chains, wijzigingsbesluiten
- **spec_ref**: `openspec/changes/verordeningenregister/specs/verordeningenregister/spec.md#requirement-req-vor-002-regelingversie-traced-to-its-amending-decision`
- **files**: `lib/Settings/register.d/53-verordeningenregister.json` (x-openregister.seedData block)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seed data is planted THEN 3 regelingen (verordening/beleidsregel/reglement per design Seed Data tables) exist with 4 regeling-versies whose `vastgesteldDoor` all resolve to seed decisions, incl. the afvalstoffenverordening v1(`vervangen`)→v2(`in-werking`) chain and one `concept` version
  - GIVEN the seeded export package in state `ready` WHEN viewed without OpenConnector THEN the manual-download degradation path is demonstrable
- [ ] Implement
- [ ] Test

### Task 3: RegelingConsolidationService — in-force resolution + activation guard
- **spec_ref**: `openspec/changes/verordeningenregister/specs/verordeningenregister/spec.md#requirement-req-vor-004-in-force-resolution-per-date`
- **files**: `lib/Service/RegelingConsolidationService.php`, `lib/Controller/RegelingController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN versions with inwerkingtreding 2024-01-01/2025-06-01/2026-09-01 WHEN resolving for 2025-12-15 THEN version 2 is returned; for 2023-05-01 THEN "no version in force"; boundary dates (day of inwerkingtreding, day of vervaldatum) resolve per spec
  - GIVEN a latest sealed version at 2026-09-01 WHEN sealing a new version dated 2026-06-01 THEN activation is refused (ordering guard)
  - GIVEN the GET in-force endpoint WHEN called with a malformed date THEN a validation error is returned; route carries explicit auth attributes
- [ ] Implement
- [ ] Test

### Task 4: RegelingExportService — STOP/TPOD package + OpenConnector delivery
- **spec_ref**: `openspec/changes/verordeningenregister/specs/verordeningenregister/spec.md#requirement-req-vor-006-drop-stop-tpod-export-package-via-openconnector`
- **files**: `lib/Service/RegelingExportService.php`, `lib/Service/LogRegelingExportService.php`, `lib/Controller/RegelingExportController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN sealed versies WHEN a package is built THEN STOP/TPOD XML + consolidated texts are bundled, structurally validated, and the package lifecycle follows `building → ready → delivering → delivered | failed`
  - GIVEN a configured OpenConnector Source WHEN delivering THEN the ack reference is stored and the CVDR identifier can be recorded onto the Regeling; GIVEN no OpenConnector THEN the package stays `ready`, is downloadable, and the UI states delivery is unavailable (no fail-open)
  - GIVEN a versie missing its consolidated text WHEN building THEN the package becomes `failed` with stored errors and delivery is refused
- [ ] Implement
- [ ] Test

### Task 5: List/detail pages + version timeline (manifest fragment)
- **spec_ref**: `openspec/changes/verordeningenregister/specs/verordeningenregister/spec.md#requirement-req-vor-008-regelingen-list-and-detail-pages`
- **files**: `src/manifest.d/verordeningenregister.json`, `src/views/regelingen/`
- **acceptance_criteria**:
  - GIVEN twelve seeded/created regelingen WHEN filtering on type `verordening` + status `in-werking` THEN only matches are listed with citeertitel, type, status, and current inwerkingtreding (no N+1 per row)
  - GIVEN a regeling detail page WHEN opened THEN the full version timeline shows states and each wijzigingsbesluit link navigates to the Decision detail page; a "geldend op" date control drives the Task 3 resolution
  - Manifest schema refs use slugs (`regeling`, `regeling-versie`), never PascalCase; WCAG 2.1 AA keyboard navigation on the timeline
- [ ] Implement
- [ ] Test

### Task 6: Public register page of regelingen in force
- **spec_ref**: `openspec/changes/verordeningenregister/specs/verordeningenregister/spec.md#requirement-req-vor-005-public-register-page-of-regelingen-in-force`
- **files**: `src/views/regelingen/PublicRegisterPage.vue`, `lib/Controller/RegelingController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN two `in-werking` and one `in-voorbereiding` regelingen WHEN an anonymous visitor opens the public page THEN exactly the two in-force regelingen are listed with downloadable current consolidated texts
  - GIVEN an in-force regeling with a pending `concept` version WHEN the public payload is built THEN the concept version is structurally absent (negative test)
  - Eligibility enforced server-side via OR published-predicate RBAC; public route uses `#[PublicPage]` with the eligibility gate
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria on Postgres (8080) instance
- [ ] Code review against spec requirements (REQ-VOR-001…008)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — consolidation boundary dates, export validation/degradation, immutability
- New/changed API endpoints covered by Newman/Postman tests (in-force GET, export build/deliver/download, CVDR capture, public page payload)
- UI changes covered by Playwright browser tests (list filters, version timeline, public page, honest-degradation notice)
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` (ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) strings added for all new user-facing strings (ADR-005); Dutch legal terms stay untranslated domain vocabulary
- `openspec validate` passes
