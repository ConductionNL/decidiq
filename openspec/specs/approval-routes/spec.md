# approval-routes Specification

## Purpose
Extends the approval-route engine with a step that names a rule instead of a
person, a step that declares what its own silence means, and a clearance answer
a consumer can gate closure on. Closes gap-register rows 3.24 and 3.31.

**Standards**: Awb (Dutch administrative law) sign-off practice, Schema.org
`Action`, Schema.org `Person` and `Organization` as the semantic types a rule
resolves against.

## Requirements

### Requirement: REQ-AR-012 A step may name a rule instead of a person

`ApprovalRoute.steps[]` SHALL carry an optional `actorRule`, an enum of
`manager-of-subject-owner`, `manager-of-actor` and `substitute-of-actor`. A step
SHALL carry `actor` or `actorRule`, never both, and a step with neither stays
the unassigned step REQ-AR-007 already allows.

`manager-of-actor` and `substitute-of-actor` SHALL carry `actorRuleSubject`,
the person the rule is read against. `manager-of-subject-owner` SHALL read the
owner of the subject the route travels.

A rule SHALL be resolved when the stage becomes active, not when the route is
instantiated, and the resolved person SHALL be written onto the stage as its
`actor` together with `actorResolvedBy` (the rule) and `actorResolvedAt`.

Resolving at activation is the difference between asking who manages this
person now and who managed them the day the route started. Writing the answer
onto the stage is what keeps the record saying who was actually asked.

#### Scenario: The manager is resolved when the step becomes live

- GIVEN a route whose second step carries `actorRule: manager-of-subject-owner`
- WHEN step one is approved and step two becomes active
- THEN step two's `actor` is the owner's manager, with `actorResolvedBy` and `actorResolvedAt` set
- @e2e exclude service path; covered by PHPUnit on the resolver with a stubbed organisation record

#### Scenario: A step carrying both an actor and a rule is rejected

- GIVEN a route step with `actor` and `actorRule` both set
- WHEN the route is saved
- THEN OpenRegister schema validation rejects it

#### Scenario: A late change of manager reaches only the steps not yet asked

- GIVEN a route on step one, and step two carrying `manager-of-subject-owner`
- WHEN the owner gets a different manager before step two becomes active
- THEN step two resolves to the new manager
- AND a stage already resolved keeps the person it recorded
- @e2e exclude service path; covered by PHPUnit with two organisation reads

### Requirement: REQ-AR-013 The manager comes from the organisation record

The resolver SHALL read the organisation record through OpenRegister, by
semantic type (ADR-048), against whichever installed schema implements the
person kind and carries a manager reference. It SHALL NOT resolve a service
class in another app's container and SHALL NOT call a sibling app over HTTP
(ADR-022, ADR-041).

Resolution SHALL fail closed. When the rule resolves to nobody, to more than one
person, or to a person with no account on this instance, the engine SHALL refuse
to activate the stage and SHALL raise an explanatory error naming the rule and
the subject. It SHALL NOT fall back to the route's owner, to the previous
actor, or to an unassigned step.

A fallback here would turn a missing manager into a wrong signature, and a
wrong signature is indistinguishable from a real one once it is recorded.

#### Scenario: No schema implements the person kind

- GIVEN an instance with no installed schema implementing the person kind
- WHEN a step carrying `manager-of-subject-owner` becomes active
- THEN activation is refused with an error naming the rule
- AND no stage is left active and no action is recorded
- @e2e exclude service path; covered by PHPUnit with an empty schema set

#### Scenario: Two candidates are a refusal, not a choice

- GIVEN an owner whose organisation record names two managers
- WHEN the rule resolves
- THEN the engine refuses and names both candidates in the error
- @e2e exclude service path; covered by PHPUnit

#### Scenario: The resolver never reaches into a sibling app

- GIVEN the resolver's implementation
- WHEN the mechanical gates run
- THEN it contains no cross-app service resolution and no server-side call to a sibling app route
- @e2e exclude static check; covered by hydra gate-27 and a PHPUnit structural test

### Requirement: REQ-AR-014 A subject with an unconcluded required route is not cleared

The system SHALL answer, for any subject, whether every route on it that is
marked `required` has concluded. The answer SHALL carry the route, the stage
that is waiting and the person it is waiting on, so a consumer can say why it
refuses rather than only that it refuses.

`ApprovalRoute` SHALL carry `required` (default `true`). The answer SHALL be
readable over decidiq's own controller and SHALL be carried on
`ApprovalRouteConcludedEvent` for a consumer that projects it.

