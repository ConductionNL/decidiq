# Tasks: objection-advisory-committee

## Implementation Tasks

### Task 1: Additive schema fields on GovernanceBody and Membership
- **spec_ref**: `openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-001-governancebody-carries-an-operational-active-flag` (+ REQ-OAC-002/REQ-OAC-003/REQ-OAC-004)
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN it imports THEN `GovernanceBody` carries `active` (boolean, default true), `quorum` (integer, minimum 2, NO default), `jurisdiction` (string) and `statutoryBasis` (string), each with a `title`
  - GIVEN `Membership` WHEN inspected THEN it carries `external` (boolean, default false) with a `title`
  - GIVEN the descriptions of `quorum` and `quorumRule` WHEN read THEN each names the other and states which question it answers; the same for `external` and `independenceStatus`
  - GIVEN a create setting `quorum: 1` WHEN saved THEN validation rejects it
  - GIVEN `required` on both schemas WHEN compared to before THEN it is unchanged — every new field is optional, so no existing object becomes invalid
- [x] Implement
- [x] Test
  - Already on development: the fields in `lib/Settings/decidesk_register.json`, `tests/Unit/Settings/ObjectionAdvisoryCommitteeFieldsTest.php`.

### Task 2: A scoped write path for governance bodies on the cross-app API
- **spec_ref**: `openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-005-governance-bodies-are-writable-through-the-cross-app-api`
- **files**: `lib/Controller/ApiController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a caller with `governance-bodies:write` WHEN it POSTs a valid body THEN the object is created and returned
  - GIVEN a caller with only the read scope WHEN it POSTs THEN the request is refused and nothing is created
  - GIVEN the same caller WHEN it POSTs to any other resource THEN the request is refused
  - GIVEN the existing GET routes WHEN exercised THEN their behaviour is unchanged
- [x] Implement
- [x] Test
  - Already on development: `ApiController::create()/update()` for `governance-bodies` only (`RESOURCE_WRITABLE`), authorised by OpenRegister RBAC as the acting user; the docblock records why not a scope (SCOPE_MAP enforces nothing).

### Task 3: Seed one objection advisory committee and surface the fields
- **spec_ref**: `openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-006-the-new-fields-are-seeded-and-surfaced`
- **files**: `lib/Settings/decidesk_register.json` (seed objects), `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeded THEN a body exists with `bodyType: advisory-body`, `statutoryBasis` naming Awb 7:13, `quorum >= 2`, `active: true`, and a `jurisdiction`
  - GIVEN the governance-body detail page WHEN rendered THEN the four new fields appear
  - GIVEN a body with an unset `quorum` WHEN rendered THEN it shows as unset, never as `0`
- [x] Implement
- [x] Test
  - Already on development: the committee seed in `lib/Settings/profiles/municipality.json` (Awb 7:13, quorum 3, active, jurisdiction); `body-data` on GovernanceBodyDetail shows every field.

### Task 4: Tests
- **spec_ref**: all requirements
- **files**: `tests/vitest/`, `tests/Unit/`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN a test reads it THEN the four GovernanceBody fields and the Membership field are present with the specced types, defaults and constraints
  - GIVEN `quorum` WHEN inspected THEN it has NO default — a test asserts the absence, because a default of 0 would read as "no members needed"
  - GIVEN the write path WHEN exercised without the scope THEN a test proves it refuses
- [x] Implement
- [x] Test
  - Already on development: `ObjectionAdvisoryCommitteeFieldsTest` (fields, no default on quorum, additive), `ApiControllerWriteTest` (write path).

### Task 5: The objection flow as configuration
- **spec_ref**: `openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route`
- **files**: `lib/Settings/profiles/municipality.json` (the `bezwaar-decision` decision template with its approval route: intake step with admissibility verdict, hearing, advice, decision, sending; the seeded advisory committee as adviser; the term period), `lib/Settings/register.d/80-decision-as-a-walked-process.json` (a `termPeriod` on the decision template if no existing field carries it), `src/manifest.json` (`DecisionDetail` shows the route steps and the term through the existing route widget), `tests/Unit/Settings/RegisterDescriptorTest.php`, `tests/e2e/objection-route.spec.ts` (new)
- **acceptance_criteria**:
  - GIVEN the municipality profile WHEN imported THEN `bezwaar-decision` carries the five steps and a term period
  - GIVEN an objection recorded against a decision WHEN its page opens THEN the steps, the current step and the term show as DcBezwaar draws them
  - GIVEN `grep -rniE 'bezwaar|objection' lib/Service` WHEN run after this task THEN no new class or method is named after objections
- [x] Implement
  - Done: the configuration half. `lib/Settings/register.d/132-decision-type-term-period.json` adds `termPeriod` (ISO 8601 duration) and `termStartsAt` to the decision type; the municipality example set seeds the `bezwaar-decision` type (P12W from the end of the objection period) and its default route `bezwaar-standaard` with the five steps (intake with admissibility, hearing, the committee's advice, decision, sending), the seeded advisory committee as adviser; `ObjectionRouteConfigurationTest`. Then the page half: `register.d/133-decision-term.json` gives a decision `receivedAt`, `contests` (the decision it contests) and the server-written `termEndsAt`; `DecisionTermCalculator` works the term out from the type's `termPeriod` and `termStartsAt` (for `objection-period-end`: the contested decision's remedy term in days after it was published, else decided), and `DecisionTermListener` writes it on every create and update. `DecisionDetail` shows `contests`, `receivedAt` and `termEndsAt` (read-only) beside the route widget that already draws the steps, their dates and the current step. Nothing in `lib/Service` is named after objections. Unit tests: `DecisionTermCalculatorTest`, `DecisionTermListenerTest`.
- [ ] Test
  - Left: `tests/e2e/objection-route.spec.ts`, a browser test against a running instance, not run here.
