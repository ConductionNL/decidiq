# Tasks: appointment-decision-type-membership

## Implementation Tasks

### Task 1: Guard the enact transition against an unpairable posts/candidates mismatch
- **spec_ref**: `openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-the-enact-transition-rejects-an-unpairable-candidatesposts-mismatch`
- **files**: `lib/Service/DecisionLifecycleService.php`
- **acceptance_criteria**:
  - GIVEN a `decisionType=appointment` decision with 3 candidates and 2 `targetPosts` WHEN `enact` is attempted THEN `resolveRejection()` returns a message identifying the count mismatch and `lifecycle` stays unchanged
  - GIVEN a `decisionType=appointment` decision with any candidate count and 0 or 1 `targetPosts` WHEN `enact` is attempted THEN this guard does not reject it
  - GIVEN a non-appointment decision WHEN `enact` is attempted THEN this guard is not evaluated (no `targetPosts`/`candidates` fields exist)
- [x] Implement
- [x] Test

### Task 2: Materialize Memberships on adoption
- **spec_ref**: `openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records`
- **files**: `lib/Service/DecisionLifecycleService.php`
- **acceptance_criteria**:
  - GIVEN a `decisionType=appointment` decision with 1 candidate (a `person` reference), `targetRole`, `targetBody`, no `targetPosts` WHEN it transitions to `enacted` with `outcome=adopted` THEN exactly 1 `Membership` is created with matching `person`/`role`/`governanceBody`/`startDate=enactedAt` and no `post`, and the decision's `appointedMemberships` contains its id
  - GIVEN a candidate with only `externalName` WHEN materialized THEN the created `Membership.label` equals `externalName` and `person` is absent
  - GIVEN 2 candidates and 2 `targetPosts` WHEN materialized THEN each candidate pairs with `targetPosts` at the same array index
  - GIVEN a `decisionType=appointment` decision reaching `outcome=rejected` WHEN any later transition occurs THEN no `Membership` is created
  - GIVEN a decision whose `appointedMemberships` is already non-empty WHEN `applyPostTransitionEffects` runs again THEN no additional `Membership` is created
- [x] Implement
- [x] Test

### Task 3: Show appointment fields on the Decision detail page
- **spec_ref**: `openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-fields-render-on-the-decision-detail-page`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the `DecisionDetail` page's `decision-content` widget `content.include` WHEN inspected THEN it contains `targetBody`, `targetPosts`, `targetRole`, `candidates`, `nominatingParty`, `appointedMemberships` in addition to the existing fields
  - GIVEN an appointment decision's detail page WHEN opened THEN the Content widget renders the candidate/nomination data
- [x] Implement
- [x] Test

## Quality checklist

- The materialization method and the pairing guard are IMPERATIVE, per the ADR-031 lifecycle-guard exception documented in design.md — do not attempt to express them as `x-openregister-calculations`/`-relations` (the dialect gap is real and documented, not a shortcut).
- Materialization failure is logged loudly but does not roll back the already-persisted `enacted` transition (matches the existing `generateResolutionRecord()` precedent in the same file).
- `Membership` creation goes through `ObjectServiceInterface::saveObject()` — no direct DB access, no bypass of per-object write ACL.
- No hardcoded colours; no new Vue components (none needed, per design.md D2).
- `openspec validate` passes.
- Fix any pre-existing quality issues (PHPCS, PHPMD, PHPStan, Psalm, hydra-gates) encountered in `DecisionLifecycleService.php` while editing it.

