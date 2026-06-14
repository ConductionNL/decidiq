# Spec delta: Decision Management — decision route relation and route-progress derivation

This file contains delta specifications for the `decision-route-and-stages` change against the existing `decision-management` capability. It adds a `route` relation from `Decision` to the new `DecisionStage` entity and declarative route-progress fields on `Decision`. The Decision lifecycle state machine, decisionType discriminator, and existing fields are unchanged. The full DecisionStage entity, polymorphic decision-maker assignment, and stage lifecycle are owned by the new `decision-route` capability.

---

## ADDED Requirements

### Requirement: Decision route relation

The `Decision` schema SHALL support a `route` relation to `DecisionStage` objects (one Decision → many DecisionStage), representing the ordered path the decision travels across decision-makers. The route SHALL be optional: a Decision with an empty route SHALL remain valid and behave as a single-body decision, preserving all behaviour of decisions created before this change. Adding or removing stages SHALL NOT change the Decision's own `lifecycle` field; the route is orthogonal to (and complements) the decision-to-decision relations owned by the `decision-relations` change.

#### Scenario: A decision exposes its route

- **GIVEN** a Decision with three related DecisionStage objects
- **WHEN** the decision is loaded
- **THEN** its `route` resolves to the stages in `sequence` order without altering the decision's `lifecycle`

#### Scenario: Existing single-body decisions are unaffected

- **GIVEN** a Decision created before this change with no stages
- **WHEN** it is loaded
- **THEN** its `route` is empty and every existing field and lifecycle transition behaves exactly as before

### Requirement: Declarative route-progress fields on Decision

The `Decision` schema SHALL expose declarative route-progress fields (ADR-031), computed from its related DecisionStage objects with no imperative Service code: `currentStage` (the first stage whose `status` is neither `decided` nor `skipped`, by `sequence`; null when the route is complete), `stageCount`, `decidedStageCount`, `skippedStageCount`, and `routeComplete`. These fields SHALL be derived/materialised by OpenRegister calculations and aggregations, mirroring the existing declarative pattern already used on the Meeting schema. They SHALL NOT introduce a new Service or modify the Decision lifecycle transition map.

#### Scenario: Route progress is materialised on the decision

@e2e exclude declarative-derivation contract — covered by register/Newman, not a UI flow

- **GIVEN** a Decision with a route of three stages, two `decided` and one `active`
- **WHEN** the decision is loaded
- **THEN** `currentStage` points at the active stage, `stageCount` is 3, `decidedStageCount` is 2, and `routeComplete` is false — all derived declaratively
