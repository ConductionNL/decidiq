# Tasks: board-self-evaluation

## Implementation Tasks

### Task 1: Evaluation schemas + default template seed
- **spec_ref**: `openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-001-board-evaluation-cycle-bound-to-a-governance-body`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `BoardEvaluation`, `EvaluationTemplate`, and `EvaluationResponse` schemas exist as OpenRegister objects with the fields in design.md
  - GIVEN a body + template WHEN a `BoardEvaluation` is created THEN it relates to the body, references the template, and starts `lifecycle: draft`
  - GIVEN two cycles on a body THEN both `BoardEvaluation` objects coexist and past scores stay readable
- [x] Add the three schemas (Schema.org annotations: CreativeWork / Action) and a reusable default `EvaluationTemplate` as seed data.
- [x] Test: schema validation; cycle creation; two-cycle coexistence.

### Task 2: Reusable dimension-organised questionnaire template
- **spec_ref**: `openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-002-reusable-questionnaire-template-organised-by-effectiveness-dimensions`
- **files**: `lib/Settings/decidesk_register.json`, `src/` (template author/select UI)
- **acceptance_criteria**:
  - GIVEN an EvaluationTemplate THEN each question declares a `dimension` and `type` ∈ {`likert`, `free-text`}
  - GIVEN a fresh install THEN at least one editable default template is selectable
  - GIVEN two bodies THEN the same template can be selected by both
- [x] Model the template question set by effectiveness dimensions; wire a select/edit UI.
- [x] Test: dimension + type on every question; default seed present; template reuse across bodies.

### Task 3: Anonymous response collection (reuse secret-ballot anonymity)
- **spec_ref**: `openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member`
- **files**: `lib/Service/` (reuse the anonymous-ballot collection), `src/` (respond flow)
- **acceptance_criteria**:
  - GIVEN a submitted response WHEN an admin inspects its content THEN no member identity is recoverable
  - GIVEN 7 invited / 4 responded THEN `respondedCount` is 4, non-responders are remindable, and answers stay unlinkable
  - GIVEN one member THEN exactly one `EvaluationResponse` can be submitted
- [x] Collect responses through the existing anonymous-ballot path; store no member relation on response content; track completion separately from content.
- [x] Test (UI): anonymous submit; admin cannot trace; completion vs content separation; one-per-member.

### Task 4: Per-dimension + overall scoring with small-body suppression
- **spec_ref**: `openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores`
- **files**: `lib/Service/BoardEvaluationScoreService.php` (new), `lib/Settings/decidesk_register.json` (`scoreSummary`, `minRespondentThreshold`)
- **acceptance_criteria**:
  - GIVEN a cycle is closed THEN per-dimension means + an overall score + free-text themes are computed and materialised as `scoreSummary`
  - GIVEN respondents below `minRespondentThreshold` THEN only the aggregate overall score shows; per-dimension + free-text breakdowns are suppressed
- [x] Implement the in-app governance-specific scoring (REQ-AN-LEAF-002) with threshold suppression; materialise `scoreSummary` on close.
- [x] Test: scoring math; threshold suppression boundary.

### Task 5: Dashboard (Analytics leaf), report, optional publication
- **spec_ref**: `openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces`
- **files**: `src/` (GovernanceBody results tab via Analytics leaf), `lib/Service/` (report via existing minutes/document path; publish via existing publication stack)
- **acceptance_criteria**:
  - GIVEN a closed evaluation THEN the results/trend dashboard is rendered by the Analytics leaf (no bespoke chart component)
  - GIVEN a body opts to publish THEN only the aggregate summary enters the public window; no raw response is published
  - GIVEN a closed cycle THEN a report document generates via the existing minutes/document path (Docudesk PDF where present, honest fallback)
- [x] Wire the results dashboard to the Analytics leaf; generate the report via the existing path; reuse the publication stack for opt-in publish (aggregate only).
- [x] Test (UI): Analytics-leaf render; publish exposes aggregate only; report generation + fallback.

### Task 6: Mode-adaptive entity + OR RBAC lifecycle gating + a11y/i18n
- **spec_ref**: `openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains`
- **files**: `lib/Settings/decidesk_register.json` (RBAC scope on the evaluation lifecycle), `src/`
- **acceptance_criteria**:
  - GIVEN a corporate board and a council THEN both use the same evaluation model with mode labels; no parallel per-domain schema exists
  - GIVEN a non-chair/secretary THEN OpenRegister RBAC denies opening/closing a cycle (no app-local authorization service)
  - GIVEN the respond + results surfaces THEN WCAG 2.1 AA holds and labels are present in nl + en
- [x] Ensure one mode-adaptive entity (ADR-006); gate the lifecycle via OR RBAC (consistent with `consume-or-rbac-authorization`); nl+en i18n; WCAG 2.1 AA.
- [x] Test: cross-domain reuse; RBAC denial for non-chair; a11y + i18n.
