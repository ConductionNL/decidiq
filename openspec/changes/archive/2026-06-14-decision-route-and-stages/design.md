# Design: Decision route and stages

## Context

ADR-005 made `Decision` the universal supertype for everything that gets decided. Its target diagram already sketches a **DECISION ROUTE / STAGE** layer as future work. ADR-006 requires the *same* Decision entity to serve all five governance domains by adapting mode rather than forking entities — which only works if a Decision can be routed across the different decision-makers each domain uses. ADR-001 (Popolo) gives us the decision-makers: a `Person` (individual) and a `GovernanceBody` (Popolo `Organization` — committee, council, board, MT), both already shipped from Cycle 1. ADR-031 mandates declarative-first behaviour (`x-openregister-*` in the register, not Service classes).

This change models the **route**: the ordered path a Decision travels across decision-makers, where each step is a first-class `DecisionStage` with its own status, outcome, and owner. The key product move is the **ambtelijk → politiek bridge** — a single decision whose early stages are owned by an organisational body (MT, college, directieteam) and whose later stages are owned by a political/governance body (gemeenteraad, RvB, RvC). C5 (`decision-methods`) attaches HOW each stage is resolved; C6 builds the route UI; C4 (this change) models the stage and a `method` enum placeholder only.

## The DecisionStage model

A `DecisionStage` is a flat OpenRegister object representing one step in a Decision's route.

| Field | Type | Notes |
|---|---|---|
| `sequence` | integer | 1-based order of this stage in the route |
| `stageType` | enum | `preparatory` \| `advisory` \| `decisive` \| `ratifying` |
| `status` | enum | `pending` \| `active` \| `decided` \| `skipped` (declarative lifecycle field) |
| `outcome` | enum (nullable) | `for` \| `against` \| `adopted` \| `rejected` \| `advised` \| `deferred` — set when `status=decided` |
| `decidedAt` | date-time (nullable) | when this stage reached `decided` |
| `method` | enum | `manual` \| `vote` \| `sign` \| `chair-register` — **placeholder** for C5 (decision-methods); defaults `manual` |
| `decisionMakerType` | enum | `person` \| `body` — discriminator for the polymorphic assignee |
| `label` | string | human label for the stage ("Advies raadscommissie") |
| `note` | string (nullable) | free text recorded by the owner at decision time |

**Relations** (`x-openregister-relations`):

| Relation | Target | Cardinality | Purpose |
|---|---|---|---|
| `decision` | Decision | many-to-one | the parent Decision this stage belongs to |
| `assignedPerson` | Person | many-to-one (optional) | individual decision-maker (when `decisionMakerType=person`) |
| `assignedBody` | GovernanceBody | many-to-one (optional) | group decision-maker (when `decisionMakerType=body`) |

On `Decision`, the inverse: a `route` relation (one Decision → many DecisionStage), plus declarative `currentStage` + route-progress fields (below).

### Lifecycle (declarative, ADR-031)

`DecisionStage.status` carries an `x-openregister-lifecycle`: initial `pending`; `pending → active → decided`, with `pending → skipped` and `active → skipped` for stages bypassed by an upstream outcome; `decided` and `skipped` are terminal. This mirrors the Decision lifecycle pattern already in the register (guarded transition map, no Service state machine).

## ASCII diagram — a decision routed MT → council → board

Municipal example — "Vaststelling Programmabegroting 2027" (decisionType=meeting-outcome):

```
  Decision: "Vaststelling Programmabegroting 2027"   lifecycle: deliberating
  route ──────────────────────────────────────────────────────────────────────
   │
   ▼  seq 1  stageType=preparatory   status=decided   outcome=adopted
  ┌──────────────────────────────────────────────┐
  │ Stage 1 · College van B&W (ambtelijk)         │  assignedBody → GovernanceBody
  │   "Voorbereiding & vaststelling concept"       │  decisionMakerType=body
  │   method=manual   decidedAt=2026-03-02         │
  └──────────────────────────────────────────────┘
   │
   ▼  seq 2  stageType=advisory     status=decided   outcome=advised
  ┌──────────────────────────────────────────────┐
  │ Stage 2 · Auditcommissie (raadscommissie)      │  assignedBody → GovernanceBody
  │   "Advies aan de raad"                         │  decisionMakerType=body
  │   method=manual   decidedAt=2026-03-20         │
  └──────────────────────────────────────────────┘
   │
   ▼  seq 3  stageType=decisive     status=active    outcome=null     ◀── currentStage
  ┌──────────────────────────────────────────────┐
  │ Stage 3 · Gemeenteraad (politiek)              │  assignedBody → GovernanceBody
  │   "Besluit"                                    │  decisionMakerType=body
  │   method=vote (resolved in C5)                 │
  └──────────────────────────────────────────────┘

  currentStage  = first stage whose status ∉ {decided, skipped} by sequence  → Stage 3
  decision overall advances as each stage decides; final decisive stage decided ⇒ decision decided
```

