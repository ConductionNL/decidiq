# approval-routes Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [approval-routes](../../../approval-routes/) (the engine)
- [approval-route-events](../../../approval-route-events/) (the seam)
- [document-approval-chain-leaf](../../../document-approval-chain-leaf/) (the leaf and `dueAt`)
- [approval-routes-resolve-a-manager-and-declare-silence](../../) (this delta)

## Purpose

Extends the approval-route engine with a step that names a rule instead of a
person, a step that declares what its own silence means, and a clearance answer
a consumer can gate closure on. Closes gap-register rows 3.24 and 3.31.

**Standards**: Awb (Dutch administrative law) sign-off practice, Schema.org
`Action`, Schema.org `Person` and `Organization` as the semantic types a rule
resolves against.

## ADDED Requirements

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
