# Tasks: consultation-discriminator

## Implementation Tasks

### Task 1: Record the ADR-006 addendum
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md`
- **acceptance_criteria**:
  - GIVEN the new addendum section WHEN read THEN it contains the pairwise field-overlap table (`PublicConsultation`∩`MemberConsultation` = 2/28 · 2/15; `PublicConsultation`∩`ConsultationRequest` = 2/28 · 2/20; `MemberConsultation`∩`ConsultationRequest` = 2/15 · 2/20), the four qualitative signals (authorization block, `x-schema-org` type, structural cross-reference, lifecycle shape), and the stated outcome (`PublicConsultation` unchanged as the sole discriminated concept; `MemberConsultation` and `ConsultationRequest` each independently exempted)
  - GIVEN the ADR file WHEN diffed THEN the existing "Decision" and "Consequences" sections are untouched — the addendum is additive only, appended after "## Consequences"
- [ ] Implement
- [ ] Test

### Task 2: Cross-reference the addendum on PublicConsultation
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN `PublicConsultation`'s `description` WHEN read THEN it references the ADR-006 addendum, confirming `PublicConsultation` remains the sole discriminated concept for the public/market-consultation family
  - GIVEN the schema's `properties`, `required`, `authorization`, and `x-openregister-lifecycle` blocks WHEN diffed against the pre-change version THEN they are unchanged; only `description` and `version` (`0.3.0` → `0.3.1`) differ
- [ ] Implement
- [ ] Test

### Task 3: Cross-reference the addendum on MemberConsultation and ConsultationRequest
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `lib/Settings/register.d/48-constituency-consultation.json`, `lib/Settings/register.d/47-works-council-consultation.json`
- **acceptance_criteria**:
  - GIVEN `MemberConsultation`'s `description` WHEN read THEN it references the ADR-006 addendum, stating it is exempted from folding as a genuinely distinct concept; `version` bumped `0.1.0` → `0.1.1`; no other field, lifecycle, notification, or seed-data changes
  - GIVEN `ConsultationRequest`'s `description` WHEN read THEN it references the ADR-006 addendum, stating it is exempted from folding as a genuinely distinct concept; `version` bumped `0.1.0` → `0.1.1`; no other field, lifecycle, notification, or seed-data changes
  - GIVEN both files are owned by the still-open `constituency-consultation` and `works-council-consultation` OpenSpec changes WHEN this task's diff is checked immediately before commit THEN it touches only `description` and `version` on the `MemberConsultation`/`ConsultationRequest` schema objects — re-run `git diff` on both files right before committing to catch any concurrent edit from those sibling changes (proposal Risk 1)
- [ ] Implement
- [ ] Test

### Task 4: Verify the three DecisionDetail consultation widgets/pages need no change
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `src/manifest.json`, `src/manifest.d/citizen-participation.json`, `src/manifest.d/constituency-consultation.json`, `src/manifest.d/works-council-consultation.json`, `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` WHEN grepped for `decision-public-consultations`, `decision-member-consultations`, `decision-wor-consultations` THEN all three widgets still reference their original schema slugs (`public-consultation`, `member-consultation`, `consultation-request` respectively) — none retired or repointed
  - GIVEN `src/menu-layout.json` WHEN checked THEN `Raadplegingen`, `Consultations`, and `WorTrajecten` still map to the `Decisions` nav-ceiling cluster and no new top-level nav entry has been added
  - GIVEN this task makes no edits to any of the listed files THEN it is a verification-only task — record the grep output as evidence in the PR description rather than modifying these files
- [ ] Implement
- [ ] Test

## Quality checklist

<!-- This change is kind: config — description/version-only edits to existing JSON schema registers plus markdown (ADR + spec). No PHP, Vue, or route code changes; no new business logic, API endpoint, or UI surface. -->

- PHPUnit / Newman / Playwright: N/A — no business logic, API endpoint, or UI behavior changes (verified by Task 2/3's "no field/lifecycle/authorization diff" acceptance criteria)
- Feature documentation (`docs/`): N/A — internal architecture/governance record, not a user-facing feature
- Dutch/English translation strings (ADR-007): N/A — no new user-facing strings introduced
- `openspec validate` passes
- JSON validity of `lib/Settings/decidesk_register.json`, `lib/Settings/register.d/47-works-council-consultation.json`, `lib/Settings/register.d/48-constituency-consultation.json` after edits (e.g. `python3 -m json.tool` or the app's existing schema-import check)
