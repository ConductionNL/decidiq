# Spec: Decision Route

## Purpose

A decision route models how a single `Decision` travels across multiple decision-makers in sequence — the organisational-to-political (ambtelijk → politiek) bridge. A Decision has an ordered **route** of `DecisionStage` objects; each stage is assigned to a decision-maker that is either a `Person` (individual) or a `GovernanceBody` (group: committee/council/board/MT), and carries its own type, status, and outcome. This capability defines the DecisionStage entity, the polymorphic decision-maker assignment, and the declarative route-progress derivations. HOW each stage is resolved (vote/sign/chair-register) is owned by the `decision-methods` capability; the route timeline UI is owned by a later Cycle-2 change. This is a register-only (config) capability per ADR-005, ADR-006, and ADR-031.

**Standards**: Popolo (`Person`, `Organization`), OpenRaadsinformatie (besluit *behandeltraject*), Akoma Ntoso (workflow steps), Schema.org (`Action` chain)
**Feature tier**: Cycle 2 (V2 differentiator)

## ADDED Requirements

### Requirement: DecisionStage entity

The system SHALL provide a `DecisionStage` schema in the decidesk register, stored as a flat OpenRegister object related to its parent `Decision`. Each DecisionStage SHALL carry: `sequence` (1-based integer order), `stageType` (one of `preparatory`, `advisory`, `decisive`, `ratifying`), `status` (one of `pending`, `active`, `decided`, `skipped`), `outcome` (one of `for`, `against`, `adopted`, `rejected`, `advised`, `deferred`, nullable until decided), `decidedAt` (date-time, nullable), `method` (one of `manual`, `vote`, `sign`, `chair-register`, default `manual`, a placeholder resolved by the `decision-methods` capability), a human `label`, and an optional `note`. A `Decision` SHALL relate to its stages via a `route` relation (one Decision → many DecisionStage). A Decision with no stages (empty route) SHALL remain valid, preserving the single-body behaviour of decisions created before this capability.

#### Scenario: A decision carries an ordered route of stages

- **GIVEN** a Decision "Vaststelling Programmabegroting 2027"
- **WHEN** three DecisionStage objects with `sequence` 1, 2, 3 are related to it
- **THEN** the decision's `route` resolves to the three stages in `sequence` order, each with its own `stageType`, `status`, and `outcome`

#### Scenario: A decision with no stages remains valid

- **GIVEN** a Decision created with no DecisionStage objects
- **WHEN** the decision is loaded
- **THEN** its `route` is empty and the decision behaves as a single-body decision with no validation error

### Requirement: Polymorphic decision-maker assignment

Each `DecisionStage` SHALL be assigned to exactly one decision-maker that is either a `Person` (individual) or a `GovernanceBody` (group). The assignment SHALL be modelled with two optional typed relations — `assignedPerson` (→ Person) and `assignedBody` (→ GovernanceBody) — and a `decisionMakerType` discriminator (`person` or `body`). Exactly one of the two relations SHALL be populated, and it SHALL be the one named by `decisionMakerType`. The assignment SHALL support relational queries in both directions — all stages assigned to a given body, and all stages assigned to a given person.

#### Scenario: Stage assigned to a governance body (group decision-maker)

- **GIVEN** a DecisionStage with `stageType=decisive`
- **WHEN** it is assigned to the GovernanceBody "Gemeenteraad Amsterdam" with `decisionMakerType=body`
- **THEN** `assignedBody` resolves to that body, `assignedPerson` is empty, and the stage appears when querying all stages assigned to that body

#### Scenario: Stage assigned to an individual person

- **GIVEN** a DecisionStage with `stageType=ratifying`
- **WHEN** it is assigned to a Person (e.g. a chair who registers the outcome) with `decisionMakerType=person`
- **THEN** `assignedPerson` resolves to that person, `assignedBody` is empty, and the stage appears when querying all stages assigned to that person

#### Scenario: Discriminator and populated relation must agree

@e2e exclude schema-validation contract — covered by register validation / Newman, not a UI flow