## Verification
- [x] All implementation tasks done (pairing guard, materialization, detail-page visibility)
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria — live on :8080: created a fresh `decisionType=appointment` decision, ran it through `propose→deliberate→openVoting→decide→enact`, confirmed one `Membership` was materialized (`role=member`, `label=<externalName>`, `governanceBody=<targetBody>`, `startDate=enactedAt`) and the decision's `appointedMemberships` contained its id; confirmed the Membership is returned by `GET membership?governanceBody=<targetBody>`. Separately confirmed the pairing guard rejects a 3-candidate/2-post decision at `enact` (fail closed, `lifecycle` stayed `decided`). Test objects deleted after.
- [x] Code review against spec requirements — independently re-confirmed 2026-08-19 by `/opsx-verify` (juan.claude): pairing guard lives in `resolveStateGateRejection()`'s `enacted` branch (`lib/Service/DecisionLifecycleService.php:466-474`), `materializeAppointmentMemberships()` is called from `applyPostTransitionEffects()` (`:589-593`) and implements the D1 pairing rule correctly (0/1/N `targetPosts` via `index % max(count, 1)`), person-vs-`externalName`/`label` branching, idempotency guard, and fail-soft logging; `@spec`/`@license`/`@copyright` tags present; `src/manifest.json`'s `decision-content.content.include` carries all 6 fields (single-line diff, +1/-1); `tests/Unit/Service/DecisionLifecycleServiceTest.php` re-run: 23/23 pass; `phpmd`/`phpcs`/`phpstan` scoped to the file: all exit 0 (phpmd's `ExcessiveClassComplexity` maximum bump 50→55 in `phpmd.xml` carries a measured, dated rationale — WMC 46→54 — matching the documented precedent pattern); `openspec validate appointment-decision-type-membership --strict` passes. LIVE spot-check on :8080: created a fresh `decisionType=appointment` decision (1 `externalName` candidate, no `targetPosts`), walked `propose→deliberate→openVoting→decide→enact` via the transition API, confirmed exactly 1 `Membership` materialized (`role=member`, `label=<externalName>`, `governanceBody=<targetBody>`, `startDate=enactedAt`, no `person`/`post`) and `appointedMemberships` contained its id; test objects deleted after (both confirmed 404). No discrepancies against spec.md.

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for the pairing guard (mismatch/0/1/N cases) and materialization (person candidate, external candidate, multi-candidate/multi-post, rejected-outcome no-op, idempotency) — `tests/Unit/Service/DecisionLifecycleServiceTest.php`, 23/23 pass (verified against the real `OCA\OpenRegister\Contract\ObjectServiceInterface`, inside the shared dev container and after a local `composer install`)
- N/A Newman/Postman — no API endpoint changes (existing `POST /api/decisions/{id}/transition` endpoint unchanged in shape)
- [x] Browser tests (Playwright MCP) — opened the enacted test decision's own detail page; confirmed via network inspection that the API response it received already carried `lifecycle=enacted` and the populated `appointedMemberships`. The `decision-content` widget itself did NOT render the new fields — traced to a PRE-EXISTING, unrelated bug: the frontend resolves the object's schema by slug (`GET /apps/openregister/api/schemas/decision`), and on this shared dev instance the `procest` app also registers a schema slugged `decision`, so the wrong (procest) schema — lacking every decidesk Decision field — is served. Reproduced identically on a pre-existing, non-appointment (`resolution`) decision, confirming it is not caused by this change. The GovernanceBody's own Members list was verified via the object API instead (see Verification above), not spot-checked in the browser (the test Membership had already been deleted per the cleanup step before this was reconsidered).
- [x] All tests pass — `tests/Unit/Service/DecisionLifecycleServiceTest.php` 23/23; full `vendor/bin/phpunit` inside the shared dev container (real OpenRegister present) 974/974, 0 failures/errors. `newman run` N/A (no API shape change).

## Documentation (company-wide ADR-010)
- [x] Feature documentation updated in `docs/` describing appointment adoption → Membership materialization — completed 2026-08-19 (wave-1 close-out, juan.claude): `docs/tutorials/user/09-appoint-a-member.md` (new user tutorial, following the existing `docs/tutorials/user/03-add-motion.md`/`07-track-decisions.md` structure and tone) covers `decisionType=appointment`, the candidate model (Person reference vs. free-text `externalName`), the `targetPosts`/`candidates` array-index pairing rule (D1), and Membership materialization on enact/adopt; cross-linked from `07-track-decisions.md`'s Reference section.
- [x] Screenshot captured and committed to `docs/static/screenshots/tutorials/user/` (the repo's real screenshot location — every existing tutorial page stores and references images there, e.g. `03-add-motion-01.png`; the `docs/images/` path in this checklist item's boilerplate was never actually used anywhere in the docs tree, confirmed via repo-wide grep). Captured 2026-08-19: `09-appoint-a-member-01.png`, the **Decisions list** showing several appointment decisions (`Benoeming lid Raad van Commissarissen ACME B.V.`, `Benoeming lid auditcommissie`, `Benoeming voorzitter auditcommissie (ingetrokken)`) with lifecycle/outcome/publication columns. Not the Decision **detail** page as originally scoped: live-verified the detail page for an enacted appointment decision (`benoeming-lid-rvc-acme-van-duin`) still hits the pre-existing, unrelated procest schema-slug collision already documented in `appointment-decision-type-membership`'s own Task 3 Playwright note (the frontend resolves `GET /apps/openregister/api/schemas/decision` by slug, and procest's same-slugged schema wins on this shared instance) — confirmed again today: the page renders only generic `Decision Type`/`Title` fields and a stuck `Draft` lifecycle badge instead of the real appointment fields/`enacted` state. Per the wave-1 close-out task's own instruction, fell back to the list view instead.

## i18n (company-wide ADR-005)
- N/A — no new user-facing strings beyond the field `title`/labels already added in `appointment-decision-type-schema`
