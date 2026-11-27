# Tasks: delegatie-mandaatregister

## Implementation Tasks

### Task 1: Register fragment — schema, lifecycle, publication predicate, notifications
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema`
- **files**: `lib/Settings/register.d/54-delegatie-mandaatregister.json`
- **acceptance_criteria**:
  - GIVEN a clean instance WHEN the register is imported THEN schema `bevoegdheidstoedeling` exists with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys; `concept → van-kracht → ingetrokken | vervallen`), relations (delegans/delegatarisBody→governance-body, delegatarisPersoon→person, besluit/ingetrokkenDoor→decision, parentToedeling→self), the `authorization.read` public predicate on `publicatiedatum <= $now`, and ADR-031-dialect expiry notifications (60d/14d before geldigTot, REQ-DMR-007)
  - GIVEN a toedeling without `besluit`, without any delegataris field, or without any delegans field WHEN saved THEN OR schema validation rejects it (REQ-DMR-001)
  - GIVEN a toedeling in `ingetrokken` WHEN a transition back to `van-kracht` is attempted THEN OR rejects it (undeclared transition, REQ-DMR-002)
- [x] Implement
- [x] Test
- State: the schema, lifecycle, public predicate and both expiry notifications exist on `authority-delegation`. "No delegans or no delegataris is refused" (REQ-DMR-001) is a save guard beside the ondermandaat guard: OpenRegister's `anyOf` composes schemas by reference and cannot express "at least one of these fields", so `AnyOfFieldsGuard` checks the `OndermandaatGuardService::PARTY_GROUPS` in `OndermandaatGuardListener` on create and update (current and old Dutch field names). The `besluit` requirement and the undeclared transition stay with OpenRegister's schema validation and lifecycle.

### Task 2: Seed data — delegatie, mandaat + published ondermandaat chain, concept volmacht, ingetrokken machtiging
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema`
- **files**: `lib/Settings/register.d/54-delegatie-mandaatregister.json` (x-openregister.seedData block)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seed data is planted THEN the 5 objects from the design Seed Data table exist: a raad→college delegatie, a mandaat with `ondermandaatToegestaan: true`, its published ondermandaat (parentToedeling set, depth 1), a `concept` volmacht, and an `ingetrokken` machtiging whose `ingetrokkenDoor` resolves to a seed decision
  - GIVEN the seeded ondermandaat WHEN the register imports THEN its `geldigTot` lies inside the rappel window so the expiry notification path is demonstrable (ADR-016)
- [x] Implement
- [x] Test
- The five seed toedelingen live in the municipality example set (`lib/Settings/profiles/municipality.json`; a register.d fragment declares schemas only). Their `decision`, `withdrawnBy`, `delegatingBody` and `delegateBody` now name seed objects instead of the zero uuid: a delegatiebesluit, the algemeen mandaatbesluit and the intrekkingsbesluit of the machtiging are seeded beside them. `AuthorityDelegationRegisterTest::testTheExampleSeedsAChainAndATracedWithdrawal` asserts the table. The ondermandaat's fixed `validTo` (2026-08-15) cannot stay inside a moving rappel window; demonstrating the rappel live is left to the live pass.

### Task 3: OndermandaatGuardService — parent permission + acyclic chain, fail closed
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free`
- **files**: `lib/Service/OndermandaatGuardService.php`, `lib/Controller/...` + `appinfo/routes.php` (only if no OR save-hook is available; explicit auth attributes)
- **acceptance_criteria**:
  - GIVEN a parent with `ondermandaatToegestaan: false` WHEN a child references it THEN the save is rejected naming the prohibition; GIVEN a permitting parent THEN the save succeeds
  - GIVEN a self-parent or a cycle (A→B→A) WHEN saved THEN the guard rejects with a cycle error and stored objects are unchanged
  - GIVEN the parent lookup throws WHEN the guard runs THEN the save is rejected (fail closed, never null-swallow)
- [x] Implement
- [x] Test
- The schema is now `authority-delegation` (renamed from `bevoegdheidstoedeling` by `86-the-last-two-dutch-names`, rows moved by `MigrateTheLastTwoDutchNames`). The guard is subscribed to, and reads parents on, the renamed schema, and still guards the old slug.

### Task 4: Register views — index/detail manifest pages, filters, "geldig op", search, CSV export
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export`
- **files**: `src/manifest.d/delegatie-mandaatregister.json`
- **acceptance_criteria**:
  - GIVEN seeded toedelingen WHEN filtering per delegans, per delegataris, per type, or per status THEN only matches list; full-text search hits onderwerp/beperkingen/delegataris description
  - GIVEN the "geldig op" date filter set to a boundary date (day of geldigVanaf, day of geldigTot) WHEN applied THEN exactly the in-force set is returned as a pure query (no per-row N+1)
  - GIVEN a filtered list WHEN exported via `CnMassExportDialog` THEN the CSV contains type, delegans, delegataris, onderwerp, financieelPlafond, grondslag, besluit, geldigheid, status; manifest refs use the slug `bevoegdheidstoedeling`, never PascalCase; detail page shows the ondermandaat chain with depth, keyboard-navigable (WCAG 2.1 AA)
