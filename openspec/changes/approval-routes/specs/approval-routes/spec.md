# approval-routes Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [approval-routes](../../changes/approval-routes/)

## Purpose

A reusable sign-off route and the engine that advances it. `ApprovalRoute` is the template (an ordered sequence of actors), `ApprovalAction` is the append-only record of what each actor did, and `ApprovalRouteService` instantiates a template into `DecisionStage` rows and moves them as actions arrive — including sending a route BACK to an earlier step, which no existing outcome expresses.

**Standards**: Awb (Dutch administrative law) sign-off practice, Schema.org `Action`
**Note**: `routedDocumentsJoin.js` routes documents onto a meeting AGENDA and is unrelated to this capability despite the name.

## ADDED Requirements

### Requirement: REQ-AR-001 ApprovalRoute is a reusable template

The system SHALL define an `ApprovalRoute` schema (slug `approval-route`) carrying `name` (required), `subjectType`, `isDefault`, `description`, and `steps` (required) — an ordered array of `{order, stageType, actorType, actor, mandatory, label}`.

A route SHALL be independent of any one subject. It describes a sequence to be travelled, not a sequence being travelled; the travelling instance is `DecisionStage`.

`steps[].mandatory` SHALL default to `true`. A step whose skippability is unstated is required — the safe reading, since the alternative silently permits skipping every step nobody thought about.

#### Scenario: One template, many subjects

- GIVEN an `ApprovalRoute` with three steps
- WHEN it is instantiated against two different subjects
- THEN each subject gets its own stages
- AND the route object is unchanged by either

#### Scenario: A route without steps is rejected

- GIVEN a create omitting `steps` or `name`
- WHEN it is saved
- THEN OpenRegister schema validation rejects it

### Requirement: REQ-AR-002 ApprovalAction is append-only

The system SHALL define an `ApprovalAction` schema (slug `approval-action`) carrying `subject` (required), `subjectSchema`, `step` (required integer), `actor` (required), `actorType` (`user` | `delegate`), `onBehalfOf`, `mandate`, `action` (required — `approved` | `returned` | `advised` | `skipped` | `endorsed`), `comment`, `advice`, and `recordedAt`.

Recording an action SHALL create a NEW object. An action SHALL NOT overwrite a previous one, and the engine SHALL NOT delete actions when a route is returned to an earlier step.

This is the difference between a stage and a trail. A `DecisionStage` holds where a route IS; the actions hold what happened, including the attempts a return undid. Collapsing them loses precisely the history that makes a sign-off auditable.

#### Scenario: A return preserves what came before

- GIVEN a route where step 2 was approved and step 3 returned it to step 2
- WHEN the actions for that subject are read
- THEN the step-2 approval AND the step-3 return are both present
- AND a subsequent step-2 approval is a third row, not an edit of the first

#### Scenario: A delegate's action records the principal

- GIVEN an actor acting under mandate for another
- WHEN the action is recorded with `actorType: delegate`, `onBehalfOf` and `mandate`
- THEN all three are stored on the action

### Requirement: REQ-AR-003 DecisionStage gains sign-off vocabulary

The system SHALL extend `DecisionStage` additively: `stageType` gains `endorsement`; `outcome` gains `approved`, `endorsed`, `returned` and `skipped`; and a `mandatory` boolean (default `true`) is added.

Every existing value SHALL keep its meaning, and `required` SHALL be unchanged, so no stored stage becomes invalid.

`mandatory` on the stage records what the template said at instantiation. Reading it from the route at decision time would give a different answer whenever the template changed after a subject started travelling it.

#### Scenario: An in-flight route is unaffected by a template edit

- GIVEN a subject part-way through a route
- WHEN the template's step is later made optional
- THEN the already-instantiated stage keeps the value it was created with

### Requirement: REQ-AR-004 A route is instantiated into stages

The system SHALL provide `ApprovalRouteService::instantiate()`, which materialises a route's steps as `DecisionStage` rows for a subject, in `order`, and marks the FIRST stage `active` with the rest `pending`.

Instantiating a route twice for the same subject SHALL NOT produce a second set of stages.

#### Scenario: The first step is live immediately

- GIVEN a route with three steps
- WHEN it is instantiated for a subject
- THEN three stages exist, with sequences 1..3
- AND stage 1 is `active` and stages 2 and 3 are `pending`

#### Scenario: Instantiation is idempotent

- GIVEN a subject that already has stages from this route
- WHEN instantiate is called again
- THEN no additional stages are created

### Requirement: REQ-AR-005 Recording an action advances the route

The system SHALL provide `ApprovalRouteService::record()`, which appends an `ApprovalAction`, applies it to the subject's ACTIVE stage, and advances.

- `approved`, `endorsed`, `advised` and `skipped` SHALL set the active stage `decided` (or `skipped`) with the matching `outcome`, and set the next `pending` stage `active`.
- When no later stage remains, the route SHALL be complete and no stage SHALL be left `active`.
- A `skipped` action SHALL be refused when the active stage is `mandatory`.

#### Scenario: The route moves one step

- GIVEN a subject on step 1 of three
- WHEN an `approved` action is recorded
- THEN stage 1 is `decided` with outcome `approved`
- AND stage 2 is `active`

#### Scenario: The last step completes the route

- GIVEN a subject on the final step
- WHEN it is approved
- THEN that stage is `decided`
- AND no stage is `active`

#### Scenario: A mandatory step cannot be skipped

- GIVEN an active stage with `mandatory: true`
- WHEN a `skipped` action is recorded
- THEN it is refused
- AND the stage is unchanged

### Requirement: REQ-AR-006 A return re-opens an earlier step

The system SHALL support a `returned` action naming an earlier step. Recording it SHALL set that earlier stage `active` and EVERY stage after it back to `pending`, discarding their outcomes.

This is the behaviour no existing `DecisionStage.outcome` expresses: `rejected` and `deferred` both end a stage, and neither reopens one.

A `returned` action naming a step at or after the active one SHALL be refused.

#### Scenario: A return rewinds the route

- GIVEN a subject on step 3, with steps 1 and 2 decided
- WHEN a `returned` action naming step 2 is recorded
- THEN stage 2 is `active` again with no outcome
- AND stage 3 is `pending`
- AND stage 1 keeps its outcome

#### Scenario: A return cannot go forwards

- GIVEN a subject on step 2
- WHEN a `returned` action naming step 3 is recorded
- THEN it is refused
- AND no stage changes

### Requirement: REQ-AR-007 The engine is fail-closed on the actor

The system SHALL refuse an action whose actor is not the one the active stage names, unless the stage names no actor at all.

The refusal SHALL be an error the caller receives. It SHALL NOT be recorded as an action, and SHALL NOT advance the route.

A guard that returns a value the caller may ignore is not a guard. This engine's whole purpose is that a sign-off route is only meaningful if the sequence is enforced.

#### Scenario: The wrong actor is refused

- GIVEN an active stage assigned to person A
- WHEN person B records an approval
- THEN the request is refused
- AND no ApprovalAction is created and no stage changes

#### Scenario: An unassigned step accepts any actor

- GIVEN an active stage naming no person or body
- WHEN any authenticated actor approves
- THEN the action is recorded and the route advances

#### Scenario: An action with no active stage is refused

- GIVEN a subject whose route is complete
- WHEN a further action is recorded
- THEN it is refused
