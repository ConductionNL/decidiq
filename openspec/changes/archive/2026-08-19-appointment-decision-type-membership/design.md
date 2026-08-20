# Design: appointment-decision-type-membership

## Architecture Overview

Hooks into the existing guarded-transition pipeline in
`DecisionLifecycleService::transition()`, at the exact point that already
runs a comparable non-declarative post-transition effect
(`generateResolutionRecord()` on `enacted`):

```
transition('enact')
  → resolveRejection()                     # existing gates + NEW: posts/candidates pairing gate
  → saveObject(lifecycle=enacted, enactedAt=now)   # unchanged
  → applyPostTransitionEffects(newState='enacted')
        if decisionType==='resolution' (existing): generateResolutionRecord()
        if decisionType==='appointment' && outcome==='adopted' (NEW):
              materializeAppointmentMemberships()
                for each candidate → saveObject('membership', {...})
              saveObject('decision', {appointedMemberships: [...ids]})
```

No new controller, no new route, no new top-level service class — this is a
guarded lifecycle side effect, exactly the ADR-031 "lifecycle guard" exception
category, living where its sibling effect already lives.

## Goals / Non-Goals

**Goals**
- Populate `appointedMemberships` on adoption.
- Block enactment when the posts/candidates pairing is ambiguous.
- Make the appointment fields visible on the existing generic Decision detail
  page.

**Non-Goals**
- A bespoke Vue form/component (none needed — see Decision D2).
- Retargeting `TermijnRegeling`/`RoosterVanAftreden`/`RoosterRegel` — they
  already consume any `Membership` regardless of provenance.
- Notifications on appointment (out of scope; not requested by the product
  decision).

## Decisions

### D1: Posts/candidates pairing rule

Three cases, in order of restrictiveness:

| `len(targetPosts)` | Behaviour |
|---|---|
| `0` | Every candidate gets a role-only Membership (`post` absent) — the common case for a generic role seat (e.g. "member of the auditcommissie", no named formal Post). |
| `1` | Every candidate gets that one Post — covers "N people appointed to the same seat type" is nonsensical for a single Post, so in practice this is the 1-candidate/1-post case; kept general rather than special-cased to 1:1 because a body may nominate one candidate against one already-known Post reference. |
| `>1` | MUST equal `len(candidates)`; paired by array index. Any other length is rejected before `enact` (fail closed) — no silent partial-pairing, no "first N win" heuristic. |

Alternative considered: a `mapping` field explicitly pairing each candidate to
a post by id/index. Rejected as over-engineering for a fold this narrow — the
product decision's own phrasing ("ONE OR MULTIPLE Persons for ONE OR MORE
Posts") describes exactly the symmetric N:N or N:1 cases the index rule
already covers; an explicit mapping field would be one more folded property
for a case (asymmetric M:N appointment) the seed data and current product
scope never exercises.

### D2: No bespoke Vue form — confirmed against the actual archived precedent

Verified by inspecting the merged `unify-decision-supertype` commits
(`e84bc3de`, `d34a92f9`): despite that change's own design.md describing
"progressive disclosure... conditional `<fieldset>` sections", the actual
diff touched **zero** `.vue` files — only `src/store/store.js` (remapping the
`motion`/`amendment` logical relation-type keys to the `decision` schema for
`useRelationStore`). Decision create/edit and detail rendering are entirely
generic and manifest-driven (`DecisionDetail`'s `decision-content` widget,
`content.include`-scoped). Confirmed the same widget already renders
`motionType`/`proposer`/`resolutionNumber` etc. for their respective
`decisionType` values with no per-type Vue branching.

This means "progressive disclosure" for `decisionType=appointment` is
achieved by the SAME mechanism already in place: the field exists on the
schema, and adding it to `content.include` makes it render/edit generically
for every decision regardless of type (the field is simply irrelevant/blank
for a `decisionType=motion` decision, exactly as `proposer` is irrelevant/
blank for a `decisionType=resolution` decision today — no runtime hiding is
actually implemented per-type on this widget; "progressive disclosure" here
means "the field group is scoped onto the type-specific pages that use it"
where such pages exist, e.g. the `Moties` page's separate `content.include`.
No `Voordrachten`/appointment-specific page is being added by this change
(that's `ia-six-clusters`' job) — this change only extends the generic
`DecisionDetail` page's field list, matching how the base `decision-content`
widget behaves for every other type today).