A consumer that cannot reach decidiq SHALL treat the subject as not cleared
(ADR-041, fail closed). Absence of an engine is not an approval.

#### Scenario: A waiting route blocks clearance and says who it waits on

- GIVEN a subject with one required route active on step two
- WHEN the clearance answer is read
- THEN it reads not cleared, naming the route, stage two and its actor
- @e2e exclude service path; covered by PHPUnit

#### Scenario: A concluded route clears the subject

- GIVEN a subject whose only required route has concluded
- WHEN the clearance answer is read
- THEN it reads cleared
- @e2e exclude service path; covered by PHPUnit

#### Scenario: A route marked not required never blocks

- GIVEN a subject with one active route carrying `required: false`
- WHEN the clearance answer is read
- THEN it reads cleared
- @e2e exclude service path; covered by PHPUnit

### Requirement: REQ-AR-015 A step declares what its silence means

`ApprovalRoute.steps[]` SHALL carry `onSilence`, an enum of `hold`, `approve`,
`refuse` and `escalate`, defaulting to `hold`. The value SHALL be copied onto
the stage at instantiation, alongside the `dueAt` REQ-AR-009 already writes.

- `hold`, the default, SHALL leave the stage active past its `dueAt` and change
  nothing. The stage renders overdue, which REQ-AR-009 already defines.
- `approve` SHALL complete the stage with outcome `approved` and advance.
- `refuse` SHALL complete the stage with outcome `rejected` and conclude the
  route.
- `escalate` SHALL reassign the stage to the actor's manager, resolved by the
  REQ-AR-013 resolver, and restart the stage's window once.

A stage with no `dueAt` SHALL never lapse, whatever its `onSilence` says.

`approve` SHALL be settable only by an administrator, and a route carrying it
SHALL say so on its own detail surface. Silence that approves is a signature
nobody gave, so it is declared deliberately or not at all.

#### Scenario: The default holds

- GIVEN a step with no `onSilence` and a `dueAt` two days past
- WHEN the sweep runs
- THEN the stage is still active with no outcome
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

#### Scenario: A declared approval on silence advances the route

- GIVEN an active stage with `onSilence: approve` and a `dueAt` in the past
- WHEN the sweep runs
- THEN the stage is `decided` with outcome `approved` and the next stage is active
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

#### Scenario: A declared refusal on silence concludes the route

- GIVEN an active stage with `onSilence: refuse` and a `dueAt` in the past
- WHEN the sweep runs
- THEN the stage is `decided` with outcome `rejected` and no stage is active
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

#### Scenario: Escalation asks the manager once

- GIVEN an active stage with `onSilence: escalate` and a `dueAt` in the past
- WHEN the sweep runs
- THEN the stage's actor is the previous actor's manager and its window restarts
- AND a second lapse of the same stage applies `hold`, never a second escalation
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

#### Scenario: A stage without a due date never lapses

- GIVEN an active stage with `onSilence: refuse` and no `dueAt`
- WHEN the sweep runs
- THEN nothing changes
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

### Requirement: REQ-AR-016 The substitute is asked before the deadline, not after

`ApprovalRoute.steps[]` SHALL carry `askSubstituteAfter`, a fraction of the
stage's window between 0 and 1. When that fraction of the time between the
stage becoming active and its `dueAt` has passed with no action, the system
SHALL resolve the actor's substitute by the REQ-AR-013 resolver and SHALL ask
them too.

Asking the substitute SHALL NOT remove the original actor. Both may act, the
first action decides the stage, and the action records who signed and, for the
substitute, `onBehalfOf` the original actor (ADR-099: the substitute signs as
themselves, no session is swapped).

When no substitute resolves, the stage SHALL stay with its original actor and
SHALL record that no substitute was found. That is a note on the stage, not a
refusal: the deadline and its declared meaning still apply.

`askSubstituteAfter` unset SHALL mean no substitute is asked.

#### Scenario: The substitute is asked at the declared point in the window

- GIVEN an active stage with a ten day window and `askSubstituteAfter: 0.5`
- WHEN five days pass with no action
- THEN the substitute is asked and the original actor is still asked
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

#### Scenario: The substitute signs on behalf of the actor

- GIVEN a stage where the substitute has been asked
- WHEN the substitute approves
- THEN the action records the substitute as actor, `onBehalfOf` the original actor
- AND the stage is decided and the route advances
- @e2e exclude service path; covered by PHPUnit

#### Scenario: No substitute is a note, not a refusal

- GIVEN an active stage whose actor has no substitute
- WHEN the ask point passes
- THEN the stage stays with its actor and records that no substitute was found
- AND its `dueAt` and `onSilence` are unchanged
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

