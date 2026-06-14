# Migration: decision-route-and-stages

> Decidesk is a thin client and owns **no** Nextcloud database tables (ADR-005, project architecture). There is no `lib/Migration/Version*.php` class. "Migration" here means a **declarative OpenRegister register migration**: a version bump of `lib/Settings/decidesk_register.json` applied through the existing register-sync path (SettingsService register import / `occ` re-import). The change is purely **additive** — a new schema, one new relation, new declarative fields, and new seed objects. No existing object is altered or deleted; there is no data loss.

## Current State

- `lib/Settings/decidesk_register.json` defines the `Decision` schema with `decisionType`, folded motion/amendment/resolution fields, a declarative lifecycle, and contract attachments (post Cycle-1 `unify-decision-supertype`).
- There is **no** `DecisionStage` schema. A Decision has a single lifecycle and an implicit single decision-maker; the multi-decision-maker route is not representable.
- `GovernanceBody` and `Person` schemas exist with seeded objects (e.g. `gemeenteraad-amsterdam`, `directieteam-gemeente-utrecht`, `auditcommissie-provincie-nh`, `raad-van-commissarissen-acme-bv`).

## Target State

- A new `DecisionStage` schema in `components.schemas` with: `sequence`, `stageType`, `status`, `outcome`, `decidedAt`, `method`, `decisionMakerType`, `label`, `note`; an `x-openregister-lifecycle` on `status` (`pending → active → decided/skipped`); and `x-openregister-relations` `decision` (→ Decision), `assignedPerson` (→ Person, optional), `assignedBody` (→ GovernanceBody, optional).
- `Decision` gains a `route` relation (→ DecisionStage, one-to-many) plus declarative `x-openregister-calculations` (`currentStage`, `routeComplete`) and `x-openregister-aggregations` (`stageCount`, `decidedStageCount`, `skippedStageCount`).
- Register `version` (and `Decision`/`DecisionStage` schema `version`) bumped.
- New seeds: two Decisions (`besluit-begroting-2027`, `besluit-investering-acme`) each with three DecisionStage seed objects spanning ambtelijk + politiek bodies. Existing seeded GovernanceBody objects are reused, not duplicated.

## Migration Class

```
Version: n/a — no Nextcloud DB migration class
Mechanism: declarative OpenRegister register import
File: lib/Settings/decidesk_register.json (version bump)
Applied by: SettingsService register import on app enable/update, or
            occ openregister:import (re-import) in dev
Key operations:
- Register the new DecisionStage schema
- Add the Decision.route relation + declarative route-progress fields
- Upsert the two new Decision seeds and their DecisionStage seeds (idempotent by @self.slug)
```

## Migration Steps

1. Add the `DecisionStage` schema to `components.schemas` in `decidesk_register.json` (properties, `required`, `x-openregister`, `x-openregister-lifecycle`, `x-openregister-relations`).
2. Add the `route` relation to `Decision.x-openregister-relations` (→ DecisionStage, cardinality one-to-many).
3. Add `x-openregister-calculations` (`currentStage`, `routeComplete`) and `x-openregister-aggregations` (`stageCount`, `decidedStageCount`, `skippedStageCount`) to the `Decision` schema.
4. Bump the register `info.version` and the `Decision` + `DecisionStage` schema `version` fields.
5. Add the two new Decision seeds and their DecisionStage seeds to `x-openregister-seeds`, referencing existing GovernanceBody slugs (and one Person seed for the polymorphic-assignee demonstration).
6. Apply via the register-sync path (app update / `occ openregister:import`); verify the new schema and seeded objects materialise.

## Data Impact

- **Records affected**: none altered or deleted. Additive only — one new schema, new declarative fields on `Decision` (no value change to existing objects; calculations/aggregations evaluate to empty/zero on stageless decisions), and a handful of new seed objects.
- **Transformation**: none. Existing Decision objects gain an empty `route` and zero-valued progress fields automatically.
- **Live data**: safe to run on live data — additive schema import, idempotent seed upsert by slug.

## Rollback Procedure

1. Revert the `decidesk_register.json` changes (remove the `DecisionStage` schema, the `Decision.route` relation, and the new calculation/aggregation fields) and restore the prior register `version`.
2. Re-run the register import to deregister the schema.
3. Optionally delete the seeded `DecisionStage` and the two demo `Decision` objects by slug. Existing single-body decisions are unaffected because the added fields were additive and empty on them.

## Validation

- The `DecisionStage` schema appears in the decidesk register (`occ openregister:schemas` or the OpenRegister UI).
- Loading `besluit-begroting-2027` resolves a `route` of three stages in `sequence` order; `currentStage` points at the `decisive` gemeenteraad stage; `stageCount=3`, `decidedStageCount=2`, `skippedStageCount=0`, `routeComplete=false`.
- Loading `besluit-investering-acme` resolves an MT → RvB → RvC route with `currentStage` on the `ratifying` RvC stage.
- A pre-existing Decision with no stages loads with an empty `route` and zero-valued progress fields, with no error.
- Querying all DecisionStage objects assigned to `gemeenteraad-amsterdam` returns the seeded decisive stage (relation query works in both directions).
