# Tasks: decision-route-and-stages

Config-first (ADR-031 / ADR-032 `kind: config`): all work is declarative register configuration in `lib/Settings/decidesk_register.json` + seeds. No PHP, no Vue, no NC migration class. C5 (`decision-methods`) and the C6 route UI build on this; they are out of scope here.

## 1. DecisionStage schema

### Task 1: Add the DecisionStage schema
- **spec_ref**: `openspec/specs/decision-route/spec.md#requirement-decisionstage-entity`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN the DecisionStage schema is inspected THEN it defines `sequence`, `stageType`, `status`, `outcome`, `decidedAt`, `method`, `decisionMakerType`, `label`, `note` with the enums from the spec
  - GIVEN a DecisionStage WHEN created THEN `method` defaults to `manual` and `status` defaults to `pending`
- [x] Implement
- [x] Test

### Task 2: Add the DecisionStage status lifecycle
- **spec_ref**: `openspec/specs/decision-route/spec.md#requirement-decisionstage-lifecycle`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the DecisionStage schema WHEN inspected THEN `x-openregister-lifecycle` on `status` has initial `pending`, transitions `pending→active`, `active→decided`, `pending→skipped`, `active→skipped`, and terminal `decided`/`skipped`
- [x] Implement
- [x] Test

## 2. Relations and polymorphic decision-maker

### Task 3: Add the stage→decision and stage→decision-maker relations
- **spec_ref**: `openspec/specs/decision-route/spec.md#requirement-polymorphic-decision-maker-assignment`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the DecisionStage schema WHEN inspected THEN `x-openregister-relations` defines `decision` (→ Decision, many-to-one), `assignedPerson` (→ Person, optional), `assignedBody` (→ GovernanceBody, optional)
  - GIVEN a stage with `decisionMakerType=body` WHEN validated THEN exactly one assignee consistent with the discriminator is required (validation note present)
- [x] Implement
- [x] Test

### Task 4: Add the Decision route relation
- **spec_ref**: `openspec/specs/decision-management/spec.md#requirement-decision-route-relation`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the Decision schema WHEN inspected THEN `x-openregister-relations` includes `route` (→ DecisionStage, one-to-many)
  - GIVEN a Decision with no stages WHEN loaded THEN `route` is empty and the decision is valid
- [x] Implement
- [x] Test

## 3. Declarative route progress (ADR-031)

### Task 5: Add currentStage + routeComplete calculations
- **spec_ref**: `openspec/specs/decision-route/spec.md#requirement-declarative-route-progress-and-currentstage`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a route where stage 1+2 are `decided` and stage 3 is `active` WHEN the Decision is loaded THEN `currentStage` resolves to stage 3
  - GIVEN a route fully decided/skipped WHEN loaded THEN `routeComplete` is true and `currentStage` is null
- [x] Implement
- [x] Test

### Task 6: Add route-progress aggregations
- **spec_ref**: `openspec/specs/decision-management/spec.md#requirement-declarative-route-progress-fields-on-decision`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a route of three stages (two decided, one active) WHEN the Decision is loaded THEN `stageCount=3`, `decidedStageCount=2`, `skippedStageCount=0`, derived declaratively
- [x] Implement
- [x] Test

## 4. Seeds — the ambtelijk → politiek bridge

### Task 7: Seed the municipal route (college → raadscommissie → gemeenteraad)
- **spec_ref**: `openspec/specs/decision-route/spec.md#requirement-the-ambtelijk-politiek-bridge`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is seeded WHEN `besluit-begroting-2027` is loaded THEN its route has a `preparatory` stage on an organisational body, an `advisory` stage on a raadscommissie, and an `active` `decisive` stage on `gemeenteraad-amsterdam`
  - GIVEN the seeds WHEN one stage uses an individual chair THEN it demonstrates `assignedPerson` with `decisionMakerType=person`
- [x] Implement
- [x] Test

### Task 8: Seed the corporate route (MT → RvB → RvC)
- **spec_ref**: `openspec/specs/decision-route/spec.md#requirement-the-ambtelijk-politiek-bridge`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is seeded WHEN `besluit-investering-acme` (`decisionType=management-point`) is loaded THEN its route is MT (`preparatory`) → executive board (`decisive`) → `raad-van-commissarissen-acme-bv` (`ratifying`, `active`, currentStage)
- [x] Implement
- [x] Test

## 5. Register version bump

### Task 9: Bump register + schema versions
- **spec_ref**: `openspec/changes/decision-route-and-stages/migration.md#target-state`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the change WHEN the register is imported THEN `info.version` and the `Decision`/`DecisionStage` schema `version` fields are bumped and import succeeds with no error
- [x] Implement
- [x] Test

## Verification (plain reminders, not task checkboxes)

- `python3 -c "import json; json.load(open('lib/Settings/decidesk_register.json'))"` parses (valid JSON)
- Register imports cleanly via the register-sync path / `occ openregister:import`
- `besluit-begroting-2027` resolves a 3-stage ambtelijk→politiek route with `currentStage` on the gemeenteraad stage
- `besluit-investering-acme` resolves a 3-stage MT→RvB→RvC route with `currentStage` on the RvC stage
- A stageless Decision loads with an empty route and zero-valued progress fields, no error
- Querying DecisionStage objects by `assignedBody` returns the seeded stage (bidirectional relation)
- `openspec validate decision-route-and-stages --strict` passes

## Compliance

- **ADR-005**: route/stages attach to the unified Decision supertype — no new sibling decision entity.
- **ADR-006**: one Decision serves all domains via a route spanning ambtelijk + politiek bodies — no parallel entities.
- **ADR-031**: `currentStage`, `routeComplete`, and progress counts are declarative (`x-openregister-calculations`/`aggregations`); no Service code introduced.
- **ADR-032**: `kind: config` — register-only; the route UI is deferred to C6, per-stage resolution to C5 (`decision-methods`).
- **Hydra gates**: no PHP/Vue touched, so code gates (spdx, route-auth, idor, stub-scan, redundant-controller) are not engaged; notification-dialect remains the canonical `x-openregister-notifications` (unchanged).
