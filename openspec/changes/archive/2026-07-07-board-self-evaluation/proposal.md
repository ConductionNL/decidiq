---
kind: feature
---

# Proposal: board-self-evaluation

## Summary
Add a periodic **board / governance-body self-evaluation** (board-effectiveness) capability: a
governance body runs a structured questionnaire — typically annually — to assess its own
functioning, members respond **anonymously**, and decidesk produces per-dimension scores, an overall
board-effectiveness score, and an evaluation report that can be archived and (where the body opts in)
published. This is the single **highest-demand capability decidesk currently lacks**
(`conduct-annual-board-evaluation`, demand 1431, priority `must`, 477 tender mentions), and it is a
table-stakes differentiator across the board-portal competitor set ("Board Effectiveness Score" ships
in 36 of decidesk's mapped competitors — Diligent, BoardEffect, Aprio, Boardable, Boardspan, Atlas
Governance, and peers).

## Motivation
decidesk positions itself for five governance domains including **corporate governance** (RvC/RvB)
and **associations/NGOs**, where a periodic board self-evaluation is a governance-code expectation
(NL Corporate Governance Code principle 1.3; the two-tier board's `zelfevaluatie`; association
statuten; and increasingly `raadsevaluatie` for municipal councils). Yet decidesk has **no board
evaluation of any kind** — verified at HEAD: no schema, no service, no spec; the only occurrences of
"board evaluation" are persona-responsibility notes in archived context-briefs.

Competitors treat board effectiveness as a headline feature. The demand signal is unambiguous:
`conduct-annual-board-evaluation` is a `must` at demand 1431 (the top non-covered decidesk
capability), and "Board Effectiveness Score" is one of the most common differentiators in the
board-portal cluster. Closing it turns a governance-code obligation staff run today in spreadsheets
and email into a first-class, anonymous, auditable decidesk workflow.

## Affected Projects
- [x] Project: `decidesk` — new evaluation schemas in the OpenRegister register, a thin
  governance-specific scoring service, anonymous response collection reusing the secret-ballot
  pattern, and dashboard/report surfaces over the existing Analytics leaf + publication stack.

## Scope

### In Scope
- **Evaluation cycle.** A `BoardEvaluation` object related to a `GovernanceBody`, with a period/cycle
  label and a lifecycle (`draft → open → closed → published`), so a body runs one evaluation per
  cycle and past cycles remain comparable.
- **Reusable questionnaire template.** A question set organised by the standard board-effectiveness
  dimensions (e.g. strategy & oversight, board composition, board dynamics/culture, information
  quality, chair effectiveness), with Likert-scale items and free-text items; templates are reusable
  across bodies and cycles.
- **Anonymous responses.** One response per invited member, collected so the content is **not
  traceable to the member** — reusing the existing secret-ballot anonymity guarantee (even an admin
  cannot trace a response) — while completion (who has/has not responded) is tracked without linking
  identity to content.
- **Scoring.** A governance-specific calculation producing per-dimension averages and an overall
  board-effectiveness score, plus aggregated free-text themes. Per
  `governance-analytics-via-analytics-leaf` REQ-AN-LEAF-002, this governance-specific calc stays
  in-app; generic aggregation/rendering uses the Analytics leaf.
- **Report + dashboard + optional publication.** An evaluation dashboard rendered by the Analytics
  leaf; a report document generated through the existing minutes/document path (Docudesk PDF where
  present, honest fallback otherwise); optional publication of the score/summary through the existing
  publication stack when the body opts in (WOO/governance transparency).
- **Mode-adaptive, one entity.** Works for corporate boards, association boards, and councils via
  mode adaptation (ADR-006) — no parallel per-domain evaluation entity.

### Out of Scope
- 360°/individual-director appraisals (this is *board*-level effectiveness, not per-person HR
  appraisal — that is hrmq territory).
- A bespoke survey engine or a second questionnaire store — response collection reuses the existing
  vote/ballot anonymity infrastructure; questionnaire *delivery* via the Nextcloud **Forms** leaf is
  noted as a future option, not built here.
- External benchmarking against other organisations' boards.
- Any new analytics-rendering engine — dashboards use the Analytics leaf
  (`governance-analytics-via-analytics-leaf`).

## Approach
Add `BoardEvaluation`, `EvaluationTemplate`/`EvaluationQuestion`, and `EvaluationResponse` schemas to
`lib/Settings/decidesk_register.json` (OpenRegister objects; no app-owned tables). Collect responses
through the existing anonymous-ballot mechanism so the anonymity guarantee is inherited, not
re-implemented. Compute per-dimension + overall scores in a thin `BoardEvaluationScoreService`
(governance-specific calc, in-app per REQ-AN-LEAF-002). Surface the dashboard via the Analytics leaf
and generate the report via the existing minutes/document generation path; publish via the existing
publication services when opted in. See `design.md`.

## New Dependencies
None new. Reuses OpenRegister objects, the secret-ballot/vote anonymity mechanism, the Analytics
leaf, the minutes/document generation path, and the publication stack — all already in decidesk.

## Impact
- **decidesk backend**: three new schemas in the register; a thin scoring service; wiring to reuse
  the anonymous-ballot collection and the publication path.
- **decidesk frontend**: an evaluation launch/respond flow, a results dashboard tab on the
  GovernanceBody detail (Analytics-leaf-rendered), and a report/publish action.
- **OpenRegister / Analytics / Docudesk**: consumed, not modified.

## Cross-Project Dependencies
Consumes the Analytics leaf (`governance-analytics-via-analytics-leaf`), the publication stack
(OpenCatalogi/ORI publication already in decidesk), and the minutes/document generation path. No
changes required in those projects.

## Risks

### Risk 1: Anonymity is weakened by small bodies
**Severity:** Medium — **Mitigation:** Inherit the secret-ballot guarantee (untraceable content) and
suppress per-dimension breakdowns below a minimum respondent threshold so a small board cannot
de-anonymise a response by inference; show only the aggregate when below threshold.

### Risk 2: Scope creep into individual-director appraisal
**Severity:** Medium — **Mitigation:** Explicitly board-level only; per-person appraisal is out of
scope and belongs to hrmq. The entity relates to the body, not to a single member's performance.

### Risk 3: Re-implementing survey/analytics infrastructure
**Severity:** Medium — **Mitigation:** Response collection reuses the vote/ballot anonymity path;
dashboards use the Analytics leaf; only the governance-specific *scoring* is app-owned (allowed by
REQ-AN-LEAF-002). No new survey engine, no new analytics renderer.

## Rollback Strategy
Additive and isolated: the evaluation schemas, scoring service, and UI surfaces are new. Remove the
schemas + service + tabs to restore the prior behaviour; no existing capability depends on it.

## Open Questions
- Should the default question-set template ship as seed data (a Dutch Corporate Governance Code /
  association-oriented set), or start empty and require the body to author one? (Resolve in design —
  leaning: ship one reusable default template as seed, editable per body.)
- Minimum-respondent threshold for per-dimension breakdown (e.g. 3 or 5)? (Resolve in design.)
