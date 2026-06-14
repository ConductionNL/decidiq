# Design: Decision detail — the full picture

## Context

C4 (`decision-route-and-stages`) made a `Decision` carry an ordered **route** of `DecisionStage` objects (each assigned to a `Person` or `GovernanceBody`, each with its own status/outcome) plus declarative `currentStage`, `stageCount`, `decidedStageCount`, `skippedStageCount`, and `routeComplete`. C5 (`decision-methods`) made each stage's `method` real (`vote`/`chair-register`/`signature`/`advice`/`manual`). That data exists in the register and is queryable — but the Decision detail page (`DecisionDetail.config.sidebarTabs` = overview / lifecycle / actionItems / voting / audit) never shows the route, and the register has no concept of relations BETWEEN decisions.

The active `decision-relations` change (idea-only since 2026-06-11; ADR-005 explicitly names "effective status owned by the decision-relations capability") specced typed decision-to-decision relations, derived effective status, integrity rules, and three UI affordances — none of it built. This change (C6) implements the route timeline AND subsumes `decision-relations`, because the in-force banner only makes sense next to the journey it modifies.

## Goals / Non-goals

- **Goal:** the Decision detail answers "where is this in its journey, what's still to do?" (Part A route timeline + Part C currentStage/open-actions) and "is this still in force, what replaced it?" (Part B relations + effective-status banner + in-force filter).
- **Goal:** subsume every `decision-relations` requirement in one shipped change.
- **Non-goal:** re-modelling the route/stages or method semantics — C6 renders existing C4/C5 fields read-only.
- **Non-goal:** consolidation (merged amended text), cross-register relations, auto-inference, extending the lifecycle state machine.

## Decisions

### D1 — Modification relations: `supersedes` / `repeals` / `implements` / `refersTo` (FOUR, not five) — resolve the `amends` collision

The subsumed `decision-relations` spec listed FIVE relation types including `amends`. **But the Decision schema already has an `amends` relation** (many-to-one, added by `unify-decision-supertype`): `decisionType=amendment` points at its parent motion via `amends` (it replaced the retired Amendment → Motion relation, and `AmendmentParentMotionTab.vue` reads it). Adding a second array-valued `amends` for legislative modification would collide on the same property key.

**Decision:** ship the modification set as **four** relations — `supersedes`, `repeals`, `implements`, `refersTo` — all array-valued (one-to-many) Decision → Decision. **Do NOT add a second `amends`.** The legislative "amends another decision" semantic is folded onto the EXISTING `amends` relation by widening its description (it already means "this decision modifies that one"; the amendment→motion case is one instance of it), and the effect-bearing set for effective-status derivation is `supersedes`/`repeals` only (`amends` is informational for in-force purposes — an amended decision is still in force, just modified). This keeps one property per concept (ADR-006: storing X and X' as two shapes guarantees drift) and avoids a breaking rename of a relation already wired into a shipped tab.

**Justification recorded; flagged as a DEFERRED_QUESTION** so the user can instead choose to rename the existing relation (e.g. `amendsMotion`) and reintroduce a clean array `amends` — a larger, breaking change touching `AmendmentParentMotionTab` + `useRelationStore`.

### D2 — effectiveStatus: declarative OR calculation if inverse-relation calcs are supported; else client-side in the tab/banner

effectiveStatus is derived from **inbound** effect-bearing relations: a decision is `repealed` if some decided/enacted decision `repeals` it, else `superseded` if some decided/enacted decision `supersedes` it, else its lifecycle status. C4's `currentStage` proves OR calculations can do a `firstRelated` FORWARD lookup; an INBOUND (reverse-relation, gated on the source's lifecycle) lookup is the open question.

