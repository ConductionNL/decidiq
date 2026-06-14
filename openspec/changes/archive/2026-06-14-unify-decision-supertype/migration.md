# Migration: unify-decision-supertype

> Decidesk owns no database tables — it is a thin client over OpenRegister. This is
> therefore a **register-schema + seed-data** migration of `lib/Settings/decidesk_register.json`
> applied via the register re-import, NOT a Nextcloud `lib/Migration/VersionXXXX.php`
> class. The shipped motion/amendment/resolution objects are SEEDED DEMO DATA, so the
> migration is re-seed-based per ADR-005 (no data-preserving migration of irreplaceable records).

## Current State

`lib/Settings/decidesk_register.json` defines four sibling decision-domain schemas plus three
orphaned procurement schemas:

- `Decision` — generic outcome (8 props, 8 seeds).
- `Motion` — `motionType`, `proposer`, `coSigners`, `text`, `lifecycle`, `submittedAt` (3 seeds); relation `agendaItem → AgendaItem`.
- `Amendment` — `text`, `proposer`, `proposedText`, `lifecycle`, `submittedAt` (3 seeds); relation `motion → Motion`.
- `Resolution` — `resolutionNumber`, `type`, `voteType`, `voteThreshold`, `fullText`, `background`, `legalBasis`, `adoptionDate`, `effectiveDate` (10 seeds); relations `meeting → BoardMeeting`, `proposingMember → BoardMember`.
- `Offer` / `Order` / `Product` — schema.org procurement schemas, 0 seeds, no decision relation.
- `Decision.x-openregister-relations` includes `motion → Motion`.

## Target State

- `Decision` is the universal supertype: required `decisionType` enum (`motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, `meeting-outcome`); folded motion/amendment/resolution fields; declarative `x-openregister-lifecycle` (7 states + `withdrawn`); `x-openregister-relations` adds `amends → Decision` and `offer`/`order`/`product → Offer/Order/Product` (contract attachments); the `motion → Motion` relation is removed.
- `Motion`, `Amendment`, `Resolution` schemas are removed from `components.schemas`.
- `Offer`/`Order`/`Product` schemas remain but are reachable only as contract-decision attachments (no standalone nav store).
- All former motion/amendment/resolution seeds re-expressed as `Decision` seeds with the matching `decisionType`; ≥1 seed per `decisionType` value; new contract seeds link offer/order/product attachments.

## Migration Class

```
No Nextcloud migration class. The change is applied by editing
lib/Settings/decidesk_register.json and re-importing the register
(SettingsService register setup / occ register import on app upgrade).

Key operations on lib/Settings/decidesk_register.json:
- Add Decision.decisionType (required enum) + folded fields + x-openregister-lifecycle
- Add Decision.x-openregister-relations: amends → Decision, offer/order/product attachments
- Remove Decision.x-openregister-relations.motion (→ Motion)
- Re-seed Decision.x-openregister-seeds (typed decisions, all 8 types)
- Delete components.schemas.Motion / Amendment / Resolution
```

## Migration Steps

1. **(config)** Add `decisionType` required enum + folded fields (`motionType`, `proposer`, `coSigners`, `proposedText`, `resolutionNumber`, resolution `type`, `voteType`, `voteThreshold`, `fullText`, `background`, `adoptionDate`, `effectiveDate`) to `Decision.properties`; bump `Decision.version`.
2. **(config)** Add `x-openregister-lifecycle` to `Decision` (states `draft → proposed → deliberating → voting → decided → enacted → archived` + terminal `withdrawn`; guarded transition map).
3. **(config)** Add `x-openregister-relations` on `Decision`: `amends → Decision` (cardinality many-to-one), `offer`/`order`/`product` contract attachments; remove the `motion → Motion` relation.
4. **(data)** Rewrite the 3 `Motion` seeds as `Decision` seeds with `decisionType = motion` (keep slugs; map `motionType`/`proposer`/`coSigners`/`text`; map motion `lifecycle` onto the decision lifecycle).
5. **(data)** Rewrite the 3 `Amendment` seeds as `Decision` seeds with `decisionType = amendment`; re-point each former `motion → Motion` relation to the corresponding `decisionType = motion` decision via the `amends` relation.
6. **(data)** Rewrite the 10 `Resolution` seeds as `Decision` seeds with `decisionType = resolution` (map resolution fields; `meeting`/`proposingMember` references retargeted per the board-portal retirement, or carried as plain fields where the target schema is also being retired — see Data Impact).
7. **(data)** Add new seeds for the previously unrepresented types: `contract` (with offer/order/product attachments), `appointment`, `management-point`, `policy`, and a generic `meeting-outcome`, plus 3-5 procurement seeds for `Offer`/`Order`/`Product`.
8. **(config)** Delete `components.schemas.Motion`, `.Amendment`, `.Resolution`.
9. **(deploy)** Re-import the register so OpenRegister applies the schema changes and seeds.

## Data Impact

- **Demo/seed data only.** 3 motion + 3 amendment + 10 resolution seed objects (16) are transformed into typed `Decision` objects; no irreplaceable records are touched.
- **Relation re-pointing:** `Amendment.motion → Motion` (3) becomes `Decision.amends → Decision`; `Decision.motion → Motion` relations are dropped. `Resolution.meeting → BoardMeeting` / `proposingMember → BoardMember` targets are themselves being retired by the parallel `retire-board-portal` change; this change carries those values as plain fields or retargets to the unified `meeting`/Person entities once that change lands (coordination note, not a blocker for the demo seeds).
- **Live data:** On a production instance with real motion/amendment/resolution objects (not the demo register), operators MUST export those objects, re-import them as `decision` objects with the matching `decisionType`, and re-point relations before removing the schemas. This change ships the re-seed for the demo register; a live-data export/re-import runbook is the operator's responsibility (documented in Rollback/Validation).

## Rollback Procedure

- Revert the branch (or restore the previous `lib/Settings/decidesk_register.json`) and re-import the register. This restores the `Motion`/`Amendment`/`Resolution` schemas and their original seeds.
- Because no irreversible data migration runs, rollback on the demo register is a clean re-import. On a live instance, restore the pre-migration register export.

## Validation

- The `decision` schema lists `decisionType` in `required` and exposes all 8 enum values; `motion`/`amendment`/`resolution` are absent from `components.schemas`.
- A register listing returns ≥1 `decision` object for each `decisionType` value (8/8).
- A `decisionType = amendment` seed resolves an `amends` relation to a `decisionType = motion` decision.
- A `decisionType = contract` seed resolves offer/order/product attachment relations.
- `GET /api/ori/v1/motions` returns the published motion decisions with the unchanged Popolo shape (Newman contract test, before/after parity).
