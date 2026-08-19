# Tasks: model-debt-cleanup-schema

## Implementation Tasks

### Task 1: Decision declares its meeting and agendaItem joins
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-022-decision-declares-its-meeting-and-agendaitem-joins`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json` (new)
- **acceptance_criteria**:
  - GIVEN the new fragment overrides `Decision.properties` WHEN the register is imported THEN `decision` gains optional `meeting` ($ref `Meeting`, `facetable: true`) and `agendaItem` ($ref `AgendaItem`, `facetable: true`)
  - Bump `Decision`'s own `version` field in the override
- [ ] Implement
- [ ] Test

### Task 1b: Person gains optional nextcloudUserId (judge amendment 2026-08-19)
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/design.md` (Judge amendment in the Data-migration section)
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register is imported THEN `Person` gains an optional, nullable `nextcloudUserId` (string) described as the Nextcloud account linkage carried over from the retired Participant shim
  - The code chain's crosswalk match order becomes nextcloudUserId-exact → email-exact → create-new, and the repair step copies `Participant.nextcloudUserId` onto the matched-or-created Person (recorded here; implemented in `model-debt-cleanup-code`)
- [ ] Implement
- [ ] Test

### Task 2: ConflictOfInterest.boardMember retargets to Membership; Participant description narrowed
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-023-conflictofinterestboardmember-references-membership-not-the-participant-shim`, `openspec/changes/model-debt-cleanup-schema/specs/participant-crud/spec.md#requirement-req-pcr-010--participant-schema-deprecated-in-favour-of-person--membership`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json`
- **acceptance_criteria**:
  - GIVEN the fragment overrides `ConflictOfInterest.properties.boardMember.$ref` WHEN imported THEN it reads `Membership` (was `Participant`)
  - GIVEN the fragment overrides `Participant.description` WHEN imported THEN it names exactly `Vote.participant`, `EngagementRecord.participant`, quorum aggregation, `resolveParticipantUuid()` as remaining consumers
  - Bump both schemas' `version` fields
- [ ] Implement
- [ ] Test

### Task 3: ProxyAuthorization retargets grantor/holder to Person and gains proxyStatus
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-024-proxyauthorization-references-person-gains-proxystatus-boardproxy-is-retired`
- **files**: `lib/Settings/register.d/63-member-proxy-authorization.json` (direct edit — this change's own fragment, not a new override)
- **acceptance_criteria**:
  - GIVEN `grantor`/`holder` properties WHEN edited THEN both `$ref: Person` (was `Participant`)
  - GIVEN a new `proxyStatus` property WHEN added THEN it is an optional enum (`pending-approval`/`active`/`suspended`/`revoked`, default `pending-approval`), distinct from `signatureStatus` (unchanged, still provider-gated)
  - Existing seed objects in this fragment (`machtiging-vandam-begroting` etc.) are left with their current `Participant`-shaped values as historical fixtures for the code chain's migration — do not edit them
  - Bump `ProxyAuthorization`'s `version` field
- [ ] Implement
- [ ] Test

### Task 4: BoardProxy retired (inactive, not deleted)
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-024-proxyauthorization-references-person-gains-proxystatus-boardproxy-is-retired`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json`
- **acceptance_criteria**:
  - GIVEN the fragment overrides `BoardProxy.x-openregister.active` WHEN imported THEN it is `false`
  - GIVEN the fragment overrides `BoardProxy.description` WHEN imported THEN it points at `ProxyAuthorization` + `proxyStatus` as the replacement
  - `board-proxy` slug remains in `components.registers.decidesk.schemas` (not removed)
- [ ] Implement
- [ ] Test

### Task 5: GoverningDocument gains currentEffectiveDate
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-025-governingdocument-carries-a-current-in-force-convenience-property`
- **files**: `lib/Settings/register.d/55-governing-documents-register.json` (direct edit — this change's own fragment)
- **acceptance_criteria**:
  - GIVEN a new `currentEffectiveDate` property WHEN added to `GoverningDocument.properties` THEN it is nullable `date`, `facetable: true`, mirroring `Regeling.currentEffectiveDate`'s shape and description caveat
  - Bump `GoverningDocument`'s `version` field
- [ ] Implement
- [ ] Test

### Task 6: Rename slug adviceRequest → advice-request
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-026-slug-hygiene--advice-request-and-proxy-authorization`
- **files**: `lib/Settings/register.d/60-advisory-opinion-workflow.json` (`"slug": "adviceRequest"` → `"advice-request"`; seed `objects` key `"adviceRequest": [...]` → `"advice-request": [...]`), `lib/Settings/decidesk_register.json` (`components.registers.decidesk.schemas` array entry, direct edit), `src/manifest.json` (one `"schema": "adviceRequest"` occurrence), `src/manifest.d/advisory-opinion-workflow.json` (two `"schema": "adviceRequest"` occurrences)
- **acceptance_criteria**:
  - GIVEN every listed file WHEN edited THEN no occurrence of the schema-slug string `adviceRequest` remains outside `register.d/47-works-council-consultation.json`
  - GIVEN `register.d/47-works-council-consultation.json`'s `ConsultationRequest.type` enum literal `"adviceRequest"` (line 9, 34, 286, 289) and `src/manifest.d/works-council-consultation.json`'s quick-filter `{ "type": "adviceRequest" }` (line 31) WHEN this task is done THEN both are UNCHANGED — confirmed unrelated (a `type` field value on a different schema, not this schema's slug)
- [ ] Implement
- [ ] Test

### Task 7: Rename slug proxyAuthorization → proxy-authorization
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/specs/schemas-and-data-model/spec.md#requirement-req-sdm-026-slug-hygiene--advice-request-and-proxy-authorization`
- **files**: `lib/Settings/register.d/63-member-proxy-authorization.json` (`"slug": "proxyAuthorization"` → `"proxy-authorization"`), `lib/Settings/decidesk_register.json` (`components.registers.decidesk.schemas` array entry, direct edit), `src/manifest.d/member-proxy-authorization.json` (two `"schema": "proxyAuthorization"` occurrences plus the `_note` prose mentioning the slug)
- **acceptance_criteria**:
  - GIVEN every listed file WHEN edited THEN no occurrence of the schema-slug string `proxyAuthorization` remains anywhere in the repo
  - Combine this edit with Task 3's edits to `63-member-proxy-authorization.json` in one coherent diff to that file
- [ ] Implement
- [ ] Test

### Task 8: Seed data for every new/retargeted property
- **spec_ref**: `openspec/changes/model-debt-cleanup-schema/design.md#seed-data-adr-001`
- **files**: `lib/Settings/register.d/67-model-debt-cleanup.json` (`objects` block)
- **acceptance_criteria**:
  - GIVEN OpenRegister seed import is create-only THEN every new/retargeted property is demonstrated on a freshly-created seed object, never a patch to an existing seeded row
  - New seed objects: one `decision` with `meeting`/`agendaItem` set; one `conflict-of-interest` with `boardMember` as a `membership` slug; one `proxyAuthorization` sibling object with `grantor`/`holder` as `person` slugs and `proxyStatus: "active"`; one `governing-document` with `currentEffectiveDate` set
  - Every reference resolves by slug against existing base/fragment seed objects (no new nil-UUID placeholders unless no matching seed object exists)
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off
- `openspec validate` passes
- Manual verification via OpenRegister's schema browser against migration.md's Validation section

## Tests (company-wide ADR-009)
- `composer test -- --filter RegisterJsonTest` passes unmodified (confirms zero fallout on the one PHPUnit file checked in design.md Decision 1)
- Newman/API tests per test-plan.md (TC-1 through TC-12)
- N/A: Browser tests — this change ships no UI

## Documentation (company-wide ADR-010)
- N/A: no user-facing feature added — this is an internal schema-integrity fix; no `docs/` update needed

## i18n (company-wide ADR-005)
- N/A: no new user-facing strings — schema `description`/`title` fields are internal/developer-facing, not rendered translated UI copy