The **ambtelijk → politiek bridge** is the transition from Stage 1/2 (organisational bodies: college, commissie) to Stage 3 (the political body: gemeenteraad). The corporate mirror routes **MT → RvB → RvC** (operational → executive → supervisory).

## Design decisions

### D1 — DecisionStage is a SEPARATE OR schema (not an embedded array on Decision)

**Decision:** model each stage as its own `DecisionStage` object related to Decision via `route`, rather than an embedded JSON array property on Decision.

**Rationale:** OpenRegister stores flat objects and resolves cross-object relations via the relation engine; stages need (a) their own lifecycle/`status`, (b) their own *typed relations* to a decision-maker (Person OR GovernanceBody — relations cannot be expressed inside an embedded sub-array), and (c) to be **listed and queried independently** ("all stages currently assigned to the gemeenteraad", "all advisory stages awaiting a decision"). An embedded array would bury all of that in one Decision blob, defeating relational query, per-stage notifications, and per-stage RBAC. The cost (a second schema + a relation hop) is exactly the cost OR is built to absorb; the C6 UI reads the route via the relation just like Meeting → AgendaItem already does. **Chosen: separate schema.**

### D2 — Polymorphic decision-maker via two optional relations + a discriminator

**Decision:** a stage's assignee is a `Person` OR a `GovernanceBody`, modelled as two optional typed relations (`assignedPerson`, `assignedBody`) plus a `decisionMakerType` enum discriminator (`person`/`body`); a declarative validation note requires exactly one assignee consistent with the discriminator.

**Rationale:** OpenRegister relations are typed *per target schema* — a single relation property cannot point at two different schemas, so a true union relation is not expressible today. Two optional typed relations keep both directions of query first-class (find all stages for body X; find all stages for person Y), and the discriminator makes the active assignee unambiguous for the UI without inspecting which relation is populated. This mirrors how the register already discriminates polymorphic content (e.g. Decision's `decisionType`). The alternative — one "assignee" relation to a shared supertype — has no supertype to point at (Popolo Person and Organization are distinct classes) and would lose typed querying. **Chosen: two optional relations + discriminator.**

### D3 — Concrete per-Decision stages, optionally instantiated from a processTemplate

**Decision:** C4 models **concrete** DecisionStage objects owned by a specific Decision. Reusable route definitions are NOT a new entity in C4; where an organisation wants a standard route, it is instantiated from the existing `processTemplate` (the process-configuration capability, `processTemplate` objects seeded per domain) — C4 documents the link but does not build a template-authoring surface.

**Rationale:** the route a *particular* budget actually took is concrete history (who decided, when, what outcome) and must be stored as real objects, not a pointer to a mutable template. The process-configuration capability already owns reusable workflow definitions (`processTemplate`, five seeded built-ins, admin-managed); adding a second template entity for routes would duplicate it and re-open the route/template coupling C4 deliberately keeps loose. A future change can add "instantiate route from template" as a thin generator that materialises concrete stages — that generator reads `processTemplate`, it does not replace concrete stages. **Chosen: concrete stages; template-instantiation referenced, not built.**

### D4 — currentStage and route-progress are DECLARATIVE (ADR-031)

**Decision:** `currentStage` and the route-progress counters live in `x-openregister-calculations` / `x-openregister-aggregations` on Decision; no Service computes them.

- `currentStage` (calculation) = the first stage whose `status` ∉ {`decided`,`skipped`} ordered by `sequence` (derived pointer; null when all stages decided/skipped ⇒ route complete).
- `stageCount`, `decidedStageCount`, `skippedStageCount` (aggregations) = counts over the related DecisionStage objects, scoped `decision = @self.id`.
- `routeComplete` (calculation) = `decidedStageCount + skippedStageCount >= stageCount AND stageCount > 0`.

**Rationale:** ADR-031 prefers declarative behaviour in the register so a single source of truth drives every consumer (UI, notifications, C5 methods) and no two callers can drift. The existing Meeting schema already computes `quorumMet` / `actionItemCompletionRate` this way; route progress is the same shape (counts + a boolean/pointer over related objects). The decision's *overall* lifecycle is NOT auto-advanced by a calculation (that stays the existing guarded transition map, possibly nudged by C5's method resolution) — only the *derived view* (currentStage, progress) is declarative here.

