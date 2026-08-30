# Proposal: Decision detail — the full picture (route timeline + relations)

## Summary

Make the Decision detail page show the **full picture** of a decision's journey: WHERE it is in its route (the ambtelijk → politiek timeline built in C4/C5), and HOW it relates to other decisions (supersedes / amends / repeals chains, with derived in-force status). This is the **C6 payoff** of Cycle 2 — the route built by `decision-route-and-stages` (C4) and resolved by `decision-methods` (C5) finally becomes visible to a secretary as a timeline, alongside a `RelatedDecisionsTab` and an effective-status banner. This change **SUBSUMES the active `decision-relations` change** (idea-only, never built): every requirement of `decision-relations` is implemented here so the relations story ships in one place with the route timeline that gives it context.

## Motivation

A decision register is only trustworthy if it answers the two questions every secretary, member, and auditor actually asks: **"where is this decision in its journey right now, and what's still to be done?"** and **"is this decision still in force, and if not, what replaced it?"** Today the Decision detail has lifecycle, action-item, voting, and audit tabs — but nothing shows the C4 *route* (the ordered DecisionStage path across decision-makers), and nothing models relations BETWEEN decisions. So decidesk silently lies twice: a routed decision shows no journey, and a 2024 decision repealed in 2026 still renders `enacted` with nothing pointing at its repeal.

Now is the moment: C4 shipped the `DecisionStage`/`route` model and declarative `currentStage`/route-progress fields; C5 shipped the per-stage `method`. The route data exists and is queryable — it just isn't surfaced. And `decision-relations` (FEATURE-REEVALUATION EXPECTED-GAP #4, ADR-005's "effective status owned by decision-relations") has sat as an idea-only change since 2026-06-11. Folding both into the detail page lets the in-force banner sit next to the route timeline, where "superseded by Budget 2027" and "currently at stage 3 / gemeenteraad" tell one coherent story.

## Affected Projects

- [x] Project: `decidesk` — `RelatedDecisionsTab.vue` + `DecisionRouteTab.vue` (new sidebar tabs registered in `src/registry.js`, wired into `DecisionDetail.config.sidebarTabs`); five typed decision-to-decision relations + a declarative `effectiveStatus` calculation + an in-force list filter + a supersedes seed pair in `lib/Settings/decidesk_register.json`.

## Scope

### In Scope

- **Part A — Route timeline**: a `DecisionRouteTab` rendering the Decision's `route` (C4 DecisionStage objects) as an ordered timeline — per-stage sequence, label, decision-maker (assignedBody/assignedPerson name), stageType, method (C5), status, outcome, decidedAt — with `currentStage` highlighted and route-progress (`decidedStageCount`/`stageCount`) shown. Read via the `route` relation through the established relation-tab pattern.
- **Part B — Subsume `decision-relations`**: typed `supersedes`/`repeals`/`implements`/`refersTo` decision-to-decision relations (declarative `x-openregister-relations`); derived inverse views via OR relation queries (never stored twice); a derived `effectiveStatus` (superseded/repealed) layered OVER lifecycle (ADR-031, not a new workflow state); relation integrity rules (only decided/enacted decisions exert effect; reject self-reference + cycles in effect-bearing types; audit-trail entries); a `RelatedDecisionsTab` (peer-relation pattern); an effective-status banner; an in-force list filter.
- **Part C — Outstanding-work wiring**: ensure the route + relations tabs slot cleanly into `DecisionDetail.config.sidebarTabs`; "what's still to be done" (currentStage + open action items) is visible. Light, mostly wiring.
- Seed: a `supersedes` relation between two seeds (new `besluit-begroting-2027` supersedes existing `besluit-begroting-2026`) so the banner + relations tab are demonstrable out of the box.

### Out of Scope

- **Consolidation text rendering** (showing merged amended text for `amends` chains) — follow-up, as in the subsumed change.
- **Cross-register / external-decision relations** and **automatic relation inference** from text.
- **New route/stage modelling or new method semantics** — owned by C4/C5; C6 only renders the route and reads existing fields.
- **Extending the lifecycle state machine** — `superseded`/`repealed` are derived presentation states, never workflow states.

## Approach

Config-first per ADR-031/ADR-032. The Decision schema gains the four additional relation types + a declarative `effectiveStatus` calculation + a notification rule + the supersedes seed pair. Two new Vue tabs read existing relations via `useRelationStore`/`useObjectStore` (no new controllers — relation CRUD stays on the OR object API per ADR-022). The in-force filter is added to the Decisions index page in the manifest. See design.md for the `amends`-name-collision resolution, the declarative-vs-client-side effectiveStatus decision, and the in-force-filter mechanism.

