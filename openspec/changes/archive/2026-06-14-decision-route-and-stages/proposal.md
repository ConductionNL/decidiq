# Proposal: Decision route and stages (the ambtelijk → politiek bridge)

## Summary

Model how a single `Decision` travels across **multiple decision-makers in sequence**. Today a Decision has one lifecycle and is implicitly handled by one body; there is no first-class concept of a decision *routed* committee → council → board, each step owned by a different decision-maker with its own status and outcome. This change introduces a **DecisionStage** (route-step) entity: a Decision now has an ordered **route** = a sequence of DecisionStage objects, each assigned to a decision-maker that is either a `Person` (an individual) or a `GovernanceBody` (a group: committee/council/board/MT). This is THE differentiator for Cycle 2 — it makes the organisational-to-political (ambtelijk → politiek) hand-off explicit and queryable instead of folklore.

## Motivation

Every real governance decision of any weight is *staged*. A municipal budget is prepared by the college, advised by a raadscommissie, and then decided by the gemeenteraad. A corporate investment is proposed by the MT (management team), decided by the RvB (executive board), and ratified by the RvC (supervisory board). The decisive moment, the advisory moment, and the preparatory moment are owned by **different decision-makers** and carry **different outcomes** — yet decidesk currently flattens all of this into one `lifecycle` field on one Decision and one (implicit) body. The consequence: the register cannot answer "which stage is this decision in, who owns it now, and what did the previous decision-maker advise?" — the single most important operational question for a secretary tracking a decision through its journey. Incumbents (Notubiz, iBabs, GO/Decos) all model the *behandeltraject* / route; ADR-005's target diagram already sketches a DECISION ROUTE / STAGE layer; ADR-006 (mode adaptation) requires that the same Decision entity serve all five domains, which is impossible without a route that crosses ambtelijk and politiek bodies. Now is the moment because Cycle 1 (`unify-decision-supertype`) has just made Decision the universal supertype, giving us one entity to attach a route to, and the next change (`decision-methods`, C5) needs a stage to hang HOW each step is resolved.

## Affected Projects

- [x] Project: `decidesk` — new `DecisionStage` schema in the register; declarative `route`/`currentStage`/route-progress on Decision; seeds for multi-stage ambtelijk+politiek routes. Register-only (config) change.

## Scope

### In Scope

- A new `DecisionStage` OpenRegister schema: `sequence`/order, `stageType` (advisory/decisive/ratifying/preparatory), `status` (pending/active/decided/skipped), `outcome` (for/against/adopted/rejected/advised/deferred), `decidedAt`, a `method` enum placeholder (manual/vote/sign/chair-register), and relations to its parent Decision and to its decision-maker.
- A **polymorphic decision-maker** on each stage: two optional typed relations (`assignedPerson` → Person, `assignedBody` → GovernanceBody) plus a `decisionMakerType` discriminator.
- The Decision↔DecisionStage relation (`route`, one Decision → many stages) and a **declarative** `currentStage` derivation (first non-decided/non-skipped stage by sequence) plus route-progress aggregations (stage counts) per ADR-031.
- Seed data: concrete multi-stage routes across ambtelijk + politiek bodies — a municipal decision routed college → raadscommissie → gemeenteraad, and a corporate one routed MT → RvB → RvC.

### Out of Scope

- **The route timeline / stage UI** (a visual route component, drag-reorder, per-stage action buttons) — deferred to Cycle 2's C6 UI change. C4 is config-only.
- **How each stage is resolved** (vote tally / signature / chair-registers) beyond a `method` enum placeholder — owned by `decision-methods` (C5).
- **Reusable route templates** as a separately-managed entity — C4 models *concrete* per-Decision stages; instantiation from the existing `processTemplate` (process-configuration capability) is referenced but the template-authoring surface is unchanged.
- Decision-to-decision relations (supersedes/amends/repeals) — owned by the active `decision-relations` change; route/stages are orthogonal (see design.md).

## Approach

Add `DecisionStage` as a separate OpenRegister schema (flat object storage; stages need their own status/outcome/decision-maker relations and are listed/queried independently), related to Decision via a `route` relation. Each stage assigns a decision-maker through two optional typed relations + a discriminator. `currentStage` and route-progress are declarative (`x-openregister-calculations` / `x-openregister-aggregations`, ADR-031), not Service code. Seeds demonstrate the ambtelijk → politiek bridge in both a municipal and a corporate route. No PHP, no Vue, no migration of existing data — purely additive register configuration.

## New Dependencies

None.

## Impact

- **Schemas**: new `DecisionStage` schema; `Decision` gains a `route` relation (→ DecisionStage) and declarative `currentStage` + route-progress fields via a version bump. No existing fields change; no data loss.
- **Specs**: new `decision-route` capability spec; delta on `decision-management`.
- **APIs**: stages are read/written through OpenRegister's generic object API (`useObjectStore`), as with all decidesk entities — no new app endpoints.
- **Downstream**: `decision-methods` (C5) attaches per-stage resolution to `DecisionStage.method`; the C6 UI change renders the route.

## Cross-Project Dependencies

None. Self-contained within decidesk; OpenRegister provides the object/relation/calculation engine already in use.

## Risks

### Risk 1: Polymorphic decision-maker (Person OR GovernanceBody) modelled with two relations

**Severity:** Medium — **Mitigation:** OpenRegister relations are typed per target schema, so a single relation cannot point at two schemas. We use two optional typed relations (`assignedPerson`, `assignedBody`) guarded by a `decisionMakerType` discriminator and a declarative "exactly one assignee" validation note; this mirrors the established pattern and keeps relational queries (find all stages assigned to body X) first-class. Documented as a resolved design decision, not left implicit.

### Risk 2: `currentStage` derivation drift between declarative calc and any future imperative caller

**Severity:** Low — **Mitigation:** `currentStage` and route-progress are defined ONLY declaratively (ADR-031) in the register; no Service computes them. The C6 UI and C5 methods read the materialised values, never recompute, so there is a single source of truth.

### Risk 3: Route vs. process-template duplication

**Severity:** Low — **Mitigation:** C4 stages are concrete per-Decision objects; the existing `processTemplate` (process-configuration) remains the authoring surface for reusable workflows. The relationship is documented (a route MAY be instantiated from a template) but C4 adds no competing template entity.

## Rollback Strategy

Revert the register version bump (remove the `DecisionStage` schema, the Decision `route` relation, and the new declarative fields/seeds) and re-run register sync. Because the change is additive and seed-only, no production decision data is lost; existing single-body decisions keep working exactly as before (an empty route is valid).

## Open Questions

See `DEFERRED_QUESTIONS` at the end of the design — the genuinely-open items are (1) the polymorphic-decision-maker shape (two relations + discriminator vs. a single typed relation if OR later supports schema unions) and (2) how tightly a route should be coupled to a `processTemplate` for auto-instantiation. Both are recorded with a chosen default so C4 is unblocked.