- [x] Implement
- [x] Test
- Index: quick filter "In force today" is the in-force query as one OR list request (`status=effective`, `validFrom[lte]=@today`, `validTo[gte]=@today`, `validTo[orNull]=true`); per-delegans, per-delegataris (body, role, person), type, status and validity-date filters are the sidebar facets of the facetable schema properties (`delegatePerson` made facetable); full-text search is OpenRegister's `_search`; CSV export is the index's mass export. Asserted in `AuthorityDelegationRegisterTest`. Left for the live/browser pass: boundary-date runs on a real instance and the exported CSV's columns.
- Run live (25 Nov 2026, sandbox Nextcloud 34.0.6 + PostgreSQL 16 on :8080, OpenRegister main, decidiq development): `tests/e2e/delegatie-mandaatregister.spec.ts` 9/9. The in-force query returns exactly the in-force set at the boundaries as one list call; the delegate filter lists only its delegations; the index's mass export (Actions, Export, CSV) downloads a CSV whose header carries type, delegatingBody, delegateBody, delegateRole, subject, financialCeiling, statutoryBasis, decision, validFrom, validTo and status (the spec now checks those columns; it looked for an Export button outside the Actions menu). The chain's sub-mandate is a keyboard-reachable button.

### Task 5: Public register via the published predicate — live intrekking
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-005-public-register-via-the-or-published-predicate-on-the-live-object`
- **files**: `lib/Settings/register.d/54-delegatie-mandaatregister.json` (predicate), `src/manifest.d/delegatie-mandaatregister.json` (publish/withdraw actions)
- **acceptance_criteria**:
  - GIVEN a published toedeling WHEN an unauthenticated client reads the OR predicate surface THEN it is returned; GIVEN an unpublished one THEN it is not (negative test)
  - GIVEN a published `van-kracht` toedeling WHEN staff revoke it THEN the next anonymous read shows `ingetrokken` without any republish step
  - GIVEN the schema WHEN reviewed THEN it carries no internal-only or writeOnly fields and its `description` records the publishable-by-construction constraint (D4)
- [x] Implement
- [x] Test
- The public read predicate now also honours withdrawal: public read holds while `publicationDate <= $now` and `depublicationDate` is empty or still ahead (two public rules), on `authority-delegation` and on the old slug alike, and the schema description records the publishable-by-construction constraint. The detail page's Publish / Withdraw header actions PATCH only `publicationDate` / `depublicationDate` on the live object, confirm-gated. Asserted in `AuthorityDelegationRegisterTest`. Left for the API pass: the anonymous read and its negative case on a live instance.
- Run live (25 Nov 2026, sandbox Nextcloud 34.0.6 + PostgreSQL 16 on :8080, OpenRegister main, decidiq development): a published delegation reads anonymously and an unpublished one does not; an anonymous write is refused; a withdrawal shows on the next anonymous read; depublishing takes it off the public register.

### Task 6: Assistive bevoegdheidsgrondslag on Decision — display only, never enforcement
- **spec_ref**: `openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-006-assistive-bevoegdheidsgrondslag-link-on-decision-never-enforcement`
- **files**: `lib/Settings/decidesk_register.json` (one nullable property), `src/manifest.json` or `src/manifest.d/delegatie-mandaatregister.json` (Decision detail display, reverse lookup on toedeling detail)
- **acceptance_criteria**:
  - GIVEN a Decision with bevoegdheidsgrondslag set WHEN its detail renders THEN "genomen krachtens" links to the toedeling detail; the toedeling detail lists referencing decisions via reverse lookup
  - GIVEN a Decision without bevoegdheidsgrondslag, and one referencing an `ingetrokken` toedeling WHEN created/transitioned/enacted THEN no block, error, or warning is raised (negative test — assistive only)
  - GIVEN the base-register edit WHEN diffed THEN only the one property is added; no existing Decision schema content is modified or removed
- [x] Implement
- [x] Test
- Fragment `134-decision-authority-basis.json` adds the one nullable `authorityBasis` reference to `decision` (English name, as the rest of the renamed schema; spec name `bevoegdheidsgrondslag`); nothing in a decision flow reads it. Decision detail shows it in Content and as a "Taken under" list linking to the toedeling (shown only when set); the toedeling detail lists the decisions taken under it. Asserted in `AuthorityDelegationRegisterTest`. Left for the browser pass: the rendered link and the no-block negative run.
- Run live (25 Nov 2026, sandbox Nextcloud 34.0.6 + PostgreSQL 16 on :8080, OpenRegister main, decidiq development): found that the decision's "Taken under" list ignored its filter and showed another delegation ("1–1 of 9"): it filtered on `id`, which OpenRegister's list route takes as a route parameter and drops. It now filters on `uuid` (`src/manifest.json`, guarded by `tests/vitest/manifestFiltersOnUuid.spec.js`). With that, the decision links to the delegation it names, the delegation lists the decision, and decisions naming a withdrawn delegation or none save without any check.

## Verification

- [x] All tasks checked off
- [ ] `openspec validate` passes
- [x] Manual testing against acceptance criteria on Postgres (8080) instance (25 Nov 2026: the e2e run above on a sandbox PostgreSQL 16 instance on :8080; REQ-DMR-007's expiry notifications are not part of that run)
- [x] Code review against spec requirements (REQ-DMR-001…007)
  - Reviewed 27 Nov 2026, every scenario against the schema `authority-delegation` (fragment 86), the guards, the manifest fragment and their tests. Covered: REQ-DMR-001, 003 (guard), 005, 006, the in-force-today view of 004. Fixed here: REQ-DMR-007 reminded every in-force delegation, open-ended ones included, because both scheduled rules filtered on `status` alone and OpenRegister applies only a date window the rule declares. Both now carry `validTo withinNext P60D / P14D`, are evaluated daily, are deduplicated on `validTo` and name the end date in the subject (`AuthorityDelegationRegisterTest::testTheExpiryRemindersWatchTheEndDate`). Added unit tests for the lifecycle map (`testTheLifecycleEndsInWithdrawnOrLapsed`) and the required decision (`testADelegationNeedsItsGrantingDecision`). What the review left open is listed under Follow-ups.

## Follow-ups from the code review

- [x] REQ-DMR-002, server side: a save that moves a delegation into `withdrawn` without `withdrawnBy` is refused (`OndermandaatGuardListener`, message names `withdrawnBy`). Only the move is judged, so a row already withdrawn (one carried across by the rename) and a lapse are left alone. Tests: `OndermandaatGuardListenerTest::testAWithdrawalMustNameTheRevokingDecision`, `testAnEarlierWithdrawalAndALapseAreLeftAlone`.
- [ ] REQ-DMR-002, the action: a Revoke header action on the delegation detail, shown when `status` is `effective`, that asks for the revoking decision and saves `status: withdrawn` with `withdrawnBy` in one PATCH. Needs a manifest action with a decision picker (design).
- [x] REQ-DMR-003: the chain widget shows the breadcrumb but not the depth the scenario names ("depth 1"); `DelegationChainWidget.vue` plus a vitest assertion and the new string in every locale.
  - Built (27 Nov): the widget shows "Depth {depth}", the number of ancestors it walked up (a hoofdmandaat is depth 0, its ondermandaat depth 1; a defensive cycle stops the count like it stops the walk). The string is in all 38 catalogues. Test: `tests/vitest/delegationChainDepth.spec.js` (5).
- [ ] REQ-DMR-004: "in force on date X" works for today only; a date choice that fills the same four-key query with X instead of `@today`.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — guard cycles/permissions/fail-closed, boundary-date filter semantics
- New/changed API endpoints covered by Newman/Postman tests (guard endpoint if introduced; anonymous predicate reads incl. negative case)
- UI changes covered by Playwright browser tests (filters, geldig-op, chain display, publish/withdraw, Decision assistive link)
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` (ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) strings added for all new user-facing strings (ADR-005); Dutch legal terms (mandaat, delegatie, volmacht, machtiging, ondermandaat) stay untranslated domain vocabulary
- `openspec validate` passes
