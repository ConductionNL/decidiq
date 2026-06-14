# Design: unify-decision-supertype

## Architecture Overview

Decidesk is a thin client over OpenRegister (ADR — no app DB tables). Today the
decision domain is split across four schemas in `lib/Settings/decidesk_register.json`:
`Decision`, `Motion`, `Amendment`, `Resolution`. ADR-005 collapses these into one:
`Decision` becomes the universal supertype and the other three become *values of a
`decisionType` discriminator*.

```
            BEFORE                                AFTER
  ┌─────────┐ ┌────────┐ ┌──────────┐     ┌──────────────────────────────┐
  │ Motion  │ │Amendmnt│ │Resolution│     │           Decision           │
  └─────────┘ └────────┘ └──────────┘     │  decisionType ∈ {motion,     │
  ┌──────────────────────┐                │   amendment, resolution,     │
  │       Decision       │   ───────►     │   contract, appointment,     │
  └──────────────────────┘                │   management-point, policy,  │
  ┌─────┐┌─────┐┌────────┐ (orphans)      │   meeting-outcome}           │
  │Offer││Order││Product │                │  + folded type fields        │
  └─────┘└─────┘└────────┘                │  + x-openregister-lifecycle  │
                                          └──────────────┬───────────────┘
                                                 contract │ attachments
                                          ┌─────┐┌─────┐┌─┴──────┐
                                          │Offer││Order││Product │
                                          └─────┘└─────┘└────────┘
```

The register list, search and detail page are one surface filtered by `decisionType`;
"Moties" in the nav is the Decisions register pre-filtered to `decisionType=motion`,
not a separate store (ADR-004 Rule 3, ADR-006). Type-specific fields render only when
the relevant `decisionType` is selected (progressive disclosure, ADR-004 Rule 2).

## Goals / Non-Goals

**Goals**
- One `Decision` schema with a required `decisionType` discriminator (8 values).
- Fold motion/amendment/resolution fields into `decision`; retire those 3 schemas.
- Declarative decision lifecycle (`x-openregister-lifecycle`), per ADR-031.
- Re-home `offer`/`order`/`product` as `contract` decision attachments.
- ORI/Popolo `/api/ori/v1/motions` byte-compatible, sourced from decisions.

**Non-Goals**
- Decision route/stages and pluggable methods (Cycle 2).
- Board-portal retirement / Popolo person model (separate Cycle-1 changes).
- Cross-decision relations (`decision-relations` change).
- Data-preserving migration of irreplaceable records (data is seeded demo data).

## Decisions

### D1: One schema + discriminator, not a JSON-Schema `oneOf` per type
`decisionType` is a plain required enum field; all folded fields live flat on the one
`Decision` schema and are shown/hidden in the form by `decisionType`. Alternative
(`oneOf`/`discriminator` polymorphic sub-schemas) was rejected: OpenRegister stores flat
objects and the progressive-disclosure UI already keys on a single field; `oneOf` adds
validation machinery without product value.

