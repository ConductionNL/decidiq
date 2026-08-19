# Tasks: model-debt-cleanup-schema

## Implementation Tasks

### Task 1: Decision declares its meeting and agendaItem joins
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-022-decision-declares-its-meeting-and-agendaitem-joins`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json` (new)
- **acceptance_criteria**:
  - GIVEN the new fragment overrides `Decision.properties` WHEN the register is imported THEN `decision` gains optional `meeting` ($ref `Meeting`, `facetable: true`) and `agendaItem` ($ref `AgendaItem`, `facetable: true`)
  - Bump `Decision`'s own `version` field in the override
- [x] Implement
- [x] Test

### Task 1b: Person gains optional nextcloudUserId (judge amendment 2026-08-19)
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/design.md` (Judge amendment in the Data-migration section)
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register is imported THEN `Person` gains an optional, nullable `nextcloudUserId` (string) described as the Nextcloud account linkage carried over from the retired Participant shim
  - The code chain's crosswalk match order becomes nextcloudUserId-exact → email-exact → create-new, and the repair step copies `Participant.nextcloudUserId` onto the matched-or-created Person (recorded here; implemented in `model-debt-cleanup-code`)
- [x] Implement
- [x] Test

### Task 2: ConflictOfInterest.boardMember retargets to Membership; Participant description narrowed
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-023-conflictofinterestboardmember-references-membership-not-the-participant-shim`, `openspec/changes/model-debt-cleanup-schema/specs/participant-crud/spec.md#requirement-req-pcr-010--participant-schema-deprecated-in-favour-of-person--membership`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json`
- **acceptance_criteria**:
  - GIVEN the fragment overrides `ConflictOfInterest.properties.boardMember.$ref` WHEN imported THEN it reads `Membership` (was `Participant`)
  - GIVEN the fragment overrides `Participant.description` WHEN imported THEN it names exactly `Vote.participant`, `EngagementRecord.participant`, quorum aggregation, `resolveParticipantUuid()` as remaining consumers
  - Bump both schemas' `version` fields
- [x] Implement
- [x] Test

### Task 3: ProxyAuthorization retargets grantor/holder to Person and gains proxyStatus
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-024-proxyauthorization-references-person-gains-proxystatus-boardproxy-is-retired`
- **files**: `lib/Settings/register.d/63-member-proxy-authorization.json` (direct edit — this change's own fragment, not a new override)
- **acceptance_criteria**:
  - GIVEN `grantor`/`holder` properties WHEN edited THEN both `$ref: Person` (was `Participant`)
  - GIVEN a new `proxyStatus` property WHEN added THEN it is an optional enum (`pending-approval`/`active`/`suspended`/`revoked`, default `pending-approval`), distinct from `signatureStatus` (unchanged, still provider-gated)
  - Existing seed objects in this fragment (`machtiging-vandam-begroting` etc.) are left with their current `Participant`-shaped values as historical fixtures for the code chain's migration — do not edit them
  - Bump `ProxyAuthorization`'s `version` field
- [x] Implement
- [x] Test
  - **Deviation (noted, not a scope change):** the two `x-openregister-notifications` event keys `proxyAuthorizationCreated`/`proxyAuthorizationSigned` also embedded the pre-rename slug string; renamed to `authorizationCreated`/`authorizationSigned` to satisfy this task's acceptance criterion ("no occurrence of the schema-slug string `proxyAuthorization` remains anywhere in the repo" — stricter wording than Task 6's, which carves out register.d/47). Purely an internal event-key identifier, not consumed by any manifest/UI reference found in the grep sweep.

### Task 4: BoardProxy retired (inactive, not deleted)
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-024-proxyauthorization-references-person-gains-proxystatus-boardproxy-is-retired`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json`
- **acceptance_criteria**:
  - GIVEN the fragment overrides `BoardProxy.x-openregister.active` WHEN imported THEN it is `false`
  - GIVEN the fragment overrides `BoardProxy.description` WHEN imported THEN it points at `ProxyAuthorization` + `proxyStatus` as the replacement
  - `board-proxy` slug remains in `components.registers.decidesk.schemas` (not removed)
