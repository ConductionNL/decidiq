---
kind: code
---

# Proposal: unify-decision-supertype

## Summary

Make `Decision` the single universal supertype for everything decidesk decides. Add a required `decisionType` discriminator (`motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, `meeting-outcome`), fold the type-specific fields of the `motion`, `amendment`, and `resolution` schemas into `decision` (rendered via progressive disclosure), retire those three schemas, re-seed their demo objects as typed decisions, weave the orphaned `offer`/`order`/`product` schema.org schemas in as attachments to `contract` decisions, and keep the ORI/Popolo `/api/ori/v1/motions` output identical by serializing `decisionType=motion` decisions as Popolo Motions at the boundary.

## Motivation

ADR-005 establishes `Decision` as the universal supertype: a council motion, a corporate resolution, a procurement contract award and a management go/no-go are all the same kind of thing — something that had to be decided. The shipped data model contradicts this with three sibling entities (`motion`, `amendment`, `resolution`) plus the latent ADR-001 design-choice-#1 statement that there should be *no* Decision entity at all. A user today sees "Moties", "Resolutions" and "Decisions" side by side and cannot tell which to use — exactly the vocabulary confusion ADR-004 set out to prevent — and the procurement schemas (`offer`/`order`/`product`) sit orphaned with no decision to attach to. ADR-006 makes "one schema per concept" binding at the data layer. This change is Cycle 1 of that programme and the prerequisite for `decision-relations`, `decision-route-and-stages`, and `decision-methods`.

## Affected Projects

- [ ] Project: `decidesk` — `decision` schema gains `decisionType` + folded type fields + declarative lifecycle; `motion`/`amendment`/`resolution` schemas retired and re-seeded as decisions; motion/amendment/resolution Vue views fold into decision-typed views and filters; ORI serializer reads decisions; `offer`/`order`/`product` become contract attachments.

## Scope

### In Scope

- Add required `decisionType` enum discriminator to the `decision` schema in `lib/Settings/decidesk_register.json`.
- Fold `motion` (motionType, proposer, coSigners, text), `amendment` (proposedText, amends-relation, proposer) and `resolution` (resolutionNumber, type, voteType, voteThreshold, fullText, background, adoptionDate, effectiveDate) fields into `decision`, rendered with progressive disclosure keyed on `decisionType`.
- Declare the decision lifecycle (draft → submitted → in-progress → decided → published/withdrawn) as a declarative `x-openregister-lifecycle` on the `decision` schema.
- Retire (deactivate + remove) the `motion`, `amendment`, and `resolution` schemas.
- Re-seed the shipped motion/amendment/resolution demo objects as `decision` objects with the matching `decisionType`, across general-org domains.
- Add `offer`/`order`/`product` as declarative relation attachments on `decisionType=contract` decisions, with realistic contract seed data.
- Fold motion/amendment/resolution Vue views and nav items into decision views pre-filtered by `decisionType`.
- Keep ORI/Popolo output byte-compatible: `/api/ori/v1/motions` serializes `decisionType=motion` decisions as Popolo Motions.

### Out of Scope

- Decision route/stages and pluggable decision *methods* (Cycle 2 — `decision-route-and-stages`, `decision-methods`).
- The board-portal overlay retirement and Popolo person/org unification (separate Cycle 1 changes `retire-board-portal`, `popolo-decision-makers`, `ia-six-item-nav`).
- A data-preserving migration of irreplaceable production records — the shipped data is seeded demo data, so migration is re-seed-based.
- Cross-decision relations (`supersedes`/`amends`/`repeals`) — owned by the separate `decision-relations` change.

## Approach

Config-first: patch the schema register (add `decisionType`, fold fields, declarative lifecycle, contract attachment relations) before touching code. Then rewrite the frontend so the motion/amendment/resolution surfaces become `decisionType`-filtered views over the unified store, update the ORI serializer to project decisions, and finally retire the three schemas and re-seed their objects as typed decisions. Per ADR-031 the lifecycle, notifications, and relations are declared in `decidesk_register.json`, not new Service classes. The Popolo mapping stays a thin projection at the serialization boundary per ADR-001/ADR-003.

## New Dependencies

None.

## Impact

- **Schemas** (`lib/Settings/decidesk_register.json`): `decision` schema version bump (discriminator + folded fields + `x-openregister-lifecycle` + contract attachment relations + re-seeded objects). `motion`, `amendment`, `resolution` schemas removed.
- **Frontend** (`src/`): motion/amendment/resolution list/detail/form views fold into decision views with a `decisionType` filter and progressive-disclosure form sections; nav "Moties" becomes a typed filter, not a sibling store. All reads/writes via `useObjectStore`.
- **ORI serializer** (`lib/` ORI controller/service): reads `decision` objects where `decisionType=motion` instead of `motion` objects; same endpoint, same Popolo shape.
- **Standards**: Popolo Motion / ORI Besluit mapping preserved at the boundary; schema.org `offer`/`order`/`product` re-homed as contract attachments.

## Cross-Project Dependencies

None at the data layer — OpenRegister stores the objects. ORI consumers (Dutch municipalities) read `/api/ori/v1/...`; their contract is preserved unchanged.

## Risks

### Risk 1: ORI/Popolo output regression for external consumers

**Severity:** High — **Mitigation:** Keep `/api/ori/v1/motions` endpoint and response shape identical; serialize `decisionType=motion` decisions through the existing Popolo projection. Cover with a contract test (Newman) asserting the response shape is unchanged before and after the fold.

### Risk 2: Lost references from objects/relations that pointed at retired schemas

**Severity:** Medium — **Mitigation:** Re-seed is the migration; the migration plan enumerates every retired-schema relation (amendment→motion, decision→motion) and re-points it at the corresponding decision object. Run the re-seed only on a clean/demo register.

### Risk 3: Progressive-disclosure form complexity hides required fields per type

**Severity:** Low — **Mitigation:** Drive field visibility purely off `decisionType`; document the per-type required-field matrix in design.md and assert it in the test plan.

## Rollback Strategy

The change is delivered as schema-register edits plus frontend/ORI code on a branch. Revert the branch (or restore the prior `decidesk_register.json` and re-import the register) to bring back the `motion`/`amendment`/`resolution` schemas and their seeds. Because data is seeded demo data, no irreversible data migration is performed.

## Capabilities

### New Capabilities

_None — this unifies and deepens existing capabilities; no new surface._

### Modified Capabilities

- `decision-management`: ADDED requirements — `decisionType` discriminator, folded type-specific fields with progressive disclosure, declarative decision lifecycle, and `offer`/`order`/`product` contract attachments.
- `motion-management`: RETIRED — motions become `decisionType=motion` decisions; the capability redirects to `decision-management`.
- `amendment-workflow`: RETIRED — amendments become `decisionType=amendment` decisions; the capability redirects to `decision-management`.
- `resolution-minutes`: MODIFIED — resolutions become `decisionType=resolution` decisions; the Resolution storage requirement redirects to the unified `decision` schema (minutes capability otherwise unchanged).
- `ori-api`: MODIFIED — the ORI Motion serialization sources `decisionType=motion` decisions instead of `motion` objects; endpoints and response shapes unchanged.