## Seed data

Two concrete multi-stage routes are seeded (all referencing already-seeded GovernanceBody objects, plus a new decision per route):

1. **Municipal — `besluit-begroting-2027` (decisionType=meeting-outcome), route college → raadscommissie → gemeenteraad:**
   - Stage 1 `preparatory` · assignedBody → `directieteam-gemeente-utrecht` (stands in for college, ambtelijk) · status `decided` · outcome `adopted`
   - Stage 2 `advisory` · assignedBody → `auditcommissie-provincie-nh` (raadscommissie) · status `decided` · outcome `advised`
   - Stage 3 `decisive` · assignedBody → `gemeenteraad-amsterdam` (politiek) · status `active` · outcome null ← currentStage · method `vote`
2. **Corporate — `besluit-investering-acme` (decisionType=management-point), route MT → RvB → RvC:**
   - Stage 1 `preparatory` · assignedBody → an operational MT body · status `decided` · outcome `adopted` · method `manual`
   - Stage 2 `decisive` · assignedBody → an executive board body · status `decided` · outcome `adopted` · method `vote`
   - Stage 3 `ratifying` · assignedBody → `raad-van-commissarissen-acme-bv` (RvC, supervisory) · status `active` · outcome null ← currentStage · method `sign`

(One stage in the municipal route MAY additionally demonstrate an individual assignee — `assignedPerson` with `decisionMakerType=person` — e.g. a chair who registers the outcome, to exercise the polymorphic relation.) Existing seeded GovernanceBody slugs are reused; only the two new Decisions and their stages are added. The seeds prove the ambtelijk → politiek bridge in both the municipal and corporate domains.

## Declarative vs. imperative (ADR-031)

| Concern | Declarative (register) | Imperative (Service) |
|---|---|---|
| Stage status lifecycle (`pending→active→decided/skipped`) | ✅ `x-openregister-lifecycle` on DecisionStage | — |
| `currentStage` pointer | ✅ `x-openregister-calculations` on Decision | — |
| `stageCount` / `decidedStageCount` / `skippedStageCount` | ✅ `x-openregister-aggregations` on Decision | — |
| `routeComplete` | ✅ `x-openregister-calculations` on Decision | — |
| "exactly one assignee per stage" integrity | ✅ schema constraint / validation note (discriminator + optional relations) | — |
| Auto-advancing the **Decision's own lifecycle** when the decisive stage decides | — | deferred to C5 (decision-methods) — a method resolution MAY nudge the Decision lifecycle; out of scope for C4 |
| Rendering the route timeline | — | deferred to C6 (UI) |

C4 introduces **zero** new Service/PHP code. Everything is register configuration + declarative behaviour. This keeps the change `kind: config` per ADR-032.

## Relationship to decision-relations (C6 subsumes)

The active `decision-relations` change models relations **between** decisions (supersedes / amends / repeals / implements / refersTo) — the *in-force* question across the register. This change models the route **within one decision's journey** — the *who-decides-next* question. They are orthogonal and complementary: a decision can both have a route (its stages) and supersede another decision (a peer relation). C4 does not touch `decision-relations`; C6 later harmonises the two into a unified decision graph view.

## DEFERRED_QUESTIONS

- **D2 polymorphic-decision-maker shape** — chosen: two optional typed relations (`assignedPerson`/`assignedBody`) + `decisionMakerType` discriminator, because OR relations are typed per target schema. OPEN if OpenRegister later supports a relation to a schema-union or a shared Popolo "Agent" supertype, at which point a single typed relation would be cleaner; revisit then.
- **D3 route ↔ processTemplate coupling** — chosen: concrete per-Decision stages now, with template-instantiation only *referenced* (no generator built in C4). OPEN: whether a future change adds an "instantiate route from processTemplate" generator and, if so, whether the generated route stores a back-pointer to the source template version for drift detection. Recorded for C5/C6 to decide.