Alternative considered: wait for `ia-six-clusters` to add a type-scoped
"Nominations" page with its own narrower `content.include`, and skip touching
`DecisionDetail` in this change. Rejected — this change's own acceptance
criteria need the fields visible somewhere testable now, and every other
`decisionType`'s fields already live on the shared `decision-content` widget
(motion/resolution fields sit there too); a future `ia-six-clusters` page can
additionally scope a narrower view without conflicting with this change's
edit (both consume the same underlying widget-config array; `ia-six-clusters`
would add a new page/widget, not remove this change's list entries).

### D3: Where the guard lives — resolveRejection(), not a new method

`resolveRejection()` already evaluates every fail-closed gate (transition-map
from-state, domain policy, chair-only, quorum-before-voting, outcome-before-
enact) through one rejection-message return contract. The new posts/
candidates pairing check is added as one more branch there, keeping a single
call site for "why can't this transition proceed" — consistent with the
existing architecture rather than introducing a second guard mechanism.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Classification | Where it lives |
|---|---|---|
| Membership materialization on adoption | **Imperative — ADR-031 exception: lifecycle guard** | `DecisionLifecycleService::materializeAppointmentMemberships()`, called from the existing `applyPostTransitionEffects()`. No declarative dialect in this OpenRegister install can create a new object of a different schema as a side effect of a transition (see `appointment-decision-type-schema`'s design.md for the specific dialect-gap evidence, and this schema's own pre-existing `x-decidesk-effectiveStatus-note` documenting the same `CalculationEvaluator` limitation for a different field). |
| Posts/candidates pairing guard | **Imperative — same exception, it's part of the same lifecycle guard** | `resolveRejection()`, same file. A pure validation of two array lengths could in principle be expressed as a `x-openregister-calculations` boolean, but OpenRegister's calculation engine has no array-length/array-compare operator (confirmed: `CalculationEvaluator` supports only `prop, and, or, eq/ne/lt/lte/gt/gte, +, -, *, /, %, concat, if, now, diffDays, formatDate, dateDiff`), and even if it did, a calculated boolean cannot itself BLOCK a transition — only the transition guard can. |
| Field visibility on `DecisionDetail` | Declarative | Plain manifest JSON (`content.include` array) — no code. |

## Mixed-spec rationale (ADR-032 thin-glue note)

This change touches one config file (`src/manifest.json`, a 6-entry array
addition to one existing widget) alongside the PHP service work. The config
edit alone is well under the 20-LOC/2-file thin-glue threshold, but the PHP
work (guard clause + materialization method) is not thin glue on its own —
so this change is declared `kind: code` (the dominant surface), carrying the
small, tightly-coupled manifest edit alongside it rather than spinning up a
third change in the chain for one array edit. This mirrors the archived
`unify-decision-supertype` change's own precedent of declaring `kind: code`
when both surfaces are touched and the config edit is small relative to the
code.

## Security Considerations

`Membership` creation goes through the same `ObjectServiceInterface::saveObject()`
write path every other guarded transition in this service already uses —
per-object write ACL applies identically. No new endpoint, no new auth
surface. An external candidate's `externalName` free-text becomes a
`Membership.label` — no new PII class beyond what the retired `Voordracht`
schema already carried for the same not-yet-registered-candidate case.

## NL Design System

No new UI. The generic data widget's field rendering (labels via each
property's `title`, already declared in `appointment-decision-type-schema`)
follows the existing pattern with no additional work.

## File Structure

```
lib/Service/DecisionLifecycleService.php   # + posts/candidates pairing gate in resolveRejection(); + materializeAppointmentMemberships() called from applyPostTransitionEffects()
src/manifest.json                          # decision-content widget content.include +6 fields
tests/Unit/Service/DecisionLifecycleServiceTest.php  # new test cases (or equivalent existing test file)
```

## Trade-offs

- **[No explicit candidate→post mapping field]** → see D1; accepted because
  the index-pairing rule covers every case the product decision and current
  seed data exercise, and a mismatch fails closed rather than guessing.
- **[Materialization failure doesn't roll back the lifecycle write]** →
  matches the existing `generateResolutionRecord()` precedent exactly (logged
  loudly, decision stays enacted); an operator can manually create the
  missing `Membership` via standard CRUD, same recovery path as a resolution-
  record generation failure today.
