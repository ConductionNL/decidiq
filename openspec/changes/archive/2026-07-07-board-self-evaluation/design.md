# Design: board-self-evaluation

## Context
A periodic board self-evaluation (`zelfevaluatie` / board-effectiveness review) is a governance-code
expectation for the corporate and association bodies decidesk serves, and the single highest-demand
capability decidesk lacks (`conduct-annual-board-evaluation`, demand 1431, `must`; "Board
Effectiveness Score" ships in 36 mapped competitors). decidesk has none today (no schema, service, or
spec at HEAD). This design adds it **without** re-implementing infrastructure decidesk already owns:
OpenRegister objects for storage, the secret-ballot mechanism for anonymity, the Analytics leaf for
dashboards, and the existing document/publication path for the report.

## Goals / Non-Goals
**Goals**
- A body runs one anonymous self-evaluation per cycle and gets per-dimension + overall scores.
- Past cycles are comparable (trend over time).
- Works across corporate boards, association boards, and councils via one mode-adaptive entity.
- Zero new survey engine, zero new analytics renderer, zero app-owned tables.

**Non-Goals**
- Per-director/individual appraisal (hrmq territory).
- External cross-organisation benchmarking.
- A bespoke questionnaire delivery engine (Forms leaf is a future option, not built here).

## Data Model (OpenRegister objects in the decidesk register)
- **EvaluationTemplate** — a reusable question set. Fields: `title`, `description`, `dimensions[]`
  (e.g. strategy-and-oversight, board-composition, board-dynamics, information-quality,
  chair-effectiveness), `questions[]` (each: `dimension`, `prompt`, `type` ∈ {`likert`, `free-text`},
  optional `scaleMin`/`scaleMax`). Schema.org: `CreativeWork`. One editable default template ships as
  seed data.
- **BoardEvaluation** — one evaluation cycle for a body. Fields: `GovernanceBody` (relation),
  `template` (relation), `cycleLabel` (e.g. "2026"), `lifecycle` ∈ {`draft`, `open`, `closed`,
  `published`}, `openedAt`, `closedAt`, `invitedMemberCount`, `respondedCount`,
  `minRespondentThreshold` (default from config), `scoreSummary` (materialised — see below),
  publication window fields reusing the existing `publicatiedatum`/`depublicatiedatum` pattern.
  Schema.org: `Action`.
- **EvaluationResponse** — one member's anonymous submission. Fields: `BoardEvaluation` (relation),
  `answers[]` (each: `question`, `likertValue?`, `freeText?`). **No member relation is stored on the
  response content** — see Anonymity below. Schema.org: `Action`.

No app-owned tables: all three are OpenRegister objects, consistent with decidesk's thin-client
architecture.

## Decisions

### D1 — Anonymity inherits the secret-ballot guarantee, not a new mechanism
Responses are collected through the **existing anonymous-ballot path** (the same mechanism behind
`secret-ballot`, whose contract is "members vote anonymously and even an admin cannot trace a ballot
back"). The `EvaluationResponse` content carries **no** member identity. Completion (has member X
responded?) is tracked separately from content — the invited roster and a per-member "responded"
flag live apart from the answer payload, so decidesk can chase non-responders without ever linking a
member to their answers. This reuses the anonymity infrastructure rather than re-deriving it, and
avoids inventing a second anonymity model.

### D2 — Small-body de-anonymisation guard
Below `minRespondentThreshold` respondents, decidesk shows **only** the aggregate overall score and
suppresses per-dimension and free-text breakdowns, so a 3-person board cannot infer an individual's
answers. The threshold is configurable (default resolved in tasks; leaning 3).

### D3 — Governance-specific scoring stays in-app; rendering uses the Analytics leaf
Per `governance-analytics-via-analytics-leaf` REQ-AN-LEAF-002 ("generic aggregations move to the
Analytics leaf; governance-specific calculations stay in-app"), the **board-effectiveness score** is
a governance-specific calc and lives in a thin `BoardEvaluationScoreService`: per-dimension mean of
Likert answers + an overall weighted score + free-text theme grouping. The **dashboard** that
displays those numbers (and the cycle-over-cycle trend) is rendered by the **Analytics leaf**, not by
a bespoke chart component. The computed `scoreSummary` is materialised onto `BoardEvaluation` so the
dashboard and report read a stable value.

### D4 — Report + optional publication reuse existing paths
The evaluation **report document** is generated through the existing minutes/document generation path
(markdown canonical; Docudesk PDF where the app is present, honest fallback otherwise) — no new
renderer. When a body opts to publish its effectiveness summary (governance transparency / WOO), the
existing publication stack (publication services + `publicatiedatum` RBAC window) is used; the raw
responses are **never** published, only the aggregate summary.

### D5 — One mode-adaptive entity (ADR-006)
A single `BoardEvaluation`/template/response model serves corporate boards, association boards, and
councils; domain differences are mode labels + the chosen template, not parallel schemas. This
follows ADR-006 (mode adaptation over parallel entities) exactly as the rest of decidesk does.

## Lifecycle
`draft` (author/select template, set roster) → `open` (members submit anonymous responses) →
`closed` (scoring runs, `scoreSummary` materialised, report generated) → `published` (optional; only
the aggregate summary enters the public window). Transitions follow the existing meeting/decision
lifecycle conventions and, per `consume-or-rbac-authorization`, any actor-gating is expressed as OR
RBAC (e.g. only the body's chair/secretary may open/close a cycle) rather than an app-local guard.

## Alternatives Considered
- **Deliver the questionnaire via the Nextcloud Forms leaf.** Attractive (reuse a real form engine)
  but Forms has no OpenRegister leaf today and the evaluation *record* must be a decidesk domain
  object (relates to the body, feeds governance analytics, is an archival/WOO record). Chosen: keep
  the record + anonymous collection in decidesk; note Forms delivery as a future enhancement.
- **Individual-director 360° appraisal.** Rejected as out of scope — that is per-person HR appraisal
  (hrmq), not board-level effectiveness.

## Test Strategy
- Unit: scoring (per-dimension mean, overall weighted score, threshold suppression); anonymity —
  no member identity is recoverable from response content; completion tracking is independent of
  content.
- e2e (Playwright, UI): a body runs a cycle, three members submit anonymously, the chair closes the
  cycle, the dashboard shows the scores via the Analytics leaf, the report generates, and publishing
  exposes only the aggregate (never a raw response).
- Standards/compliance: WCAG 2.1 AA on the respond + results surfaces; i18n nl + en.