- **GIVEN** a DecisionStage with `decisionMakerType=body` but only `assignedPerson` populated
- **WHEN** the stage is validated
- **THEN** the inconsistency is rejected because the populated relation does not match the discriminator and exactly one assignee is required

### Requirement: The ambtelijk → politiek bridge

A single `Decision`'s route SHALL be able to span both organisational (ambtelijk) decision-makers and political/governance (politiek) decision-makers, so that one decision can be prepared and advised by organisational bodies and then decided or ratified by a political body. The capability SHALL support, without any structural change, the municipal pattern college → raadscommissie → gemeenteraad and the corporate pattern MT → RvB → RvC.

#### Scenario: Municipal decision routed across ambtelijk and politiek bodies

- **GIVEN** a municipal Decision with a route of three stages
- **WHEN** stage 1 (`preparatory`) is assigned to an organisational body (college/directieteam), stage 2 (`advisory`) to a raadscommissie, and stage 3 (`decisive`) to the gemeenteraad
- **THEN** the single decision records the full ambtelijk → politiek journey, each stage owned by a different body with its own status and outcome

#### Scenario: Corporate decision routed MT → RvB → RvC

- **GIVEN** a corporate Decision with `decisionType=management-point` and a route of three stages
- **WHEN** stage 1 (`preparatory`) is assigned to the MT, stage 2 (`decisive`) to the executive board (RvB), and stage 3 (`ratifying`) to the supervisory board (RvC)
- **THEN** the single decision records the operational → executive → supervisory journey across the three bodies

### Requirement: DecisionStage lifecycle

Each `DecisionStage` SHALL have a declarative lifecycle on its `status` field (`x-openregister-lifecycle`, ADR-031): initial `pending`; allowed transitions `pending → active`, `active → decided`, `pending → skipped`, `active → skipped`; `decided` and `skipped` are terminal. When a stage reaches `decided`, its `outcome` SHALL be set and `decidedAt` SHALL be recorded. No imperative Service SHALL drive this lifecycle in this capability.

#### Scenario: Stage progresses pending → active → decided

- **GIVEN** a DecisionStage in `status=pending`
- **WHEN** it becomes `active` and is later resolved with `outcome=adopted`
- **THEN** its `status` is `decided`, `outcome` is `adopted`, and `decidedAt` is set

#### Scenario: Upstream outcome skips a downstream stage

- **GIVEN** a DecisionStage in `status=pending` that is bypassed because an upstream stage's outcome ended the route
- **WHEN** it is marked `skipped`
- **THEN** its `status` is `skipped` (terminal) with no `outcome` and it is excluded from the active route

### Requirement: Declarative route progress and currentStage

The system SHALL derive route progress declaratively (ADR-031), with no imperative computation. On `Decision`: `currentStage` (calculation) SHALL be the first stage whose `status` is neither `decided` nor `skipped`, ordered by `sequence`, and SHALL be null when every stage is decided or skipped; `stageCount`, `decidedStageCount`, and `skippedStageCount` (aggregations) SHALL count the related DecisionStage objects scoped to the decision; `routeComplete` (calculation) SHALL be true when `decidedStageCount + skippedStageCount >= stageCount` and `stageCount > 0`. Consumers (the route UI, decision-methods, notifications) SHALL read these materialised values and SHALL NOT recompute them.

#### Scenario: currentStage points at the first unresolved stage

- **GIVEN** a route where stage 1 is `decided`, stage 2 is `decided`, and stage 3 is `active`
- **WHEN** the decision's `currentStage` is read
- **THEN** it resolves to stage 3

#### Scenario: routeComplete when all stages are resolved

- **GIVEN** a route of three stages all in `status=decided` or `skipped`
- **WHEN** the decision's `routeComplete` is read
- **THEN** it is true and `currentStage` is null

#### Scenario: Progress counts reflect the route

@e2e exclude declarative-aggregation contract — covered by register/Newman, not a UI flow

- **GIVEN** a route of three stages with two `decided` and one `active`
- **WHEN** the decision's progress counters are read
- **THEN** `stageCount` is 3, `decidedStageCount` is 2, `skippedStageCount` is 0
