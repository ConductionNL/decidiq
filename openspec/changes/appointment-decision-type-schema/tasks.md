# Tasks: appointment-decision-type-schema

## Implementation Tasks

### Task 1: Add decisionType=appointment folded fields to the Decision schema
- **spec_ref**: `openspec/changes/appointment-decision-type-schema/specs/decision-management/spec.md#requirement-appointment-decision-type-carries-folded-nomination-fields`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `Decision` schema WHEN inspected THEN it carries `targetBody` ($ref GovernanceBody), `targetPosts` (array $ref Post), `targetRole` (enum, matching person-and-membership's Membership role enum), `candidates` (array, minItems documented as form-enforced, each item `person`/`externalName`/`notes`), `nominatingParty` (object `type`/`name`/`reference`), and `appointedMemberships` (nullable array $ref Membership), each described as "Revealed when decisionType=appointment"
  - GIVEN `Decision.version` WHEN inspected THEN it reads `0.8.0`
  - GIVEN a `decisionType=motion` decision created without any appointment field WHEN validated THEN it is accepted (appointment fields are not in JSON-schema `required[]`)
- [ ] Implement
- [ ] Test

### Task 2: Re-author the 3 voordracht seeds as decisionType=appointment Decision seeds
- **spec_ref**: `openspec/changes/appointment-decision-type-schema/specs/decision-management/spec.md#requirement-the-voordracht-schema-is-retired-in-favor-of-decisiontypeappointment`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a freshly installed register WHEN the `Decision` seed objects are inspected THEN 3 new `decisionType=appointment` seeds exist (`benoeming-lid-auditcommissie`, `benoeming-lid-rvc-acme-van-duin`, `benoeming-voorzitter-auditcommissie-ingetrokken`) per the design.md Seed Data table, using the D2/D3 field and lifecycle mapping
  - GIVEN the pre-existing `besluit-benoeming-penningmeester` appointment seed WHEN the register is inspected THEN it is unchanged
- [ ] Implement
- [ ] Test

### Task 3: Retire the Voordracht schema and its seed block
- **spec_ref**: `openspec/changes/appointment-decision-type-schema/specs/decision-management/spec.md#requirement-the-voordracht-schema-is-retired-in-favor-of-decisiontypeappointment`
- **files**: `lib/Settings/register.d/61-appointments-and-terms.json`
- **acceptance_criteria**:
  - GIVEN `register.d/61-appointments-and-terms.json` `components.schemas` WHEN inspected THEN `Voordracht` is absent and `TermijnRegeling`, `RoosterVanAftreden`, `RoosterRegel` are present, byte-identical to before
  - GIVEN `x-openregister.seedData.objects` WHEN inspected THEN the `voordracht` key is absent and `termijn-regeling`/`rooster-van-aftreden`/`rooster-regel` keys are unchanged (2/2/5 objects respectively)
- [ ] Implement
- [ ] Test

### Task 4: Remove the Voordrachten nav pages from the manifest fragment
- **spec_ref**: `openspec/changes/appointment-decision-type-schema/specs/decision-management/spec.md#requirement-the-voordracht-schema-is-retired-in-favor-of-decisiontypeappointment`
- **files**: `src/manifest.d/appointments-and-terms.json`
- **acceptance_criteria**:
  - GIVEN the manifest fragment `menu` array WHEN inspected THEN the `Voordrachten` entry is absent and `Roosters`/`Termijnregelingen` entries are unchanged
  - GIVEN the manifest fragment `pages` array WHEN inspected THEN `Voordrachten` and `VoordrachtDetail` are absent (4 pages remain, down from 6) and the remaining 4 pages are unchanged
- [ ] Implement
- [ ] Test

## Quality checklist

- Every appointment folded field is DECLARATIVE (plain schema properties) — no new Service class ships in this change (ADR-031); `appointedMemberships` materialization is explicitly deferred to `appointment-decision-type-membership`.
- Confirm no remaining reference to `Voordracht`/`voordracht` anywhere in `lib/` or `src/` after Task 3-4 (repo-wide grep) — this was already verified empty before this change (only the 2 edited files referenced it).
- `Decision.candidates[].person` and `Decision.targetPosts[]`/`targetBody` resolve against real seeded `Person`/`Post`/`GovernanceBody` objects (`marie-janssen`, `auditcommissie-provincie-nh`, `raad-van-commissarissen-acme-bv`).
- No hardcoded colours, no Vue/PHP changes in this change (config only, per ADR-032 `kind: config`).
- `openspec validate` passes.
- Fix any pre-existing quality issues (PHPCS, PHPMD, PHPStan, hydra-gates) encountered while editing these 3 JSON files.

## Verification
- [ ] All implementation tasks done (Decision folded fields, re-seeded appointment decisions, Voordracht retirement, manifest cleanup)
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (register re-imports cleanly; Decision list shows 4 appointment-typed decisions; Voordrachten pages are gone from the manifest)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit: none required — no PHP code changes in this change (config-only); verified by the existing `SettingsServiceTest`/register-import test suite continuing to pass against the edited JSON
- N/A Newman/Postman — no API endpoint changes
- N/A Playwright browser tests — no Vue/UI changes in this change (ships in `appointment-decision-type-membership`)
- [ ] All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` noting `decisionType=appointment` is now a fully folded type (supersedes the `appointments-and-terms` change's Voordrachten docs, if any were written)
- N/A Screenshot — no UI change in this change

## i18n (company-wide ADR-005)
- [ ] Dutch (`nl_NL`) and English (`en_US`) field labels added for `targetBody`/`targetPosts`/`targetRole`/`candidates`/`nominatingParty`/`appointedMemberships` (labels only — the form UI itself ships in the dependent change)
