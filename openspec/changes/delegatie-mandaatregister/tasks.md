# Tasks: delegatie-mandaatregister

## Implementation Tasks

### Task 1: Register fragment — schema, lifecycle, publication predicate, notifications
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema`
- **files**: `lib/Settings/register.d/54-delegatie-mandaatregister.json`
- **acceptance_criteria**:
  - GIVEN a clean instance WHEN the register is imported THEN schema `bevoegdheidstoedeling` exists with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys; `concept → van-kracht → ingetrokken | vervallen`), relations (delegans/delegatarisBody→governance-body, delegatarisPersoon→person, besluit/ingetrokkenDoor→decision, parentToedeling→self), the `authorization.read` public predicate on `publicatiedatum <= $now`, and ADR-031-dialect expiry notifications (60d/14d before geldigTot, REQ-DMR-007)
  - GIVEN a toedeling without `besluit`, without any delegataris field, or without any delegans field WHEN saved THEN OR schema validation rejects it (REQ-DMR-001)
  - GIVEN a toedeling in `ingetrokken` WHEN a transition back to `van-kracht` is attempted THEN OR rejects it (undeclared transition, REQ-DMR-002)
- [ ] Implement
- [ ] Test

### Task 2: Seed data — delegatie, mandaat + published ondermandaat chain, concept volmacht, ingetrokken machtiging
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema`
- **files**: `lib/Settings/register.d/54-delegatie-mandaatregister.json` (x-openregister.seedData block)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seed data is planted THEN the 5 objects from the design Seed Data table exist: a raad→college delegatie, a mandaat with `ondermandaatToegestaan: true`, its published ondermandaat (parentToedeling set, depth 1), a `concept` volmacht, and an `ingetrokken` machtiging whose `ingetrokkenDoor` resolves to a seed decision
  - GIVEN the seeded ondermandaat WHEN the register imports THEN its `geldigTot` lies inside the rappel window so the expiry notification path is demonstrable (ADR-016)
- [ ] Implement
- [ ] Test

### Task 3: OndermandaatGuardService — parent permission + acyclic chain, fail closed
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free`
- **files**: `lib/Service/OndermandaatGuardService.php`, `lib/Controller/...` + `appinfo/routes.php` (only if no OR save-hook is available; explicit auth attributes)
- **acceptance_criteria**:
  - GIVEN a parent with `ondermandaatToegestaan: false` WHEN a child references it THEN the save is rejected naming the prohibition; GIVEN a permitting parent THEN the save succeeds
  - GIVEN a self-parent or a cycle (A→B→A) WHEN saved THEN the guard rejects with a cycle error and stored objects are unchanged
  - GIVEN the parent lookup throws WHEN the guard runs THEN the save is rejected (fail closed, never null-swallow)
- [ ] Implement
- [ ] Test

### Task 4: Register views — index/detail manifest pages, filters, "geldig op", search, CSV export
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export`
- **files**: `src/manifest.d/delegatie-mandaatregister.json`
- **acceptance_criteria**:
  - GIVEN seeded toedelingen WHEN filtering per delegans, per delegataris, per type, or per status THEN only matches list; full-text search hits onderwerp/beperkingen/delegataris description
  - GIVEN the "geldig op" date filter set to a boundary date (day of geldigVanaf, day of geldigTot) WHEN applied THEN exactly the in-force set is returned as a pure query (no per-row N+1)
  - GIVEN a filtered list WHEN exported via `CnMassExportDialog` THEN the CSV contains type, delegans, delegataris, onderwerp, financieelPlafond, grondslag, besluit, geldigheid, status; manifest refs use the slug `bevoegdheidstoedeling`, never PascalCase; detail page shows the ondermandaat chain with depth, keyboard-navigable (WCAG 2.1 AA)
- [ ] Implement
- [ ] Test

### Task 5: Public register via the published predicate — live intrekking
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-005-public-register-via-the-or-published-predicate-on-the-live-object`
- **files**: `lib/Settings/register.d/54-delegatie-mandaatregister.json` (predicate), `src/manifest.d/delegatie-mandaatregister.json` (publish/withdraw actions)
- **acceptance_criteria**:
  - GIVEN a published toedeling WHEN an unauthenticated client reads the OR predicate surface THEN it is returned; GIVEN an unpublished one THEN it is not (negative test)
  - GIVEN a published `van-kracht` toedeling WHEN staff revoke it THEN the next anonymous read shows `ingetrokken` without any republish step
  - GIVEN the schema WHEN reviewed THEN it carries no internal-only or writeOnly fields and its `description` records the publishable-by-construction constraint (D4)
- [ ] Implement
- [ ] Test

### Task 6: Assistive bevoegdheidsgrondslag on Decision — display only, never enforcement
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-006-assistive-bevoegdheidsgrondslag-link-on-decision-never-enforcement`
- **files**: `lib/Settings/decidesk_register.json` (one nullable property), `src/manifest.json` or `src/manifest.d/delegatie-mandaatregister.json` (Decision detail display, reverse lookup on toedeling detail)
- **acceptance_criteria**:
  - GIVEN a Decision with bevoegdheidsgrondslag set WHEN its detail renders THEN "genomen krachtens" links to the toedeling detail; the toedeling detail lists referencing decisions via reverse lookup
  - GIVEN a Decision without bevoegdheidsgrondslag, and one referencing an `ingetrokken` toedeling WHEN created/transitioned/enacted THEN no block, error, or warning is raised (negative test — assistive only)
  - GIVEN the base-register edit WHEN diffed THEN only the one property is added; no existing Decision schema content is modified or removed
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria on Postgres (8080) instance
- [ ] Code review against spec requirements (REQ-DMR-001…007)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — guard cycles/permissions/fail-closed, boundary-date filter semantics
- New/changed API endpoints covered by Newman/Postman tests (guard endpoint if introduced; anonymous predicate reads incl. negative case)
- UI changes covered by Playwright browser tests (filters, geldig-op, chain display, publish/withdraw, Decision assistive link)
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` (ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) strings added for all new user-facing strings (ADR-005); Dutch legal terms (mandaat, delegatie, volmacht, machtiging, ondermandaat) stay untranslated domain vocabulary
- `openspec validate` passes
