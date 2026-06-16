# ADR-006: Mode Adaptation Over Parallel Entities

**Status:** accepted
**Date:** 2026-06-14
**Amends:** ADR-004 (Information Architecture) — makes Rule 1 binding at the
data-model layer, not just the nav layer.

## Context

ADR-004 Rule 1 says: *"The same six top-level items serve all four target
audiences. Only the labels shift via tenant mode — the navigation structure
itself never branches per persona."* This was written as a **navigation** rule.

The implementation honoured it for the nav shell but violated its spirit at the
data layer. When corporate-board support was added (the "board portal", Phase 8),
it shipped a **complete parallel entity set** instead of adapting the universal
entities:

| Universal entity | Parallel corporate duplicate |
|---|---|
| `meeting` | `board-meeting` |
| `participant` / Person | `board-member` |
| `decision` / `motion` | `resolution` |
| `vote` / `voting-round` | `board-vote` |
| `minutes` | `board-minutes` |
| `governance-body` | `board` |
| OR built-in `auditTrail` | `board-audit-log-entry` |
| (generic attachments) | `board-material` |

…each with its own Vue views (`BoardList`, `BoardMeetingList`,
`ResolutionList`, …) and its own nav items injected via
`src/manifest.d/board-portal.json`. The result is the exact failure ADR-004 set
out to prevent: corporate vocabulary and parliamentary vocabulary sitting
side-by-side in one app, backed by duplicated, divergent data models. A vote is
a vote; storing `vote` and `board-vote` as different schemas guarantees they
drift.

## Decision

**Domain differences are expressed by mode adaptation, never by parallel
entities.** There is exactly one schema per concept. Audience-specific behaviour
is achieved through three mechanisms, in this order of preference:

1. **Label adaptation** (`organisatie-modus`: `gov` / `corp` / `assoc` / `ops` /
   `citizen`) — the same entity, different displayed noun. A `governance-body`
   shows as "Raad"/"Commissie" (gov), "Board"/"Supervisory Board" (corp),
   "Bestuur"/"ALV" (assoc), "MT"/"Stuurgroep" (ops). This is the ADR-004 Rule 1
   mechanism, now mandated at the data layer too.

2. **Type discriminators** — where a real subtype distinction exists, use an
   enum field on the universal entity (e.g. `GovernanceBody.bodyType`,
   `Decision.decisionType` per ADR-005), not a new schema.

3. **Progressive disclosure** (ADR-004 Rule 2) — domain-specific fields render
   conditionally on mode/type, on the same detail page.

### Forbidden

- A new schema that duplicates an existing concept "for a different audience"
  (the board-* pattern). This requires an ADR amendment demonstrating the
  concept is genuinely distinct, not a relabelling.
- A `manifest.d/*.json` fragment that adds parallel nav items for a relabelled
  concept.

### Corporate concepts re-expressed as adaptations

| Was (parallel schema) | Becomes |
|---|---|
| `board` | `governance-body` with `bodyType=supervisory-board` / `executive-board`, mode=corp labels |
| `board-meeting` | `meeting` (CalDAV VEVENT per ADR-002), mode=corp labels |
| `board-member` | Person + Membership (Popolo, ADR-001), mode=corp labels |
| `board-vote` | `vote` / `voting-round` |
| `board-minutes` | `minutes` |
| `resolution` | `decision` with `decisionType=resolution` (ADR-005) |
| `board-material` | DigitalDocument attachment |
| `board-audit-log-entry` | OR built-in `auditTrail` |
| eIDAS signing (board-specific) | a **decision method** (`signature`) available to any decision, Cycle 2 |

### eIDAS signing is a decision method, not a corporate feature

The board portal's signing/attestation flow is not corporate-only — it is one
**way a decision is reached** (alongside vote, secret-vote, chair-registers,
advice). It is promoted to a pluggable decision *method* on the unified Decision
(ADR-005, Cycle 2 `decision-methods`), available regardless of mode.

## Consequences

- **The board-portal overlay is retired** (Cycle 1, change `retire-board-portal`):
  delete the 7 `board-*` / `resolution` schemas, `board-portal.json`, and the
  six `Board*`/`Resolution*` Vue views. Corporate demo data re-seeds onto the
  unified entities.
- **One schema per concept** is now an invariant the spec-review rubric enforces.
  Future audiences (e.g. a new sector mode) extend the label table and possibly
  a type enum — never the schema set.
- **The Popolo decision-maker model becomes the single person/org model**
  (ADR-001), with `board-member` folded into Person + Membership
  (Cycle 1, change `popolo-decision-makers`).
- **Drift between audiences becomes impossible** at the data layer — a vote, a
  meeting, a minute behaves identically everywhere; only the words change.
- **ADR-004's six-item nav is realized** (Cycle 1, change `ia-six-item-nav`):
  the parallel "Boards / Board meetings / Resolutions" items disappear, replaced
  by mode-aware labels on the universal six.
