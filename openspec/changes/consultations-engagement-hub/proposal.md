---
kind: config+code
---

# Proposal: Consultations & engagement hub (unify participation surfaces)

## Problem

Decidesk's public-engagement features are scattered across **four** top-level menu entries —
*Citizen participation*, *Consultations*, *Participatory budgets*, and *Moderation queue* — even
though they are facets of one thing: **asking the outside world for input on a decision-making
process**. Consequences:

1. **Fragmented IA.** A user looking for "how do we gather input" has to know which of four menu
   items holds what. "Participation" is a bespoke action page; "Consultations" is a thin index;
   "Participatory budgets" is a separate index; "Moderation queue" is a disconnected staff page.
2. **Moderation is decoupled from its object.** Reaction moderation lives in a standalone
   `/moderation-queue` page that lists *all* pending `ConsultationReaction`s across every
   consultation. A staff member reviewing one consultation cannot moderate its reactions in
   context — there is no Reactions tab on the consultation detail page. (User report:
   "reaction moderation should be part of the consultation details page".)
3. **The model only covers one engagement shape.** `PublicConsultation` models a generic
   open-feedback round. But a municipality / organisation runs several *kinds* of consultation that
   share ~80% of the same process (publish → collect input → moderate → close → publish results) and
   differ only in vocabulary and a few phases: **citizen participation**, **market consultations**,
   **tenders / procurement**, **budget proposals & participatory budgets**, and **idea boxes**.
   Today only citizen participation + participatory budgets are modelled; market consultations,
   tenders, and idea boxes have no home, so teams improvise outside the app.

## Proposed Change

Treat **Consultation** as a supertype (mirroring the existing `Decision`/`decisionType` supertype
pattern — ADR-005) and fold every engagement surface under a single **Consultations** area.

### 1. IA: one "Consultations" hub, typed sub-views (no new lib)

Collapse the four menu entries into **one** top-level *Consultations* item. Inside, use the
in-bar quick-filter toggle (the `CnActionsBar` `#filters` pattern just shipped for Decisions/Motions)
to switch between consultation **types**:

`All · Citizen participation · Market consultations · Tenders · Idea box · Participatory budgets`

- *Participatory budgets* becomes a **consultation type** within the hub (Q3 resolved — unify the
  model): the `ParticipatoryBudget` / `BudgetProposal` schemas are folded into `consultationType:
  participatory-budget`, with budget-specific fields (below) carried as additive optional properties
  on the Consultation supertype rather than a separate schema.
- The standalone *Participation* action page is reachable as the citizen-facing surface of an open
  consultation (kept, re-pointed), not a top-level peer.
- The standalone *Moderation queue* top-level leaf is **kept** (Q4 resolved — keep the hub-wide
  queue) as the cross-consultation staff view, in addition to the per-consultation Reactions tab
  added in §3.

### 2. Data model: `consultationType` on the Consultation supertype

Extend `PublicConsultation` with a stored, queryable `consultationType` enum:
`citizen-participation` (default) | `market-consultation` | `tender` | `idea-box` |
`participatory-budget`. The shared fields (title, description, status, moderationPolicy,
submissionDeadline, reactions, resultsSummary) stay; type-specific fields are additive and optional
so each type reuses the same list/detail surfaces (filtered by `consultationType`, exactly like
Motions = `decisionType: motion`):

- **tender**: `referenceNumber`, `estimatedValue`, `awardCriteria`, `questionDeadline`, `awardedTo`
  (+ phases: `published → questions → submission → evaluation → awarded`). **Scope boundary (Q2
  resolved):** decidesk owns **publishing** the tender, **managing the responses**, and the **award
  decision** (who wins) — because awarding is a decision-making process. It does *not* own tender
  *authoring* (that is **procest**'s process) nor *responding* to a tender as a bidder (that is
  **pipelinq**, the CRM). A tender Consultation links back to the procest process that authored it
  and forward to the responses/bidders surfaced from pipelinq where present.
- **market-consultation**: `marketScope`, lighter flow (`open → closed → report-published`).
- **idea-box**: `votingEnabled`, `ideaCount` (ideas are `ConsultationReaction`s that can be voted on).
- **participatory-budget**: `budgetCeiling`, `currency`, `votingMethod`, `proposalDeadline`,
  `votingDeadline`; proposals are `ConsultationReaction`s of a budget-proposal shape (title, amount,
  voteCount) — replacing the retired `BudgetProposal` schema (existing budget rounds migrate to
  `consultationType: participatory-budget`, no hard delete).
- **citizen-participation**: unchanged (the current PublicConsultation flow).

### 3. Moderation folded into the consultation detail

Add a **Reactions** tab to the consultation detail (`ConsultationDetail`) that lists this
consultation's `ConsultationReaction`s with **inline approve/reject** (reusing the existing
`ReactionApproveModal` / `ReactionRejectModal` + `participationApi` approve/reject endpoints). The
`ModerationQueuePage` logic is refactored into a reusable `ConsultationReactionsTab` component so the
same moderation UI works in **both** places (Q4): (a) per-consultation in the detail tab and (b) the
retained hub-wide *Moderation queue* (all pending reactions across consultations).

### 4. Per-type lifecycle

Keep `status` declarative per type via `x-openregister-lifecycle`, with the citizen-participation
states unchanged and additional states for tender/market/idea-box as above. No client-side
derivation — all states are stored/queryable so they drive filters and badges.

## Impact

- **Schemas**: `PublicConsultation` gains `consultationType` + the additive type fields (incl. the
  budget fields); lifecycle extended. `ConsultationReaction` gains an optional `voteCount` (idea-box
  + budget voting) and an optional budget-proposal shape. `BudgetProposal` is retired into the
  Consultation/Reaction model; existing rows migrate to `consultationType: participatory-budget`
  (no breaking removals — existing consultations default to `citizen-participation`).
- **Manifest**: four menu entries → **two** (`Consultations` hub + retained `Moderation queue`);
  `Consultations` index gains `quickFilters` by type (incl. `participatory-budget`);
  `ConsultationDetail` gains the Reactions/moderation tab; `BudgetRounds` becomes the
  participatory-budget hub sub-view.
- **Components**: new `ConsultationReactionsTab` (extracted from `ModerationQueuePage`, reused in
  both the detail tab and the hub-wide queue); minor `ParticipationPage` re-point. No lib changes
  required (uses shipped `#filters` + detail tabs).
- **Out of scope (this change)**: tender *authoring* (procest) and tender *response/bidding*
  (pipelinq) — decidesk only publishes/manages/awards; tender e-signing / SES / peppol publication;
  budget-allocation accounting. Flagged as follow-ups / other-app concerns.

## Decisions (resolved with product owner, 2026-06-23)

1. **Top-level name → "Consultations"** (not "Engagement"/"Participation").
2. **Tenders are in decidesk's scope, but only the publish → manage-responses → award slice.**
   Tender *authoring* is procest's process; *responding* as a bidder is pipelinq (CRM). The award is
   a decision-making process, so it stays here.
3. **Participatory budget becomes a `consultationType`** — unify the model (retire the separate
   `BudgetProposal`/`ParticipatoryBudget` schema into the supertype).
4. **Keep the hub-wide moderation queue** in addition to the per-consultation Reactions tab.
