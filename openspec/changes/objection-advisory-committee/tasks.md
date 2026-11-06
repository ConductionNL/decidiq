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
- [ ] Implement
- [ ] Test

### Task 2: A scoped write path for governance bodies on the cross-app API
- **spec_ref**: `openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-005-governance-bodies-are-writable-through-the-cross-app-api`
- **files**: `lib/Controller/ApiController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a caller with `governance-bodies:write` WHEN it POSTs a valid body THEN the object is created and returned
  - GIVEN a caller with only the read scope WHEN it POSTs THEN the request is refused and nothing is created
  - GIVEN the same caller WHEN it POSTs to any other resource THEN the request is refused
  - GIVEN the existing GET routes WHEN exercised THEN their behaviour is unchanged
- [ ] Implement
- [ ] Test

### Task 3: Seed one objection advisory committee and surface the fields
- **spec_ref**: `openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-006-the-new-fields-are-seeded-and-surfaced`
- **files**: `lib/Settings/decidesk_register.json` (seed objects), `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeded THEN a body exists with `bodyType: advisory-body`, `statutoryBasis` naming Awb 7:13, `quorum >= 2`, `active: true`, and a `jurisdiction`
  - GIVEN the governance-body detail page WHEN rendered THEN the four new fields appear
  - GIVEN a body with an unset `quorum` WHEN rendered THEN it shows as unset, never as `0`
- [ ] Implement
- [ ] Test

### Task 4: Tests
- **spec_ref**: all requirements
- **files**: `tests/vitest/`, `tests/Unit/`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN a test reads it THEN the four GovernanceBody fields and the Membership field are present with the specced types, defaults and constraints
  - GIVEN `quorum` WHEN inspected THEN it has NO default — a test asserts the absence, because a default of 0 would read as "no members needed"
  - GIVEN the write path WHEN exercised without the scope THEN a test proves it refuses
- [ ] Implement
- [ ] Test

### Task 5: The objection flow as configuration
- **spec_ref**: `openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route`
- **files**: `lib/Settings/profiles/municipality.json` (the `bezwaar-decision` decision template with its approval route: intake step with admissibility verdict, hearing, advice, decision, sending; the seeded advisory committee as adviser; the term period), `lib/Settings/register.d/80-decision-as-a-walked-process.json` (a `termPeriod` on the decision template if no existing field carries it), `src/manifest.json` (`DecisionDetail` shows the route steps and the term through the existing route widget), `tests/Unit/Settings/RegisterDescriptorTest.php`, `tests/e2e/objection-route.spec.ts` (new)
- **acceptance_criteria**:
  - GIVEN the municipality profile WHEN imported THEN `bezwaar-decision` carries the five steps and a term period
  - GIVEN an objection recorded against a decision WHEN its page opens THEN the steps, the current step and the term show as DcBezwaar draws them
  - GIVEN `grep -rniE 'bezwaar|objection' lib/Service` WHEN run after this task THEN no new class or method is named after objections
- [ ] Implement
- [ ] Test
