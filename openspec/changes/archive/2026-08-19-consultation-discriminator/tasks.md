# Tasks: consultation-discriminator

## Implementation Tasks

### Task 1: Record the ADR-006 addendum
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md`
- **acceptance_criteria**:
  - GIVEN the new addendum section WHEN read THEN it contains the pairwise field-overlap table (`PublicConsultation`∩`MemberConsultation` = 2/28 · 2/15; `PublicConsultation`∩`ConsultationRequest` = 2/28 · 2/20; `MemberConsultation`∩`ConsultationRequest` = 2/15 · 2/20), the four qualitative signals (authorization block, `x-schema-org` type, structural cross-reference, lifecycle shape), and the stated outcome (`PublicConsultation` unchanged as the sole discriminated concept; `MemberConsultation` and `ConsultationRequest` each independently exempted)
  - GIVEN the ADR file WHEN diffed THEN the existing "Decision" and "Consequences" sections are untouched — the addendum is additive only, appended after "## Consequences"
- [x] Implement
- [x] Test

### Task 2: Cross-reference the addendum on PublicConsultation
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN `PublicConsultation`'s `description` WHEN read THEN it references the ADR-006 addendum, confirming `PublicConsultation` remains the sole discriminated concept for the public/market-consultation family
  - GIVEN the schema's `properties`, `required`, `authorization`, and `x-openregister-lifecycle` blocks WHEN diffed against the pre-change version THEN they are unchanged; only `description` and `version` (`0.3.0` → `0.3.1`) differ
- [x] Implement
- [x] Test

### Task 3: Cross-reference the addendum on MemberConsultation and ConsultationRequest
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `lib/Settings/register.d/48-constituency-consultation.json`, `lib/Settings/register.d/47-works-council-consultation.json`
- **acceptance_criteria**:
  - GIVEN `MemberConsultation`'s `description` WHEN read THEN it references the ADR-006 addendum, stating it is exempted from folding as a genuinely distinct concept; `version` bumped `0.1.0` → `0.1.1`; no other field, lifecycle, notification, or seed-data changes
  - GIVEN `ConsultationRequest`'s `description` WHEN read THEN it references the ADR-006 addendum, stating it is exempted from folding as a genuinely distinct concept; `version` bumped `0.1.0` → `0.1.1`; no other field, lifecycle, notification, or seed-data changes
  - GIVEN both files are owned by the still-open `constituency-consultation` and `works-council-consultation` OpenSpec changes WHEN this task's diff is checked immediately before commit THEN it touches only `description` and `version` on the `MemberConsultation`/`ConsultationRequest` schema objects — re-run `git diff` on both files right before committing to catch any concurrent edit from those sibling changes (proposal Risk 1)
- [x] Implement
- [x] Test

### Task 4: Verify the three DecisionDetail consultation widgets/pages need no change
- **spec_ref**: `openspec/changes/consultation-discriminator/specs/citizen-participation/spec.md#requirement-consultation-family-discriminator-boundary-adr-006`
- **files**: `src/manifest.json`, `src/manifest.d/citizen-participation.json`, `src/manifest.d/constituency-consultation.json`, `src/manifest.d/works-council-consultation.json`, `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` WHEN grepped for `decision-public-consultations`, `decision-member-consultations`, `decision-wor-consultations` THEN all three widgets still reference their original schema slugs (`public-consultation`, `member-consultation`, `consultation-request` respectively) — none retired or repointed
  - GIVEN `src/menu-layout.json` WHEN checked THEN `Raadplegingen`, `Consultations`, and `WorTrajecten` still map to the `Decisions` nav-ceiling cluster and no new top-level nav entry has been added
  - GIVEN this task makes no edits to any of the listed files THEN it is a verification-only task — record the grep output as evidence in the PR description rather than modifying these files
- [x] Implement
- [x] Test

## Quality checklist

<!-- This change is kind: config — description/version-only edits to existing JSON schema registers plus markdown (ADR + spec). No PHP, Vue, or route code changes; no new business logic, API endpoint, or UI surface. -->

