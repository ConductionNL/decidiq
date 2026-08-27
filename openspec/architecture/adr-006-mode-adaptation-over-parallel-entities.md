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

## Addendum (2026-08-19): Consultation family discriminator boundary

**Change:** `consultation-discriminator`

Decidiq carries three schemas whose names all contain "consultation":
`PublicConsultation` (already a discriminated supertype, `consultationType`
enum: `citizen-participation | market-consultation | tender | idea-box |
participatory-budget`), `MemberConsultation` (the internal, non-binding
achterbanraadpleging), and `ConsultationRequest` (the formal WOR art. 25/27
traject). Naming proximity plus this ADR's "one schema per concept" mandate
invited the question of whether `MemberConsultation` and/or
`ConsultationRequest` should fold into `PublicConsultation` as two more
`consultationType` values. This addendum records the measured evaluation and
its outcome, per the escape clause in "Forbidden" above ("This requires an ADR
amendment demonstrating the concept is genuinely distinct, not a
relabelling.").

### Pairwise field-name overlap

Exact property-key intersection between each pair's `properties` objects,
expressed as a share of each side's own field count:

| Pair | Shared field names | Share of smaller-numerator side |
|---|---|---|
| `PublicConsultation` (28) ∩ `MemberConsultation` (15) | `description`, `decision` (2) | 2/28 = 7% of PC · **2/15 = 13% of MC** |
| `PublicConsultation` (28) ∩ `ConsultationRequest` (20) | `governanceBody`, `relatedDecision` (2) | 2/28 = 7% of PC · **2/20 = 10% of CR** |
| `MemberConsultation` (15) ∩ `ConsultationRequest` (20) | `agendaItem`, `lifecycle` (2) | 2/15 = 13% of MC · 2/20 = 10% of CR |

Even the highest ratio (13%) is far below what would suggest "the same concept,
different labels" — the board-portal parallel entities this ADR retired shared
their *entire* field set 1:1 (a straight rename), not a 13% intersection.

### Qualitative signals

1. **Authorization block.** `PublicConsultation` is the only one of the three
   with a declared `authorization.read` block granting the `public` group
   anonymous access once `publicationDate` has passed (WOO/DIWOO publication).
   Neither `MemberConsultation` nor `ConsultationRequest` declares one —
   internal/staff-only by the OR RBAC default. Folding either into
   `PublicConsultation` would require either accepting the public-read cascade
   on every row unless per-value conditional composition is invented first (the
   OR authorization dialect has no `allOf`/`anyOf`/`oneOf` today), or inventing
   that capability — the least defensible part of a fold.
2. **`x-schema-org` type.** `Event` (`PublicConsultation`) vs. `AskAction`
   (`MemberConsultation`) vs. `Action` (`ConsultationRequest`) — three distinct
   domain shapes, not one type with cosmetic relabelling.
3. **Structural cross-reference.** `ConsultationRequest.constituencyConsultation`
   is a live, already-shipped optional reference *to* `MemberConsultation` (a
   WOR traject may point at an achterbanraadpleging as one of its steps). This
   is a composition relationship, like `Meeting` referencing `AgendaItem`, not
   two names for one thing — folding `MemberConsultation` into
   `PublicConsultation` would make `ConsultationRequest` reference "a
   `PublicConsultation` row with `consultationType=member-poll`" for a step
   whose entire point is that it is *not* public.
4. **Lifecycle shape.** `PublicConsultation.status` is a 12-value union across
   its 5 existing subtypes (`x-openregister-lifecycle`). `MemberConsultation`'s
   `lifecycle` is a 4-state internal poll (`draft → open → closed →
   processed`). `ConsultationRequest`'s `lifecycle` is a 9-state bilateral
   statutory procedure with a derived one-month suspension date
   (`suspensionTo`, WOR art. 25 lid 6) unique to it. Neither reduces to "one
   more branch of `PublicConsultation`'s existing union" without inventing new
   cross-cutting transition semantics the declarative lifecycle dialect does
   not have today.

### Outcome

**No fold. `PublicConsultation` remains the sole ADR-006-discriminated concept
for the public/market-consultation family — already correctly implemented, no
change to its shape.** `MemberConsultation` and `ConsultationRequest` are each
independently exempted under this ADR's escape clause as genuinely distinct
concepts. The evidence is symmetric: neither schema is meaningfully closer to
`PublicConsultation` than the other (13% vs. 10% field overlap is not a
material difference), and the strongest qualitative signal — the missing
public-authorization block — applies equally to both.

A future consultation variant that DOES share `PublicConsultation`'s
public-group authorization model and core "ask citizens, collect reactions,
publish results" shape is added as a new `consultationType` enum value on the
existing schema, per this ADR — never as a new parallel schema. A future
re-evaluation of this addendum's conclusion must re-measure field overlap and
the four qualitative signals above; a naming-similarity argument alone does
not reopen it.

See `openspec/changes/consultation-discriminator/design.md` for the full
worked evaluation, including the alternatives considered.