## New Dependencies

None. Reuses `@conduction/nextcloud-vue` (CnNoteCard, CnStatusBadge, CnDeleteDialog), `@nextcloud/vue` (NcSelect, NcButton), the existing object/settings stores, and OpenRegister relations/calculations.

## Impact

- **Schema (`lib/Settings/decidesk_register.json`)**: Decision gains `supersedes`/`repeals`/`implements`/`refersTo` relations (the `amends` name is already taken — see design D1), a materialised `effectiveStatus` calculation, one ADR-031 notification rule, and one new seed + a relation on it. Additive, backwards compatible, declarative version bump.
- **Frontend (`src/`)**: `DecisionRouteTab.vue`, `RelatedDecisionsTab.vue`, registry registration, two new entries in `DecisionDetail.config.sidebarTabs`, one effective-status banner (in the route/overview surface), and the in-force filter on the Decisions index.
- **Backend (`lib/`)**: none required for the declarative path (effectiveStatus computed by OR calculation; integrity rejection of self-reference/cycles declared via the calculation/relation constraints). If a server-side integrity hook is needed it stays a validation seam, not a pass-through controller (ADR-022). See DEFERRED_QUESTIONS.
- **Standards**: Akoma Ntoso active/passive modifications, ORI `Besluit` relations, schema.org `replacer`/`replacee` — mapped in the relation descriptions.

## Cross-Project Dependencies

- Depends on `decision-route-and-stages` (C4) and `decision-methods` (C5) being applied/archived — the route timeline reads their `DecisionStage`/`route`/`currentStage`/`method` fields. Both are ✓ Complete in the change queue; C6 renders, it does not re-model.
- **Subsumes** the `decision-relations` change: its proposal/design/specs/tasks are fully implemented here. This proposal notes the subsumption; running `openspec archive decision-relations` (marking it superseded) is a separate step for the user — see design D6 and DEFERRED_QUESTIONS.

## Risks

### Risk 1: `amends` relation-name collision on the Decision schema
**Severity:** High — **Mitigation:** The Decision schema ALREADY has an `amends` relation (many-to-one, `decisionType=amendment` → parent motion, from `unify-decision-supertype`). The subsumed `decision-relations` change specced `amends` as a decision-modification array — a direct collision. Resolved in design D1: keep the existing `amends` semantics, and DO NOT add a second `amends`. The modification-relation set ships as `supersedes`/`repeals`/`implements`/`refersTo` (four, not five); `amends`-as-modification is folded onto the existing relation by widening its description, OR deferred. Recorded as a DEFERRED_QUESTION so the user confirms the naming before build.

### Risk 2: effectiveStatus declarative computation depth
**Severity:** Medium — **Mitigation:** effectiveStatus derives from INBOUND supersedes/repeals (a reverse relation query gated on the source being decided/enacted). OR calculations express forward/`firstRelated` lookups (proven by C4's `currentStage`); an inbound-relation-driven calc may not be expressible declaratively. Design D2 picks the mechanism (declarative calc if OR supports inverse-relation calcs; else the tab/banner computes it client-side from a relation query) and justifies it; flagged as a DEFERRED_QUESTION.

### Risk 3: in-force filter not yet a lib primitive
**Severity:** Low — **Mitigation:** No manifest currently uses `quickFilters`. Design D3 picks `filter` over the derived `effectiveStatus` (proven by the Motions page `filter`) vs `quickFilters` tabs (study CnIndexPage); recorded as a DEFERRED_QUESTION pending CnIndexPage capability confirmation.

## Rollback Strategy

Fully additive and config-first. Revert by: removing the two `sidebarTabs` entries + the in-force filter from `src/manifest.json`, deleting the two new `.vue` tabs + their `registry.js` imports, and reverting the Decision schema relations/calculation/notification/seed additions in `decidesk_register.json` (declarative version bump back). No data migration to undo — relations are OR object relations; effectiveStatus is derived (no stored lifecycle change to reverse).

## Open Questions

See DEFERRED_QUESTIONS at the end of the change for: the `amends` collision resolution, the effectiveStatus declarative-vs-client-side mechanism, the in-force-filter mechanism, and whether to archive `decision-relations` now.