### Requirement: REQ-AR-017 A lapse is recorded as an action, and applied once

One `TimedJob` under `lib/BackgroundJob/`, registered in `appinfo/info.xml`
(ADR-069), SHALL sweep active stages whose `dueAt` has passed and apply
REQ-AR-015 and REQ-AR-016.

Every lapse that changes a stage SHALL append an `ApprovalAction` with
`actorType: system`, the applied policy in `comment`, and `recordedAt` of the
sweep. The append-only rule of REQ-AR-002 SHALL hold: a lapse never edits an
earlier action.

The sweep SHALL be idempotent. Running it twice over the same lapsed stage
SHALL produce one action and one advance. A stage that a person decided between
two sweeps SHALL be left alone.

#### Scenario: The sweep writes an auditable action

- GIVEN a lapsed stage with `onSilence: approve`
- WHEN the sweep runs
- THEN an `ApprovalAction` exists with `actorType: system` naming the policy
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

#### Scenario: Two sweeps do the work once

- GIVEN a lapsed stage
- WHEN the sweep runs twice
- THEN exactly one action was recorded and the route advanced one step
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

#### Scenario: A person who acted just in time wins

- GIVEN a stage decided by its actor after the `dueAt` but before the sweep
- WHEN the sweep runs
- THEN the stage is untouched and no system action is recorded
- @e2e exclude timed path; covered by PHPUnit with a fixed clock

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

### Requirement: REQ-AR-008 A route can be held from named users

`ApprovalRouteService::holdFor(subject, actors, deadline, kind)` SHALL build
a route with `origin: adhoc` and one stage per actor, in the given order,
without a stored `ApprovalRoute` template, and SHALL then follow the
instantiate path of REQ-AR-004. `HoldApprovalRouteRequestedEvent` SHALL
accept an optional ordered `actors[]` with the same effect.

#### Scenario: A clerk names three reviewers

- GIVEN a document object and three user ids
- WHEN the clerk holds a review route with those users in order
- THEN a route with three stages exists, stage one assigned to the first user
- @e2e exclude service path; covered by PHPUnit on `ApprovalRouteService::holdFor()`

### Requirement: REQ-AR-009 One deadline splits over the steps

`DecisionStage` SHALL carry `dueAt`. When a route is held with a `deadline`,
the service SHALL divide the working days until the deadline evenly over the
stages, the last stage on the deadline. The holder MAY edit a stage's
`dueAt`. A stage past `dueAt` without an action SHALL render as overdue; this
is a computed view, not a lifecycle state.

#### Scenario: Nine working days over three steps

- GIVEN a deadline nine working days from now and three stages
- WHEN the route is held
- THEN the stages carry `dueAt` on working days three, six and nine
- @e2e exclude date arithmetic; covered by PHPUnit with a fixed clock

### Requirement: REQ-AR-010 The route is a render-surface leaf

decidiq SHALL register a leaf `decidiq-approval-chain` of kind
`render-surface` on both halves under one id, with a `tab` (the timeline
with every action and reason) and a `widget` (the current step, its due date,
and for the current actor the approve and reject actions, reject requiring a
reason). Start, approve and reject SHALL run through decidiq's own service
and controller. The leaf SHALL NOT invoke any action in the consuming app
(ADR-066 decision 2).

#### Scenario: A reviewer approves from the case's documents tab

- GIVEN dossiq places `decidiq-approval-chain` on a document and the user is the current actor
- WHEN the user approves in the widget
- THEN an `ApprovalAction` is recorded, the next stage becomes current and the widget shows the next actor
- e2e: `tests/e2e/approval-chain-leaf.spec.ts`

#### Scenario: A user who is not the current actor sees no buttons

- GIVEN a route whose current stage belongs to someone else
- WHEN the widget renders for this user
- THEN it shows the timeline and no approve or reject action
- e2e: `tests/e2e/approval-chain-leaf.spec.ts`

### Requirement: REQ-AR-011 The decisions leaf names its tab

The `decidesk-decisions` leaf SHALL declare a `tab` with a label and icon in
both halves, keeping `mount` and `unmount` as its render pair, so a sidebar
host shows decidiq's own label instead of a fallback.

#### Scenario: The sidebar shows the decidiq label

- GIVEN a consumer sidebar hosting `decidesk-decisions`
- WHEN the sidebar renders its tabs
- THEN the tab reads the label decidiq declared, not a fallback naming decidesk
- e2e: `tests/e2e/decisions-leaf-tab.spec.ts`