### D2: Reuse the existing richer 7-state lifecycle
The register already declares a `Decision.lifecycle` enum
(`draft → proposed → deliberating → voting → decided → enacted → archived`) built by
`decision-state-machine-v1`. ADR-005's wording (`draft → submitted → in-progress →
decided → published/withdrawn`) maps onto it rather than replacing it: `submitted`≈`proposed`,
`in-progress`≈`deliberating`+`voting`, `published`≈`enacted` (with `isPublished=public`),
`withdrawn` is added as a terminal state. We keep the proven 7 states and add `withdrawn`,
formalised as a declarative `x-openregister-lifecycle` block (it was previously prose-only
on the property). Alternative (rewrite to ADR-005's 5 literal states) was rejected — it would
break `decision-management` (status: done) and the motion/amendment lifecycle semantics that
motion/amendment seeds depend on. Recorded as a deferred question.

### D3: Declarative-vs-imperative — lifecycle is config, not a Service (ADR-031)
The decision lifecycle, notifications and relations are declared in
`lib/Settings/decidesk_register.json`, NOT in new `*Service.php` classes:

| Behaviour | Where it lives |
|---|---|
| Decision lifecycle (draft→…→enacted/withdrawn, guarded transitions) | `x-openregister-lifecycle` on `Decision` |
| "Decision proposed / decided / published" notifications | existing `x-openregister-notifications` on `Decision` (extended for new types where needed) |
| Contract attachments (offer/order/product) | `x-openregister-relations` on `Decision` |
| Amendment→parent-decision link | `x-openregister-relations` on `Decision` (replaces Amendment→Motion) |

The only code touched is (a) the Vue surfaces (fold three view sets into decision-typed
views), and (b) the ORI serializer (read decisions, project to Popolo). No new state-machine,
aggregation, or notification *service* is introduced by this change.

### D4: ORI mapping stays a boundary projection (ADR-001/ADR-003)
`/api/ori/v1/motions` keeps its path and response shape. The serializer's query changes from
"all `motion` objects" to "all `decision` objects where `decisionType=motion`", and the
field mapping reads the folded `motionType`/`proposer`/`coSigners`/`text` fields off the
decision. Storage unifies; Popolo fidelity is recovered at serialization exactly as ADR-001
§Consequences intends.

### D5: Re-seed is the migration
The shipped motion/amendment/resolution objects are demo seeds. Rather than write a
data-preserving object migration, the retired schemas' seeds are rewritten as `Decision`
seeds with the matching `decisionType`, and inter-object references are re-pointed
(amendment→motion becomes amendment-decision→motion-decision; decision→motion becomes a
decision→decision relation). See migration.md.

## Mixed-spec rationale (ADR-032 exception)

ADR-032 (one-surface-per-spec) would normally split a change that touches BOTH the schema
register (config: discriminator, folded fields, lifecycle, schema retirement) AND code (Vue
views, ORI serializer, object re-seed) into a config→code chain, to bound budget burn under
the autonomous Hydra builder. This change is being executed under **supervised local apply**
(opsx-apply with human review between phases), so that budget-burn risk is mitigated and the
user has explicitly scoped it as the single change `unify-decision-supertype`. We therefore
deliver it as one change with `kind: code` (both surfaces touched; code dominates) and order
tasks.md **config-first** (schema-register patches → Vue/ORI rewrites → schema deletion +
re-seed) so the supervisor can review at the natural config/code boundary.

## Declarative-vs-imperative decision (ADR-031)

The decision lifecycle is **declarative**: a guarded transition map declared as
`x-openregister-lifecycle` on the `Decision` schema in `decidesk_register.json` —
NOT a Symfony-Workflow `DecisionService` or a hand-rolled transition method. Likewise
notifications (`x-openregister-notifications`) and the contract attachment relations
(`x-openregister-relations`) are declared on the schema. Imperative code is limited to the
Vue rendering layer and the ORI boundary serializer, which are genuinely presentation/
projection concerns, not business rules.

## Security Considerations

No new endpoints; no new auth surface. RBAC, audit trail and validation come from
OpenRegister object permissions on the unified `decision` schema. The ORI endpoint keeps its
existing public/`isPublished=public` gating — only objects published for citizen visibility
are exposed, unchanged by this fold. `Decision.mailEnabled=true` (inbound email) is retained
with the existing `x-mail-security-audit` note; mail-sourced content must never be rendered
with `v-html` in decision views (carry the audit note forward).

## NL Design System

The folded decision form uses progressive-disclosure field groups (NcSelect for
`decisionType` with `inputLabel`; conditional `<fieldset>` sections per type) and the
existing decision list/detail components. Motion/amendment/resolution-specific components are
removed; their markup folds into the decision components behind `decisionType` conditions. No
hardcoded colours; standard NC components; WCAG AA (NcSelect labels via `inputLabel`).

## File Structure

```
lib/Settings/decidesk_register.json   # discriminator + folded fields + lifecycle + relations + re-seeded objects; remove Motion/Amendment/Resolution
lib/.../OriController|OriService       # serialize decisionType=motion decisions as Popolo Motions
src/views/decision/                    # decision list/detail/form gain decisionType filter + progressive disclosure
src/                                   # remove motion/amendment/resolution views; fold nav "Moties" into a typed filter
```

## Seed Data

Realistic demo decisions across `decisionType` values and general organisation domains
(municipality / corporate board / consultancy / travel agency), re-seeded onto the unified
`Decision` schema. Existing motion (3), amendment (3) and resolution (10) seeds are rewritten
as decisions; new `contract`/`appointment`/`management-point`/`policy` seeds are added so
every discriminator value is demonstrable on install (ADR-016).

### Schema: `decision`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | `besluit-begroting-2026` | `motie-duurzaamheid-2025` | `r-2025-001-goedkeuring-jaarrekening` | `contract-schoonmaak-2026` |
| decisionType | `meeting-outcome` | `motion` | `resolution` | `contract` |
| title | Vaststelling Programmabegroting 2026 | Motie Duurzaamheid: zonnepanelen | Goedkeuring jaarrekening 2024 | Gunning schoonmaakcontract 2026 |
| text | De gemeenteraad stelt de begroting 2026 vast. | De raad verzoekt het college … zonnepanelen. | De RvC verleent goedkeuring aan de jaarrekening 2024. | Het MT gunt het schoonmaakcontract aan Leverancier X. |
| lifecycle | `enacted` | `decided` | `enacted` | `decided` |
| outcome | `adopted` | `adopted` | `adopted` | `adopted` |
| legalBasis | Gemeentewet art. 189 | — | art. 2:101 BW; Statuten art. 18 | Aanbestedingswet 2012 |
| motionType / proposer | — / — | `motion` / Roos de Vries | — / — | — / — |
| resolutionNumber / voteThreshold | — | — | R-2025-001 / simple-majority | — |
| isPublished | public | public | internal | confidential |

Plus: amendment decisions (`decisionType=amendment`, e.g. `amendement-cultuursubsidie`)
linking to their parent motion decision; an `appointment` decision (e.g. appoint treasurer),
a `management-point` (e.g. go/no-go project), and a `policy` decision — one seed per
discriminator value so all 8 types render on install.

**Related items per object:**
- Files: contract decision links a `DigitalDocument` (signed contract PDF) attachment.
- Notes: resolution decision carries a `background` note (folded from `Resolution.background`).
- Tasks: enacted decisions link follow-up `ActionItem` (VTODO) objects.
- Contacts: `proposer` references a Person/participant display name.
- Attachments (contract): `offer`/`order`/`product` objects related to the `contract` decision
  via `x-openregister-relations` (3-5 contract-domain seeds so the attachment path is testable).

## Risks / Trade-offs

- [ORI consumer regression] → keep endpoint/shape; assert with a Newman contract test before/after.
- [Lost references from retired-schema relations] → migration plan re-points amendment→motion and decision→motion references onto the decision objects; re-seed only on a clean register.
- [Lifecycle wording mismatch with ADR-005] → reuse the proven 7-state map + add `withdrawn`; map ADR-005's vocabulary onto it (D2); recorded as a deferred question.
- [Progressive disclosure hides required fields] → field visibility driven purely by `decisionType`; per-type required-field matrix asserted in the test plan.

## Migration Plan

See migration.md. Summary: patch the register (config), re-point references, re-seed retired
objects as typed decisions, remove the three schemas, re-import the register. Rollback = revert
the branch / restore the prior register and re-import (no irreversible data migration).

## Open Questions

- Should the `withdrawn` terminal state be reachable from every non-terminal lifecycle state,
  or only before `voting`? (Provisional: from any state before `enacted`, mirroring motion
  withdrawal — see deferred questions.)
