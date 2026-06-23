# Design: Consultations & engagement hub

## Context

Engagement features today: `PublicConsultation` (+ `ConsultationReaction`), `ParticipatoryBudget`
(+ `BudgetProposal`), `CitizenPanel`, `Deliberation`, surfaced via 4 top-level menu items and a
standalone moderation queue. The decision domain already solved an analogous "many shapes, one
supertype" problem with `Decision` + `decisionType` (Motions = a filtered Decisions view). This
design applies the same pattern to consultations.

## Decisions

### D1 — Consultation is a supertype keyed by `consultationType` (not N sibling schemas)
A single `PublicConsultation` schema with a stored `consultationType` enum, rather than separate
`Tender`/`MarketConsultation`/`IdeaBox` schemas. **Why:** the process is ~80% shared (publish →
collect → moderate → close → publish results); type-specific fields are few and additive; one schema
means one list + one detail surface filtered by type (proven Motions pattern), reuses moderation,
and avoids N×CRUD duplication. **Trade-off:** a sparse schema (tender-only fields null for idea
boxes). Accepted — mirrors `Decision`, and OpenRegister tolerates optional fields.

### D2 — IA via in-bar quick-filters, not nested menus
One *Consultations* menu leaf; types are quick-filter tabs in the action bar (the `#filters` slot
shipped with the Decisions/Motions toggle). **Why:** consistent with Decisions; avoids a 4-deep
menu; each type is a shareable filtered URL. Participatory budgets and the citizen action page are
sub-views, not top-level peers.

### D3 — Moderation lives on the object, queue is a view
Reaction moderation belongs on the consultation it concerns. Extract `ModerationQueuePage`'s logic
into a `ConsultationReactionsTab` (props: `consultationId?`) that renders the reaction list +
inline approve/reject. On the detail page it is scoped to that consultation; a hub-wide queue (open
question #4) can mount the same component with no `consultationId`. **Why:** context-in-place review;
single source of moderation UI; no behavioural change to the approve/reject endpoints.

### D4 — Participatory budgets stay a separate schema, surfaced in the hub
Budget voting (allocate amounts across proposals, tallying) is materially different from
reaction-collection, so `ParticipatoryBudget`/`BudgetProposal` keep their schema + phase flow and
appear as a hub tab, optionally linked to a parent Consultation. Revisit unifying later (open
question #3).

### D5 — Per-type lifecycle is declarative, stored, queryable
Each type's `status` stays an enum driven by `x-openregister-lifecycle`; no client-side derivation,
so states drive list filters + badges. Citizen-participation states unchanged; tender adds
`questions`/`evaluation`/`awarded`; market adds `report-published`; idea-box reuses open/closed +
`votingEnabled`.

## Type → field/lifecycle matrix

| type | extra fields | lifecycle |
|---|---|---|
| citizen-participation (default) | — | draft → open → closed → results-published |
| market-consultation | marketScope | draft → open → closed → report-published |
| tender | referenceNumber, estimatedValue, awardCriteria, questionDeadline, awardedTo | draft → published → questions → submission → evaluation → awarded |
| idea-box | votingEnabled, ideaCount | draft → open → closed (reactions carry voteCount) |

## Risks
- **Scope creep into procurement** (tenders): gated by open question #2 — if out of scope, the
  `tender` type and its fields drop cleanly (additive-only).
- **Migration**: existing `PublicConsultation` rows have no `consultationType` → default
  `citizen-participation` at read time + a one-time seed/backfill; no data loss.
- **Hydra gates**: schema-declarative (ADR-031), no imperative dispatch; reaction tab reuses
  existing endpoints (no new IDOR surface) — moderation endpoints already guard staff role.
