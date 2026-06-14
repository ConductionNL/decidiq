# Tasks: popolo-decision-makers

<!-- Config-first per ADR-031: add schemas → seeds → re-express BoardMember → deprecate
     Participant → retarget ORI. Acceptance criteria are PLAIN bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: Add Person and Post schemas to the register
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/person-and-membership/spec.md#req-pmb-010-person-schema-popolo-identity`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN the `Person` schema is inspected THEN it has the ADR-000 identity fields (name required; familyName/givenName/gender/birthDate/image/biography/email) and no role/party/votingWeight
  - GIVEN the register is imported WHEN the `Post` schema is inspected THEN it has label (required)/role/startDate/endDate and a GovernanceBody many-to-one relation
- [x] Implement
- [x] Test

### Task 2: Add Membership schema with declarative relations
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/person-and-membership/spec.md#req-pmb-011-membership-schema-orgmembership-relationship`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN the `Membership` schema is inspected THEN it has role (required enum incl. treasurer)/label/startDate/endDate/votingWeight(default 1)/party
  - GIVEN the `Membership` schema WHEN its relations are inspected THEN it declares Person, GovernanceBody, and Post relations, each many-to-one
- [x] Implement
- [x] Test

### Task 3: Add ContactDetail schema with declarative relations
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/governance-bodies/spec.md#req-gbd-010-contactdetail-schema-popolocontactdetail`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN the `ContactDetail` schema is inspected THEN it has type (required enum email/phone/fax/cell/address/url)/value (required)/label/note/validFrom/validUntil
  - GIVEN the `ContactDetail` schema WHEN its relations are inspected THEN it declares Person and GovernanceBody relations, each many-to-one
- [x] Implement
- [x] Test

### Task 4: Seed Person/Membership/Post/ContactDetail demo data across org types
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/person-and-membership/spec.md#req-pmb-013-popolo-seed-data-for-persons-memberships-and-posts`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a clean instance WHEN seeds import THEN council, corporate-board, and association decision makers exist as Person + Membership pairs (reusing femke-halsema/marie-janssen/jan-de-vries where sensible)
  - GIVEN seeds import THEN chair/secretary/treasurer Posts exist and at least one email, one phone, and one address ContactDetail exist linked to a Person or GovernanceBody
- [x] Implement
- [x] Test

### Task 5: Re-express BoardMember demo data as Person + Membership (C3 coordination)
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/governance-bodies/spec.md#req-gbd-011-person-membership-as-the-governance-body-decision-maker-model`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the shipped BoardMember demo objects WHEN seeds import THEN the same corporate people also exist as Person + Membership seeds with mode=corp labels linked to corporate GovernanceBody slugs
  - GIVEN this change WHEN the register is inspected THEN the `BoardMember` schema is NOT deleted (deletion owned by `retire-board-portal` C3)
- [x] Implement
- [x] Test

### Task 6: Deprecate the Participant schema (retain as shim)
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/participant-crud/spec.md#req-pcr-010-participant-schema-deprecated-in-favour-of-person-membership`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN the `Participant` schema description is inspected THEN it states the schema is deprecated and Person + Membership is canonical
  - GIVEN this change WHEN the register is inspected THEN `Participant` is still active/queryable and no new Participant seeds were added
- [x] Implement
- [x] Test

### Task 7: Retarget ORI persons/memberships to the Popolo schemas
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/ori-api/spec.md#req-ori-006-ori-persons-and-memberships-sourced-from-popolo-schemas`
- **files**: `lib/Controller/OriController.php`
- **acceptance_criteria**:
  - GIVEN `RESOURCE_MAP` WHEN inspected THEN `persons` maps to `person` and `memberships` maps to `membership`, using the findAll config-array pattern (register/schema inside filters)
  - GIVEN seeded data WHEN GET `/api/ori/v1/persons` and `/memberships` are called THEN they return non-empty Popolo Person/Membership JSON-LD with unchanged paths/envelope and Person email not exposed
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate popolo-decision-makers --strict` passes
- [x] Register is valid JSON and the four schemas + seeds import cleanly
- [x] `/api/ori/v1/persons` and `/memberships` serialize real Popolo objects

## Quality checklist

- New/changed business logic (ORI retarget) covered by PHPUnit unit tests (`tests/Unit/`)
- ORI `/persons` + `/memberships` covered by Newman/Postman tests
- No UI is added — Playwright N/A for this change
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010) — N/A (schema/ORI only)
- Dutch (`nl_NL`) and English (`en_US`) strings added for any new user-facing strings (ADR-007) — seed labels are Dutch with English-keyed enums
- `openspec validate` passes
