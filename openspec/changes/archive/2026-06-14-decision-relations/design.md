# Design: Decision relations

## Context

The decision-management spec models a single decision's lifecycle thoroughly (Symfony Workflow state machine, immutable audit trail) but treats every decision as an island. Real governance corpora are graphs: budgets supersede budgets, policies amend policies, repeal decisions kill ordinances. Akoma Ntoso — already a cited standard on the spec — models this as active/passive modifications; OpenRaadsinformatie relates `Besluit` records; OpenRegister natively supports typed object relations and relation-filtered queries (the relation-tab-ui capability is built on exactly that machinery).

This change is deliberately small: relation properties on the existing schema, one validation/derivation service, and three UI affordances. No new capability, no new lifecycle states.

## Goals / Non-goals

- **Goal:** typed, audited decision-to-decision relations with correct in-force semantics.
- **Goal:** the register answers "is this still in force?" everywhere a decision is shown (detail, list, published payload).
- **Non-goal:** consolidation rendering (merged amended text) — follow-up; this change records THAT a decision is amended and by what, not the merged result.
- **Non-goal:** extending the lifecycle state machine — `superseded`/`repealed` are derived presentation states, not workflow states.
- **Non-goal:** relations to objects outside the decidesk register, or auto-inferring relations from text.

## Decisions

### D1 — Five relation types, three of them effect-bearing

`supersedes`, `amends`, `repeals` are **effect-bearing** (they change how the target must be read); `implements` and `refersTo` are **informational**. The distinction drives every rule below: integrity validation, status preconditions, and effective-status derivation apply only to effect-bearing relations; informational links are cheap and unrestricted (beyond self-reference).

Mapping: `supersedes`/`repeals` → Akoma Ntoso modification (passive on the target), schema.org `replacer`/`replacee` for supersedes; `amends` → Akoma Ntoso `amendment` modification; all exported on published payloads in ORI-compatible relation fields.

### D2 — Stored once, derived inverse

Relations live on the **source** decision as OR object-relation properties (arrays of decision references). The inverse ("superseded by") is a derived view via OR relation queries — the same `getRelations`/`uses`-`used-by` machinery the rest of the fleet uses. Storing both directions invites drift; OR already answers reverse lookups.

### D3 — Effective status is derived, never a workflow state

A decision's lifecycle status (`draft`…`enacted`/`archived`) is the statutory record and stays untouched — repealing a decision does not rewrite history. The **effective status** is computed: `repealed` if an enacted decision `repeals` it, else `superseded` if an enacted decision `supersedes` it, else its lifecycle status. Computation happens server-side in `DecisionRelationService` (single source of truth, also reused by the publication payload builder) and is surfaced to the UI as a derived field. An ADR-031 rule on the target's derived-status update notifies the body.

Why not workflow states: the Symfony state machine guards transitions of ONE object by ONE actor; supersession is a property of the graph that activates when the OTHER decision becomes enacted — modelling it as a transition would require synthetic transitions and would corrupt the audit semantics ("who transitioned it?" — nobody did).

### D4 — Integrity rules

- **Status precondition**: effect-bearing relations may be created at any source status (a draft proposal already declares what it intends to replace) but exert effect only while the source is `decided`/`enacted`. Derivation re-evaluates on source status change — a draft that supersedes nothing yet starts superseding on enactment, and a withdrawn/archived repeal stops repealing per body policy.
- **No self-reference** for any type; **no cycles** in the effect-bearing subgraph (A supersedes B supersedes A) — validated server-side at relation-write time via a bounded graph walk.
- **Authority**: creating/removing effect-bearing relations requires the same governance-body authority as decision transitions (OR RBAC); informational relations require decision write access.
- **Audit**: every relation add/remove is recorded in the immutable audit trail of BOTH decisions (source: "now supersedes X"; target: "superseded by Y declared").

### D5 — UI: peer-relation tab, banner, filter

- The relation-tab-ui capability currently specs **parent-owned child CRUD** tabs. Peer relations are a different pattern: pick an EXISTING decision (NcSelect search against the OR object API), choose a relation type, no child creation, navigation both ways. This lands as an ADDED requirement on relation-tab-ui so other peer-typed relations (future: body mergers, meeting series) reuse the pattern.
- Decision detail: when effective status ≠ lifecycle status, a prominent banner ("Repealed by [Decision X] on [date]") with chain navigation; the relations tab shows outgoing and derived incoming relations grouped by type.
- Decision list: in-force filter (`in force` / `superseded` / `repealed`) implemented over the derived status; default list view unchanged.

### D6 — Publication interplay

Published decision payloads (public-publication capability) include the relation metadata and the effective status at publish time. Payloads stay immutable: when a published decision is later superseded, staff are prompted (same `prompt-on-transition` channel) to rectify the publication so the public register reflects the change. No automatic re-publication.

## Risks

- **Cycle-check cost on deep chains.** Bounded walk (effect-bearing relations only, depth-capped with a clear error) — chains in practice are short; the cap is a guard, not a feature limit.
- **Derived-status staleness** when the source decision's status changes. Mitigation: derivation is computed at read/serve time from live relations (no cached column to invalidate); the notification rule is the only consumer of a stored update, driven by the service.
- **Schema bump on a core schema.** `Decision` gains optional array properties only — backwards compatible; declarative version bump per house pattern.
- **UI ambiguity between lifecycle and effective status.** Mitigation: the banner pattern and explicit "in force" wording; lifecycle badge always remains visible (statutory record).