- [x] Implement
- [x] Test

### Task 5: GoverningDocument gains currentEffectiveDate
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-025-governingdocument-carries-a-current-in-force-convenience-property`
- **files**: `lib/Settings/register.d/55-governing-documents-register.json` (direct edit — this change's own fragment)
- **acceptance_criteria**:
  - GIVEN a new `currentEffectiveDate` property WHEN added to `GoverningDocument.properties` THEN it is nullable `date`, `facetable: true`, mirroring `Regeling.currentEffectiveDate`'s shape and description caveat
  - Bump `GoverningDocument`'s `version` field
- [x] Implement
- [x] Test

### Task 6: Rename slug adviceRequest → advice-request
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-026-slug-hygiene--advice-request-and-proxy-authorization`
- **files**: `lib/Settings/register.d/60-advisory-opinion-workflow.json` (`"slug": "adviceRequest"` → `"advice-request"`; seed `objects` key `"adviceRequest": [...]` → `"advice-request": [...]`), `lib/Settings/decidesk_register.json` (`components.registers.decidesk.schemas` array entry, direct edit), `src/manifest.json` (one `"schema": "adviceRequest"` occurrence), `src/manifest.d/advisory-opinion-workflow.json` (two `"schema": "adviceRequest"` occurrences)
- **acceptance_criteria**:
  - GIVEN every listed file WHEN edited THEN no occurrence of the schema-slug string `adviceRequest` remains outside `register.d/47-works-council-consultation.json`
  - GIVEN `register.d/47-works-council-consultation.json`'s `ConsultationRequest.type` enum literal `"adviceRequest"` (line 9, 34, 286, 289) and `src/manifest.d/works-council-consultation.json`'s quick-filter `{ "type": "adviceRequest" }` (line 31) WHEN this task is done THEN both are UNCHANGED — confirmed unrelated (a `type` field value on a different schema, not this schema's slug)
- [x] Implement
- [x] Test
  - **Additional false positive found during the exhaustive grep sweep (not documented in proposal/design.md, but the same shape as the WOR carve-out above):** `Advies.adviceRequest` — a PROPERTY NAME on the `Advies` schema (`register.d/60-advisory-opinion-workflow.json` lines 437 `required` entry and 444 property definition, plus its seed-data values at lines 61/70) that happens to spell the same as the pre-rename schema slug. It is a camelCase data-field identifier (`$ref: Adviesaanvraag`, the PascalCase schema key — never the slug), not a slug reference, so renaming the schema slug does not require renaming this field. Left UNCHANGED, consistent with Task 6's own precedent of not treating every string match as a slug reference.

### Task 7: Rename slug proxyAuthorization → proxy-authorization
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-026-slug-hygiene--advice-request-and-proxy-authorization`
- **files**: `lib/Settings/register.d/63-member-proxy-authorization.json` (`"slug": "proxyAuthorization"` → `"proxy-authorization"`), `lib/Settings/decidesk_register.json` (`components.registers.decidesk.schemas` array entry, direct edit), `src/manifest.d/member-proxy-authorization.json` (two `"schema": "proxyAuthorization"` occurrences plus the `_note` prose mentioning the slug)
- **acceptance_criteria**:
  - GIVEN every listed file WHEN edited THEN no occurrence of the schema-slug string `proxyAuthorization` remains anywhere in the repo
  - Combine this edit with Task 3's edits to `63-member-proxy-authorization.json` in one coherent diff to that file
- [x] Implement
- [x] Test

### Task 8: Seed data for every new/retargeted property
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/design.md#seed-data-adr-001`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json` (`objects` block)
- **acceptance_criteria**:
  - GIVEN OpenRegister seed import is create-only THEN every new/retargeted property is demonstrated on a freshly-created seed object, never a patch to an existing seeded row
  - New seed objects: one `decision` with `meeting`/`agendaItem` set; one `conflict-of-interest` with `boardMember` as a `membership` slug; one `proxyAuthorization` sibling object with `grantor`/`holder` as `person` slugs and `proxyStatus: "active"`; one `governing-document` with `currentEffectiveDate` set
  - Every reference resolves by slug against existing base/fragment seed objects (no new nil-UUID placeholders unless no matching seed object exists)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes — `openspec validate model-debt-cleanup-schema --strict` → "Change 'model-debt-cleanup-schema' is valid"
- [ ] Manual verification via OpenRegister's schema browser against migration.md's Validation section — **DEFERRED**: the shared dev instance (:8080) serves the pre-wave-3 build and other programmes are actively running against it; a settings-load re-import against that instance right now would be an uncontrolled experiment on a shared resource. Defer to the next coordinated deploy window (see memory: "a shared checkout was switched mid-session" / "an experiment and a validation must not share a working tree" — same class of risk). All declarative claims in this document (property shapes, `$ref` targets, slug renames, seed-object shapes) were instead verified statically: every touched JSON file parses; `npm run check:manifest` (Ajv v2.13.0) passes 0 errors; `node scripts/check-nav-ceiling.js` exits 0; `npx vitest run` is 346/346 unchanged; `vendor/bin/phpunit --filter RegisterJsonTest` is 19/19 unchanged; `openspec validate --strict` passes.

## Tests (company-wide ADR-009)
- [x] `composer test -- --filter RegisterJsonTest` passes unmodified (confirms zero fallout on the one PHPUnit file checked in design.md Decision 1) — ran directly as `vendor/bin/phpunit --filter RegisterJsonTest tests/Unit/RegisterJsonTest.php`: 19 tests, 584 assertions, OK
- [ ] Newman/API tests per test-plan.md (TC-1 through TC-11) — **DEFERRED with the live-verification step above** (same shared-instance reason); these require a live OpenRegister import against a running decidesk instance, which this implementation pass intentionally did not touch. TC-12 (the regression case) is the one PHPUnit-runnable case and IS covered by the RegisterJsonTest run above.
- N/A: Browser tests — this change ships no UI

## Documentation (company-wide ADR-010)
- N/A: no user-facing feature added — this is an internal schema-integrity fix; no `docs/` update needed

## i18n (company-wide ADR-005)
- N/A: no new user-facing strings — schema `description`/`title` fields are internal/developer-facing, not rendered translated UI copy

## Verification Notes (opsx-verify 2026-08-19)
VERDICT: PASS
- Commit f278dc69 ("apply model-debt-cleanup-schema") is the sole implementing commit; diff matches tasks.md's file list for Tasks 1/1b/2/3/4/5/6/7 exactly, with one additive completeness note below.
- Task 1 (Decision.meeting/agendaItem): `lib/Settings/register.d/67-model-debt-cleanup.json:58-80` — both properties present, `$ref: Meeting`/`AgendaItem`, `facetable: true`, `nullable: true`, `version` bumped to 0.9.0 — OK
- Task 1b (Person.nextcloudUserId): `lib/Settings/register.d/67-model-debt-cleanup.json:82-93` — optional nullable string, `version` bumped to 0.2.0 — OK
- Task 2 (ConflictOfInterest.boardMember→Membership; Participant description narrowed): `lib/Settings/register.d/67-model-debt-cleanup.json:95-110` — `boardMember.$ref: Membership`, `Participant` description names exactly `Vote.participant`/`EngagementRecord.participant`/quorum aggregation/`resolveParticipantUuid()` — OK
- Task 3 (ProxyAuthorization grantor/holder→Person + proxyStatus): `lib/Settings/register.d/63-member-proxy-authorization.json` diff (commit f278dc69) — both `$ref: Person` (was `Participant`), `proxyStatus` enum added with `default: "pending-approval"`, pre-existing seed objects' `Participant`-shaped values left untouched — OK. Notification-key deviation (`proxyAuthorizationCreated/Signed` → `authorizationCreated/authorizationSigned`) confirmed applied and consistent with the task's own noted deviation; grep found no other reference to the old event keys.
- Task 4 (BoardProxy retired): `lib/Settings/register.d/67-model-debt-cleanup.json:112-122` — `x-openregister.active: false`, description points at ProxyAuthorization+proxyStatus; base `lib/Settings/decidesk_register.json:2905-2918` confirms the schema definition and `"board-proxy"` slug (line 1511 in the registry list) are still present, deep-merge overrides only the changed keys (verified against `SettingsService::deepMergeConfig()`, `lib/Service/SettingsService.php:439-449` — object keys union, matching design.md's claim) — OK
- Task 5 (GoverningDocument.currentEffectiveDate): `lib/Settings/register.d/55-governing-documents-register.json` diff — nullable `date`, `facetable: true`, `version` bumped 0.1.0→0.2.0 — OK
- Task 6 (adviceRequest→advice-request): `lib/Settings/decidesk_register.json:1505` (registry list), `lib/Settings/register.d/60-advisory-opinion-workflow.json` (`slug` + seed key), `src/manifest.json:259`, `src/manifest.d/advisory-opinion-workflow.json` (2 occurrences) all renamed — OK. Zero leftover `"adviceRequest"` slug string; every remaining `adviceRequest` grep hit is one of the two documented false positives (see below).
- Task 7 (proxyAuthorization→proxy-authorization): `lib/Settings/decidesk_register.json:1562`, `lib/Settings/register.d/63-member-proxy-authorization.json`, `src/manifest.d/member-proxy-authorization.json` (2 occurrences) all renamed — OK, plus `src/manifest.json` also had two `"schema": "proxyAuthorization"` occurrences (the `meeting-proxy-authorizations` widget and its Meeting-page `_note` prose) that were correctly renamed even though `src/manifest.json` isn't listed in Task 7's `files:` field — a tasks.md file-list omission, not an implementation gap (acceptance criterion "no occurrence anywhere in the repo" is in fact satisfied). Confirmed zero leftover `"proxyAuthorization"` string anywhere in the repo (full grep, non-vendor).
- Task 8 (seed data): `lib/Settings/register.d/67-model-debt-cleanup.json:6-53` — four fresh seed objects (`besluit-verordening-parkeren-2026`, `coi-wethouder-vastgoedbelang`, `machtiging-jansen-alv-2026`, `huishoudelijk-reglement-acme-bv`), all referencing existing base seed slugs, none patching a pre-existing seeded row — OK
- REQ-SDM-022 through REQ-SDM-026, REQ-PCR-010 (both spec deltas): each has direct fragment/base-file evidence as itemized above — OK, no gaps found
- `tests/Unit/RegisterJsonTest.php:69-73` confirmed to read only `components.schemas` from `lib/Settings/decidesk_register.json` directly (never `components.registers`, never `register.d/*`), so it is provably unaffected by this change's fragment overrides and base-file slug renames — matches design.md Decision 1's claim exactly
- `openspec validate model-debt-cleanup-schema --strict` → "Change 'model-debt-cleanup-schema' is valid" — OK
- Two documented false-positive slug matches, confirmed genuinely bounded to two: (1) the WOR `ConsultationRequest.type` enum literal `"adviceRequest"` — `lib/Settings/register.d/47-works-council-consultation.json:9,34,286,289` and its manifest quick-filter mirror `src/manifest.d/works-council-consultation.json:31` (one decision, documented in proposal.md "Out of Scope", design.md, and the spec delta's own scenario "The unrelated WOR consultation-request enum value is untouched"); (2) the `Advies.adviceRequest` property name (a field, `$ref: Adviesaanvraag`, not a slug) — `lib/Settings/register.d/60-advisory-opinion-workflow.json:61,70` (seed values) and `:437,444` (required entry + property definition), documented only in tasks.md Task 6's own "Additional false positive" note. Both are exactly what tasks.md/proposal.md/design.md describe — no unbounded or undocumented skip found.
- No discrepancies found. No files belonging to the sibling `model-debt-cleanup-code` change were read, modified, or commented on.
