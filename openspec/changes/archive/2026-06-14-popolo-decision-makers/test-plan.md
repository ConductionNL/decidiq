# Test Plan: popolo-decision-makers

## Test Cases

### TC-1: Person schema holds identity only
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/person-and-membership/spec.md#req-pmb-010-person-schema-popolo-identity`
- **type**: api
- **persona**: Annemarie (VNG standards architect) — validates Popolo conformance
- **preconditions**: decidesk register imported on a clean instance
- **steps**: Read the `Person` schema via the OpenRegister schemas API / inspect `decidesk_register.json`
- **expected result**: Person exposes name/familyName/givenName/gender/birthDate/image/biography/email and defines no role/party/votingWeight
- **test command**: /test-api

### TC-2: Membership links Person↔GovernanceBody with declarative relations
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/person-and-membership/spec.md#req-pmb-011-membership-schema-orgmembership-relationship`
- **type**: api
- **persona**: Priya (developer/integrator)
- **preconditions**: register imported with seeds
- **steps**: Fetch a seeded Membership object and inspect its relations and fields
- **expected result**: Membership carries role/party/votingWeight/startDate/endDate and resolves Person, GovernanceBody, and Post relations (many-to-one)
- **test command**: /test-api

### TC-3: One person, multiple memberships across bodies
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/person-and-membership/spec.md#req-pmb-010-person-schema-popolo-identity`
- **type**: api
- **persona**: Priya (developer/integrator)
- **preconditions**: marie-janssen seeded with memberships in two bodies
- **steps**: Query memberships filtered by the marie-janssen Person relation
- **expected result**: a single Person record is linked by two distinct Membership records (no duplicated identity)
- **test command**: /test-api

### TC-4: Post exists independently of occupant
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/person-and-membership/spec.md#req-pmb-012-post-schema-orgpost-formal-position`
- **type**: api
- **persona**: Annemarie (VNG standards architect)
- **preconditions**: seeded Posts exist
- **steps**: Fetch a seeded Post and its GovernanceBody relation
- **expected result**: the Post resolves its GovernanceBody relation and exists whether or not a Membership references it
- **test command**: /test-api

### TC-5: ContactDetail typed multi-value contacts
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/governance-bodies/spec.md#req-gbd-010-contactdetail-schema-popolocontactdetail`
- **type**: api
- **persona**: Priya (developer/integrator)
- **preconditions**: register imported with ContactDetail seeds
- **steps**: List ContactDetail objects and inspect type/value + relations
- **expected result**: at least one email, one phone, and one address ContactDetail exist, each resolving a Person or GovernanceBody relation
- **test command**: /test-api

### TC-6: Governance-body decision makers seeded as Person + Membership
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/governance-bodies/spec.md#req-gbd-011-person-membership-as-the-governance-body-decision-maker-model`
- **type**: api
- **persona**: Annemarie (VNG standards architect)
- **preconditions**: clean instance, seeds imported
- **steps**: Inspect seeded decision makers for council, corporate board, and association bodies
- **expected result**: they are Person + Membership records; no NEW Participant seeds were added
- **test command**: /test-api

### TC-7: Participant schema deprecated and retained as shim
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/participant-crud/spec.md#req-pcr-010-participant-schema-deprecated-in-favour-of-person-membership`
- **type**: regression
- **persona**: Priya (developer/integrator)
- **preconditions**: register imported
- **steps**: Inspect Participant.description; confirm quorum aggregation and vote-casting still read Participant
- **expected result**: description marks Participant deprecated; the schema is still active/queryable so quorum + vote-casting keep working
- **test command**: /test-regression

### TC-8: ORI persons serialize real Popolo Persons (no email leak)
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/ori-api/spec.md#req-ori-006-ori-persons-and-memberships-sourced-from-popolo-schemas`
- **type**: api
- **persona**: Annemarie (VNG standards architect)
- **preconditions**: seeded Person records; anonymous caller
- **steps**: GET `/api/ori/v1/persons`
- **expected result**: JSON-LD `@type: Person`, items are seeded Persons with `name` from Person.name, no Participant objects, Person email not exposed, envelope/path unchanged
- **test command**: /test-api

### TC-9: ORI memberships serialize real Popolo Memberships
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/ori-api/spec.md#req-ori-006-ori-persons-and-memberships-sourced-from-popolo-schemas`
- **type**: api
- **persona**: Annemarie (VNG standards architect)
- **preconditions**: seeded Membership records; anonymous caller
- **steps**: GET `/api/ori/v1/memberships`
- **expected result**: JSON-LD `@type: Membership`, items are seeded Memberships, no Participant objects, envelope/path unchanged
- **test command**: /test-api

### TC-10: BoardMember data re-expressed as Person + Membership (C3 coordination)
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/governance-bodies/spec.md#req-gbd-011-person-membership-as-the-governance-body-decision-maker-model`
- **type**: regression
- **persona**: Mark (MKB software vendor)
- **preconditions**: seeds imported
- **steps**: Confirm corporate board people exist as Person + Membership (mode=corp) and the BoardMember schema is still present (not deleted)
- **expected result**: corporate decision makers exist on the unified Popolo model; `BoardMember` schema untouched (deletion deferred to retire-board-portal)
- **test command**: /test-regression

### TC-11: Register import integrity / no regression
- **spec_ref**: `openspec/changes/popolo-decision-makers/specs/ori-api/spec.md#req-ori-006-ori-persons-and-memberships-sourced-from-popolo-schemas`
- **type**: regression
- **persona**: Noor (functional admin)
- **preconditions**: register re-imported via repair step
- **steps**: Import register; verify JSON validity and existing endpoints (events/organizations/motions) still respond
- **expected result**: valid JSON; the four new schemas load; existing ORI resources unchanged and still serve data
- **test command**: /test-regression

## Coverage Summary

- REQ-PMB-010 (Person schema): TC-1, TC-3 — covered
- REQ-PMB-011 (Membership schema + relations): TC-2 — covered
- REQ-PMB-012 (Post schema): TC-4 — covered
- REQ-PMB-013 (Popolo seed data): TC-1..TC-6 (seed-dependent) — covered
- REQ-GBD-010 (ContactDetail schema): TC-5 — covered
- REQ-GBD-011 (Person+Membership decision-maker model): TC-6, TC-10 — covered
- REQ-PCR-010 (Participant deprecation/retention): TC-7 — covered
- REQ-ORI-006 (ORI persons/memberships retarget): TC-8, TC-9, TC-11 — covered

## Out of Scope

- New Vue views for Person/Membership/Post/ContactDetail — owned by `ia-six-item-nav`
  and downstream UI changes; no functional/accessibility UI test in this plan.
- `BoardMember`/`board-*` schema deletion — owned by `retire-board-portal` (C3).
- Full `Participant` removal + migration of quorum/vote-casting references — deferred.
