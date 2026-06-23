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

`All · Citizen participation · Market consultations · Tenders · Idea box · Budgets`

- *Participatory budgets* becomes a type within the hub (its own filtered view, retaining the
  ParticipatoryBudget/BudgetProposal model and phase flow).
- The standalone *Participation* action page is reachable as the citizen-facing surface of an open
  consultation (kept, re-pointed), not a top-level peer.
- The standalone *Moderation queue* top-level leaf is **retired** (see §3) — a cross-consultation
  moderation view can return later as a filtered hub view if needed.

### 2. Data model: `consultationType` on the Consultation supertype

Extend `PublicConsultation` with a stored, queryable `consultationType` enum:
`citizen-participation` (default) | `market-consultation` | `tender` | `idea-box`. The shared
fields (title, description, status, moderationPolicy, submissionDeadline, reactions, resultsSummary)
stay; type-specific fields are additive and optional so each type reuses the same list/detail
surfaces (filtered by `consultationType`, exactly like Motions = `decisionType: motion`):

- **tender**: `referenceNumber`, `estimatedValue`, `awardCriteria`, `questionDeadline`, `awardedTo`
  (+ phases: `published → questions → submission → evaluation → awarded`).
- **market-consultation**: `marketScope`, lighter flow (`open → closed → report-published`).
- **idea-box**: `votingEnabled`, `ideaCount` (ideas are `ConsultationReaction`s that can be voted on).
- **citizen-participation**: unchanged (the current PublicConsultation flow).

`ParticipatoryBudget` + `BudgetProposal` are surfaced *in* the hub but keep their own schema/flow
(budget voting is materially different); they are linked to a parent Consultation where one exists.

### 3. Moderation folded into the consultation detail

Add a **Reactions** tab to the consultation detail (`ConsultationDetail`) that lists this
consultation's `ConsultationReaction`s with **inline approve/reject** (reusing the existing
`ReactionApproveModal` / `ReactionRejectModal` + `participationApi` approve/reject endpoints). The
`ModerationQueuePage` logic is refactored into a reusable `ConsultationReactionsTab` component so the
same moderation UI works (a) per-consultation in the detail and (b) optionally as a hub-wide queue.

### 4. Per-type lifecycle

Keep `status` declarative per type via `x-openregister-lifecycle`, with the citizen-participation
states unchanged and additional states for tender/market/idea-box as above. No client-side
derivation — all states are stored/queryable so they drive filters and badges.

## Impact

- **Schemas**: `PublicConsultation` gains `consultationType` + the additive type fields; lifecycle
  extended. `ConsultationReaction` gains an optional `voteCount` for idea-box voting. No breaking
  removals (existing consultations default to `citizen-participation`).
- **Manifest**: four menu entries → one `Consultations` hub; `Consultations` index gains
  `quickFilters` by type; `ConsultationDetail` gains the Reactions/moderation tab; `BudgetRounds`
  becomes a hub sub-view; `ModerationQueue` top-level leaf removed.
- **Components**: new `ConsultationReactionsTab` (extracted from `ModerationQueuePage`); minor
  `ParticipationPage` re-point. No lib changes required (uses shipped `#filters` + detail tabs).
- **Out of scope (this change)**: a cross-consultation moderation dashboard; tender e-signing /
  award workflows beyond status; SES/peppol tender publication. Flagged as follow-ups.

## Open questions (for review before implementation)

1. Top-level name: **"Consultations"** vs **"Engagement"** vs **"Participation"**?
2. Are **tenders/procurement** in decidesk's scope, or do they belong in a procurement app
   (pipelinq/openzaak)? If out of scope here, drop the `tender` type from this change.
3. Should **participatory budgets** become a `consultationType` too (unify the model), or stay a
   linked-but-separate schema (this proposal keeps it separate)?
4. Keep a **hub-wide moderation queue** view in addition to per-consultation moderation, or fully
   retire the standalone queue?
