# board-self-evaluation Specification

## Purpose
TBD - created by archiving change board-self-evaluation. Update Purpose after archive.
## Requirements
### Requirement: REQ-EVAL-001 Board evaluation cycle bound to a governance body
The system SHALL provide a `BoardEvaluation` OpenRegister object related to a `GovernanceBody`,
carrying a `cycleLabel` and a `lifecycle` of `draft`, `open`, `closed`, or `published`, so a body
runs one evaluation per cycle and past cycles remain available for comparison. A `BoardEvaluation`
SHALL reference an `EvaluationTemplate` and SHALL store `invitedMemberCount` and `respondedCount`.
The entity SHALL be stored as an OpenRegister object (no app-owned table).

#### Scenario: A body opens an evaluation cycle
- **GIVEN** a GovernanceBody and a selected EvaluationTemplate
- **WHEN** a chair or secretary creates a `BoardEvaluation` for cycle "2026" and opens it
- **THEN** a `BoardEvaluation` object exists related to that body with `lifecycle: open` and
  `cycleLabel: 2026`.

#### Scenario: Past cycles remain comparable
- **GIVEN** a body with a closed "2025" evaluation
- **WHEN** a "2026" evaluation is created
- **THEN** both cycles exist as distinct `BoardEvaluation` objects on the body
- **AND** the 2025 scores remain readable for trend comparison.

@e2e exclude two-cycle coexistence is a data-model invariant (two OpenRegister objects on the same body), not a distinct UI flow; covered by RegisterJsonTest's seed assertions and exercised implicitly whenever the e2e board-evaluation-workflow spec creates a second cycle on a body that already has a closed one.

### Requirement: REQ-EVAL-002 Reusable questionnaire template organised by effectiveness dimensions
The system SHALL provide an `EvaluationTemplate` OpenRegister object holding a question set organised
by board-effectiveness `dimensions` (such as strategy-and-oversight, board-composition,
board-dynamics, information-quality, chair-effectiveness), where each question declares its
`dimension`, a `prompt`, and a `type` of `likert` or `free-text`. A template SHALL be reusable across
bodies and cycles, and one editable default template SHALL be available as seed data.

#### Scenario: A template groups questions by dimension
- **GIVEN** an EvaluationTemplate
- **WHEN** it is read
- **THEN** each question declares a `dimension` and a `type` of `likert` or `free-text`
- **AND** the same template can be selected by more than one body/cycle.

@e2e exclude schema-shape assertion (every question declares dimension+type), not a UI flow; covered by the register JSON structure itself and exercised implicitly by the e2e board-evaluation-workflow spec, which reads real template questions to drive the respond flow.

#### Scenario: A default template ships as seed
- **GIVEN** a fresh install
- **WHEN** a body starts its first evaluation
- **THEN** at least one reusable default EvaluationTemplate is available to select and edit.

@e2e exclude fresh-install seed-data presence is a deployment/import-time invariant, not a runtime UI flow; the e2e board-evaluation-workflow spec seeds and uses a template directly rather than asserting on install-time seed presence.

### Requirement: REQ-EVAL-003 Responses are anonymous and untraceable to the member
Member responses SHALL be collected so the response content is NOT traceable to the responding
member, reusing the existing secret-ballot anonymity guarantee (even an administrator cannot trace a
response to a member). Each invited member SHALL be able to submit exactly one `EvaluationResponse`,
and completion (which members have or have not responded) SHALL be tracked separately from the
response content so non-responders can be chased without linking identity to answers. Response
content SHALL NOT store a member relation.

#### Scenario: A response cannot be traced to its author
- **GIVEN** a member who has submitted an evaluation response
- **WHEN** an administrator inspects the stored `EvaluationResponse` content
- **THEN** no member identity is recoverable from the response content.

#### Scenario: Completion is tracked without de-anonymising
- **GIVEN** an open evaluation with 7 invited members and 4 responses
- **WHEN** the organiser views progress
- **THEN** `respondedCount` is 4 and the 3 non-responders can be reminded
- **AND** the 4 submitted answers remain unlinkable to specific members.

### Requirement: REQ-EVAL-004 Per-dimension and overall board-effectiveness scores
On closing a cycle the system SHALL compute, as an in-app governance-specific calculation, a
per-dimension mean of the Likert answers and an overall board-effectiveness score, plus a grouping of
free-text themes, and SHALL materialise the result onto the `BoardEvaluation` as `scoreSummary`.
Below a configurable minimum-respondent threshold the system SHALL show only the aggregate overall
score and SHALL suppress per-dimension and free-text breakdowns to prevent de-anonymisation by
inference.

#### Scenario: Scores are computed on close
- **GIVEN** an open evaluation with responses across its dimensions
- **WHEN** the cycle is closed
- **THEN** a per-dimension mean and an overall effectiveness score are computed and materialised as
  `scoreSummary` on the `BoardEvaluation`.

#### Scenario: Small-body breakdown is suppressed
- **GIVEN** a closed evaluation whose respondent count is below the minimum threshold
- **WHEN** the results are viewed
- **THEN** only the aggregate overall score is shown
- **AND** per-dimension and free-text breakdowns are suppressed.

### Requirement: REQ-EVAL-005 Dashboard, report, and optional publication reuse existing surfaces
The evaluation results dashboard SHALL be rendered by the Analytics leaf
(`governance-analytics-via-analytics-leaf`), not by a bespoke chart component; the evaluation report
document SHALL be generated through the existing minutes/document generation path (Docudesk PDF where
present, honest fallback otherwise); and publication of the effectiveness summary SHALL, when a body
opts in, reuse the existing publication stack and `publicatiedatum` window. Raw responses SHALL NEVER
be published — only the aggregate summary.

#### Scenario: Results render via the Analytics leaf
- **GIVEN** a closed evaluation with a materialised `scoreSummary`
- **WHEN** the results dashboard is viewed on the GovernanceBody detail
- **THEN** the scores and cycle trend are rendered by the Analytics leaf.

#### Scenario: Publishing exposes only the aggregate
- **GIVEN** a body that opts to publish its effectiveness summary
- **WHEN** the summary is published through the existing publication path
- **THEN** only the aggregate score/summary enters the public window
- **AND** no raw `EvaluationResponse` is ever published.

### Requirement: REQ-EVAL-006 One mode-adaptive entity across governance domains
The board-evaluation model SHALL serve corporate boards, association boards, and councils through
mode adaptation (ADR-006) rather than parallel per-domain entities, and any actor-gating on the
evaluation lifecycle SHALL be expressed as OpenRegister RBAC (per `consume-or-rbac-authorization`)
rather than an app-local authorization service.

#### Scenario: The same entity serves a corporate board and a council
- **GIVEN** a corporate supervisory board and a municipal council
- **WHEN** each runs a self-evaluation
- **THEN** both use the same `BoardEvaluation`/template/response model with mode labels
- **AND** no parallel per-domain evaluation schema exists.

@e2e exclude structural claim about the register (one schema, no parallel per-domain entity), not a distinct runtime flow — proven by construction (RegisterJsonTest asserts the schema count/names) and exercised implicitly since the e2e board-evaluation-workflow spec runs the same create/respond/close flow against a `bodyType: council` GovernanceBody as any other body, through the identical BoardEvaluation schema.

#### Scenario: Lifecycle gating is OR RBAC, not app-local
- **GIVEN** a user who is not the body's chair or secretary
- **WHEN** they attempt to open or close an evaluation cycle
- **THEN** OpenRegister RBAC denies the write
- **AND** no app-local authorization service performs the check.