- PHPUnit / Newman / Playwright: N/A — no business logic, API endpoint, or UI behavior changes (verified by Task 2/3's "no field/lifecycle/authorization diff" acceptance criteria)
- Feature documentation (`docs/`): N/A — internal architecture/governance record, not a user-facing feature
- Dutch/English translation strings (ADR-007): N/A — no new user-facing strings introduced
- `openspec validate` passes
- JSON validity of `lib/Settings/decidesk_register.json`, `lib/Settings/register.d/47-works-council-consultation.json`, `lib/Settings/register.d/48-constituency-consultation.json` after edits (e.g. `python3 -m json.tool` or the app's existing schema-import check)

## Verification Notes (opsx-verify 2026-08-19)
VERDICT: PASS
- Task 1 (ADR-006 addendum): `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md` lines 103-189 (commit 3eb5f502) — addendum appended immediately after `## Consequences` (line 100 diff context), contains the pairwise overlap table, four qualitative signals, and outcome exactly as specified. `git show 3eb5f502` confirms the pre-existing "Decision"/"Consequences" sections are untouched (pure addition, no deletions in that hunk) — OK
- Task 2 (PublicConsultation cross-reference): `lib/Settings/decidesk_register.json` `.components.schemas.PublicConsultation` — `version` `0.3.0`→`0.3.1`, `description` references the addendum verbatim ("ADR-006 addendum (consultation-discriminator, 2026-08-19): PublicConsultation remains the sole discriminated concept..."). `git show 3eb5f502 -- lib/Settings/decidesk_register.json` shows exactly 2 changed lines (`version` + `description`); `properties`/`required`/`authorization`/`x-openregister-lifecycle` untouched — OK
- Task 3 (MemberConsultation + ConsultationRequest cross-reference): `lib/Settings/register.d/48-constituency-consultation.json` `MemberConsultation` — `version` `0.1.0`→`0.1.1`, description references addendum with "13% measured field overlap"; `lib/Settings/register.d/47-works-council-consultation.json` `ConsultationRequest` — `version` `0.1.0`→`0.1.1`, description references addendum with "10% measured field overlap". Both diffs (`git show 3eb5f502`) are exactly 2 changed lines each (`version` + `description`); no field/lifecycle/seed-data changes — OK
- Task 4 (widget/nav verification): `src/manifest.json:1068-1070` — the three widgets `decision-public-consultations`/`decision-member-consultations`/`decision-wor-consultations` still reference `"schema": "public-consultation"` / `"member-consultation"` / `"consultation-request"` respectively, none retired or repointed. `src/menu-layout.json:14-16` — `Raadplegingen`, `Consultations`, `WorTrajecten` all still map to `"Decisions"`. `git show --stat 3eb5f502` confirms neither `src/manifest.json` nor `src/menu-layout.json` appears in this change's commit (verification-only, no edits) — OK
- Field-overlap measurements (programmatic re-check): `PublicConsultation` 28 properties, `MemberConsultation` 15, `ConsultationRequest` 20 (counted directly from each schema's `properties` object). `PC ∩ MC = {'decision','description'}` (2/15=13.3%→"13%"), `PC ∩ CR = {'relatedDecision','governanceBody'}` (2/20=10%), `MC ∩ CR = {'agendaItem','lifecycle'}` — all three sets match the ADR addendum and spec.md exactly, not just asserted — OK
- Qualitative signals re-check: `x-schema-org` = `schema:Event` (PC, via `x-openregister.schemaType`), `schema:AskAction` (MC), `schema:Action` (CR) — confirmed distinct. `authorization` block present only on PC (`public` group read gated on `publicationDate <= $now`); `None` on MC and CR — confirmed. `ConsultationRequest.constituencyConsultation` field present, `$ref: MemberConsultation`, nullable — confirmed live structural cross-reference — OK
- Discriminator mechanism (PublicConsultation): `lib/Settings/decidesk_register.json` `PublicConsultation.properties.consultationType.enum` = `['citizen-participation','market-consultation','tender','idea-box','participatory-budget']` — the 5-value discriminator this change deliberately left unchanged is confirmed still present and unmodified — OK
- Spec requirement (`citizen-participation` spec.md, "Consultation family discriminator boundary (ADR-006)"): backed by the ADR addendum (Task 1) + both schema cross-references (Tasks 2/3) + the manifest/nav verification (Task 4) — no gap found
- `openspec validate consultation-discriminator --strict` → "Change 'consultation-discriminator' is valid" — OK
- No discrepancies found. This is a pure documentation/schema-description change (`kind: config`); no PHP/Vue/route code was touched, consistent with the proposal's stated scope.