**Decision (preferred):** express `effectiveStatus` as a declarative `x-openregister-calculations` entry on Decision using a reverse-relation/`existsRelated`-style expression (mirroring `currentStage`'s `firstRelated` shape but inbound), gated on the source decision's `lifecycle ∈ {decided, enacted}`. ADR-031 mandates declarative-first and this is a register-resident derivation, so it belongs in the register if OR can express it.

**Fallback:** if OR cannot express an inverse-relation-driven calculation, the `DecisionRouteTab`/banner computes effectiveStatus **client-side** from a relation query (`getRelations` for incoming `supersedes`/`repeals`, filtered to decided/enacted sources) — the same query the `RelatedDecisionsTab` already runs for its incoming groups, so no extra round-trip. This keeps the lifecycle state machine untouched either way (effectiveStatus is never a workflow state).

The mechanism choice is a DEFERRED_QUESTION pending verification of OR calculation capability against the live register; the spec delta states the SEMANTICS (derived, read-time, precedence repealed > superseded > lifecycle) without binding the mechanism, so either implementation satisfies it.

### D3 — In-force filter: manifest `filter` over the derived effectiveStatus (preferred), `quickFilters` if CnIndexPage supports tabs

The Decisions index (`/decisions`) needs an in-force filter (`in force` / `superseded` / `repealed`). No manifest currently uses `quickFilters`; the proven pattern is the static `filter` block (Motions page filters `decisionType=motion`).

**Decision (preferred):** add a `quickFilters` block on the Decisions index — user-switchable tabs (All / In force / Superseded / Repealed) over the derived `effectiveStatus` — IF CnIndexPage exposes `quickFilters` (the prompt says "it exists"; verify against the lib). quickFilters fits because in-force is a user-chosen view, not a fixed page scope like Motions. If CnIndexPage does NOT support `quickFilters`, fall back to documenting the filter as a `filter`-shaped option the user toggles, or a follow-up lib change. Either way the filter operates over `effectiveStatus`, which requires D2's derivation to be queryable on list rows (a materialised calculation makes this trivial; a client-side-only derivation would force the filter client-side too — another reason to prefer the declarative calc in D2).

Recorded as a DEFERRED_QUESTION (quickFilters vs filter) pending CnIndexPage capability confirmation.

### D4 — Two new tabs, registered + wired; route tab reads the `route` relation like every other relation tab

`DecisionRouteTab.vue` and `RelatedDecisionsTab.vue` are new `kind: "page"` registry entries (per the registry's "Detail-tab components — one per cross-schema relation" exception) and two new `DecisionDetail.config.sidebarTabs` entries:

- `route` (icon `icon-timezone`/`icon-projects`, order 12 — just after lifecycle, before actionItems): `DecisionRouteTab`.
- `related` (icon `icon-link`, order 35 — after voting): `RelatedDecisionsTab`.

`DecisionRouteTab` reads the Decision's `route` relation via `ensureRelationType('decision'...)` + the object store (the C4 `route` relation resolves to `DecisionStage`; add `decision-stage` → `decisionStageSchema` to `useRelationStore`'s `TYPE_TO_SETTINGS_KEY` if not present), ordering stages by `sequence`, resolving each stage's `assignedBody`/`assignedPerson` name, and highlighting the stage whose id equals the Decision's `currentStage`. Route progress = `decidedStageCount` / `stageCount`. Read-only (resolving a stage is C5's surface, not C6).

`RelatedDecisionsTab` follows the peer-relation pattern (REQ-RTU-002): outgoing relations + derived incoming groups, add via NcSelect object search + relation-type selector, remove via CnDeleteDialog (dialog in `src/modals/` per modal-isolation gate), navigate on row activation, inline server validation errors, empty-`objectId` short-circuit.

### D5 — Effective-status banner placement

The banner ("Superseded by [Budget 2027] on [date]" / "Repealed by [X]") renders when effectiveStatus ≠ lifecycle, with chain navigation to the effecting decision and the lifecycle badge always visible. It lives at the top of the `DecisionRouteTab` (the journey surface) AND/OR the overview tab. Since the overview tab is a manifest `widgets` block (no bespoke component), the banner is rendered by `DecisionRouteTab` at the top of the route timeline, where "currently at stage 3" and "but superseded by Budget 2027" read as one story. (A manifest-native banner widget is a possible follow-up.)

### D6 — Subsume, don't auto-archive `decision-relations`

This proposal notes `decision-relations` as **subsumed** (every requirement implemented here). The actual `openspec archive decision-relations` (marking it superseded so the change queue stops listing it as active) is a SEPARATE explicit step left to the user — archiving mutates the change ledger and should be a deliberate action after this change's specs are confirmed to cover the subsumed deltas. Recommended, recorded as a DEFERRED_QUESTION.

## ASCII — route timeline + effective-status banner (DecisionRouteTab)

```
┌─ Decision: "Vaststelling Programmabegroting 2027" ─────────────────────────┐
│ lifecycle: enacted [badge]        effectiveStatus: in force                  │
│                                                                              │
│ ⚠ This decision SUPERSEDES "Programmabegroting 2026" (10 Apr 2025) →         │
│   (and the 2026 decision's own detail shows: "Superseded by 2027" banner)   │
│                                                                              │
│ Route progress:  ●●○  2 of 3 stages decided                                  │
│                                                                              │
│  seq 1 · College van B&W (body)        preparatory · manual                  │
│   ✓ decided · adopted · 2 Mar 2026                                           │
│  ──────────────────────────────────────────────────────────────────        │
│  seq 2 · Auditcommissie (body)         advisory · advice                     │
│   ✓ decided · advised · 20 Mar 2026                                          │
│  ──────────────────────────────────────────────────────────────────        │
│  ▶ seq 3 · Gemeenteraad (body)         decisive · vote      ◀ currentStage   │
│   ◌ active · (no outcome yet)                                                │
│                                                                              │
│ Still to do: stage 3 (Gemeenteraad) · 2 open action items →                  │
└──────────────────────────────────────────────────────────────────────────┘
```

Banner on the SUPERSEDED target ("Programmabegroting 2026"):

```
┌────────────────────────────────────────────────────────────────────────┐
│ lifecycle: enacted [badge]   ·   effectiveStatus: SUPERSEDED [badge]      │
│ 🔁 Superseded by "Programmabegroting 2027" on 10 Apr 2025  → [navigate]   │
└────────────────────────────────────────────────────────────────────────┘
```

## Seed Data

Add to `Decision.x-openregister-seeds` so the banner + relations tab + effective-status are demonstrable out of the box:

- New seed `besluit-begroting-2027` ("Vaststelling Programmabegroting 2027", `decisionType=meeting-outcome`, `lifecycle=enacted`, `outcome=adopted`) carrying a `supersedes` relation → the existing `besluit-begroting-2026` seed.
- Effect: opening `besluit-begroting-2026` shows the "Superseded by Programmabegroting 2027" banner + effectiveStatus=`superseded`; opening 2027 shows the outgoing `supersedes` in its RelatedDecisionsTab; the in-force filter excludes 2026.
- (Optional) attach a short 3-stage route to 2027 so the route timeline is non-empty in the seed.

## Declarative vs imperative

| Concern | Mechanism | Why |
|---|---|---|
| 4 modification relations | declarative `x-openregister-relations` | ADR-031 — register-resident typed relations |
| effectiveStatus derivation | declarative `x-openregister-calculations` (preferred) / client-side fallback | ADR-031 declarative-first; fallback only if OR can't express inverse-relation calcs (D2) |
| notify body on superseded/repealed | declarative `x-openregister-notifications` (ADR-031 dialect) | notification-dialect gate; no imperative dispatch |
| self-reference / cycle integrity | declarative constraint on the relation if OR supports it; else a thin server-side validation seam | ADR-022 — no pass-through controller; relation CRUD stays on OR object API |
| relation CRUD | OR object API via `useObjectStore` | ADR-022 — apps consume OR abstractions |
| route timeline + banner + tabs | code (`.vue`) | no abstract manifest primitive for a timeline / peer-relation tab |
| in-force filter | manifest `quickFilters`/`filter` (config) | ADR-032 config |

## Mixed-spec rationale (kind: code)

This change is MIXED (ADR-032): config (Decision relation types + effectiveStatus calc + notification rule + in-force filter + seeds) AND code (`DecisionRouteTab.vue` + `RelatedDecisionsTab.vue` + registry + manifest sidebarTabs). Per the established supervised-local precedent (C5 shipped config + a thin code seam as ONE `kind: code` change), this ships as **one change with `kind: code`** — the two new Vue tabs are the irreducible code surface that config cannot express (a route timeline and a peer-relation tab are not manifest primitives), so the change is gated as code while remaining config-first for everything the register can own.

## Risks

- **`amends` collision (D1)** — mitigated by NOT adding a second `amends`; user confirms naming (DEFERRED_QUESTION).
- **Inverse-relation calc may not be declaratively expressible (D2)** — client-side fallback keeps the feature shippable; spec states semantics not mechanism.
- **quickFilters may not be a CnIndexPage capability (D3)** — `filter` fallback; verify against lib before build.
- **Route tab empty when no route configured** — render an empty-state ("No staged route configured"); a stageless Decision is valid per C4.
