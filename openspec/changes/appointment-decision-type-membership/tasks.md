# Tasks: appointment-decision-type-membership

## Implementation Tasks

### Task 1: Guard the enact transition against an unpairable posts/candidates mismatch
- **spec_ref**: `openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-the-enact-transition-rejects-an-unpairable-candidatesposts-mismatch`
- **files**: `lib/Service/DecisionLifecycleService.php`
- **acceptance_criteria**:
  - GIVEN a `decisionType=appointment` decision with 3 candidates and 2 `targetPosts` WHEN `enact` is attempted THEN `resolveRejection()` returns a message identifying the count mismatch and `lifecycle` stays unchanged
  - GIVEN a `decisionType=appointment` decision with any candidate count and 0 or 1 `targetPosts` WHEN `enact` is attempted THEN this guard does not reject it
  - GIVEN a non-appointment decision WHEN `enact` is attempted THEN this guard is not evaluated (no `targetPosts`/`candidates` fields exist)
- [ ] Implement
- [ ] Test

### Task 2: Materialize Memberships on adoption
- **spec_ref**: `openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records`
- **files**: `lib/Service/DecisionLifecycleService.php`
- **acceptance_criteria**:
  - GIVEN a `decisionType=appointment` decision with 1 candidate (a `person` reference), `targetRole`, `targetBody`, no `targetPosts` WHEN it transitions to `enacted` with `outcome=adopted` THEN exactly 1 `Membership` is created with matching `person`/`role`/`governanceBody`/`startDate=enactedAt` and no `post`, and the decision's `appointedMemberships` contains its id
  - GIVEN a candidate with only `externalName` WHEN materialized THEN the created `Membership.label` equals `externalName` and `person` is absent
  - GIVEN 2 candidates and 2 `targetPosts` WHEN materialized THEN each candidate pairs with `targetPosts` at the same array index
  - GIVEN a `decisionType=appointment` decision reaching `outcome=rejected` WHEN any later transition occurs THEN no `Membership` is created
  - GIVEN a decision whose `appointedMemberships` is already non-empty WHEN `applyPostTransitionEffects` runs again THEN no additional `Membership` is created
- [ ] Implement
- [ ] Test

### Task 3: Show appointment fields on the Decision detail page
- **spec_ref**: `openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-fields-render-on-the-decision-detail-page`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the `DecisionDetail` page's `decision-content` widget `content.include` WHEN inspected THEN it contains `targetBody`, `targetPosts`, `targetRole`, `candidates`, `nominatingParty`, `appointedMemberships` in addition to the existing fields
  - GIVEN an appointment decision's detail page WHEN opened THEN the Content widget renders the candidate/nomination data
- [ ] Implement
- [ ] Test

## Quality checklist

- The materialization method and the pairing guard are IMPERATIVE, per the ADR-031 lifecycle-guard exception documented in design.md — do not attempt to express them as `x-openregister-calculations`/`-relations` (the dialect gap is real and documented, not a shortcut).
- Materialization failure is logged loudly but does not roll back the already-persisted `enacted` transition (matches the existing `generateResolutionRecord()` precedent in the same file).
- `Membership` creation goes through `ObjectServiceInterface::saveObject()` — no direct DB access, no bypass of per-object write ACL.
- No hardcoded colours; no new Vue components (none needed, per design.md D2).
- `openspec validate` passes.
- Fix any pre-existing quality issues (PHPCS, PHPMD, PHPStan, Psalm, hydra-gates) encountered in `DecisionLifecycleService.php` while editing it.

## Verification
- [ ] All implementation tasks done (pairing guard, materialization, detail-page visibility)
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (enact an appointment seed decision; confirm Membership created and visible on the target GovernanceBody)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests for the pairing guard (mismatch/0/1/N cases) and materialization (person candidate, external candidate, multi-candidate/multi-post, rejected-outcome no-op, idempotency)
- N/A Newman/Postman — no API endpoint changes (existing `POST /api/decisions/{id}/transition` endpoint unchanged in shape)
- [ ] Browser tests (Playwright MCP) confirming an enacted appointment seed decision's target GovernanceBody shows the new Membership in its Members list
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` describing appointment adoption → Membership materialization
- [ ] Screenshot captured and committed to `docs/images/` (Decision detail page showing appointment fields)

## i18n (company-wide ADR-005)
- N/A — no new user-facing strings beyond the field `title`/labels already added in `appointment-decision-type-schema`
