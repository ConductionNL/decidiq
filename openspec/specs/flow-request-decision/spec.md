# flow-request-decision Specification

## Purpose
Let any OpenRegister flow ask decidiq for a decision about the object it is
carrying, wait for it, and route on the outcome. Decidiq owns the node because
deciding is decidiq's; the flow engine stays OpenRegister's (ADR-065).

## Requirements

### Requirement: REQ-FRD-001 Decidiq contributes a request-decision node

Decidiq SHALL contribute a flow node with id `decidiq.request-decision` to
OpenRegister's node catalogue through a `RegisterFlowNodesEvent` listener. The
registration SHALL be guarded so decidiq boots and runs when OpenRegister, or
its flow engine, is absent.

The node SHALL accept exactly the config keys `dossiq.requestDecision` accepts,
name for name: `question` (required), `decisionType` (default `advice`),
`advisor`, `signalKey` (default `decisionOutcome`) and `heartbeatMinutes`
(default 120, never below 15).

#### Scenario: The node is offered when the engine asks

@e2e exclude in-process registration contract with no page of its own; pinned by DecidiqFlowNodeListenerTest and FlowNodeRegistrarTest
- **GIVEN** OpenRegister's flow engine dispatches `RegisterFlowNodesEvent`
- **WHEN** decidiq's listener handles it
- **THEN** the catalogue holds `decidiq.request-decision`

#### Scenario: Decidiq boots without OpenRegister

@e2e exclude boot-order behaviour; pinned by FlowNodeRegistrarTest
- **GIVEN** an instance where `RegisterFlowNodesEvent` does not exist
- **WHEN** decidiq registers
- **THEN** no flow listener is registered and nothing throws

#### Scenario: A step with no question is refused

@e2e exclude config validation; pinned by DecidiqRequestDecisionNodeTest
- **GIVEN** a `decidiq.request-decision` step whose config has no `question`
- **WHEN** the engine validates it
- **THEN** it is refused with a message saying what is being decided is missing

### Requirement: REQ-FRD-002 The decision is about the object the flow carries

On its first pass the node SHALL raise exactly one decision through
`DecisionIntegrationService::createDecision()`, with the subject taken from the
first item: `subjectRegister` and `subjectSchema` from the item's `@self`,
`subjectId` from `@self.id`, `id` or `uuid`, and `subjectLabel` from its title
or name. It SHALL record the returned ref, the time it asked, and the run's
acting identity in its resume slot, and SHALL suspend.

The decision's `sourceApp` SHALL be `decidiq-flow` and its `externalReference`
SHALL name the run and the node, so two decision steps on one object in one run
raise two decisions, and a retried raise finds the decision it already made.

#### Scenario: Requesting a decision suspends the run

@e2e exclude suspend path with no user-visible surface; pinned by DecidiqRequestDecisionNodeTest
- **GIVEN** a run carrying an object of register `pipelinq`, schema `lead`
- **WHEN** it reaches a `decidiq.request-decision` step
- **THEN** one decision is raised with that register, schema and object id as its subject
- **AND** the ref is recorded in the node's resume slot
- **AND** the run suspends with a reason naming the question

#### Scenario: Two decision steps on one object get two decisions

@e2e exclude idempotency key contract; pinned by RequestDecisionHeartbeatRecoveryTest
- **GIVEN** a run with two decision steps of the same type on the same object
- **WHEN** both are reached
- **THEN** each step holds a different decision ref

#### Scenario: An item with no identifiable object fails the step

@e2e exclude input guard; pinned by DecidiqRequestDecisionNodeTest
- **GIVEN** a first item with no id
- **WHEN** the step runs
- **THEN** it fails, and no decision is raised

### Requirement: REQ-FRD-003 The step fails closed

When the decision cannot be raised (OpenRegister unavailable, an unknown
decision type, a save that fails, or a result with no ref) the step SHALL fail.
It SHALL NOT suspend and SHALL NOT pass the items on, because a run that
proceeds past a decision nobody made is the outcome the step exists to prevent.

#### Scenario: An unavailable decision service does not become an approval

@e2e exclude fail-closed contract; pinned by DecidiqRequestDecisionNodeTest and FlowDecisionServiceTest
- **WHEN** the decision cannot be raised
- **THEN** the step fails with `decision_could_not_be_raised`
- **AND** the run does not continue past the decision

### Requirement: REQ-FRD-004 The step advances on its decision, not on a signal

On every pass after the first, the node SHALL read back the decision it raised,
scoped to the identity that raised it (falling back to the run's current acting
identity when the slot records none), and act on the state:

- decided: advance, with the outcome on every item;
- open: suspend again without touching the slot;
- withdrawn: fail naming the decision;
- gone: fail naming the decision;
- refused: fail naming the identity;
- unreadable: suspend again.

A run naming no acting identity SHALL suspend again and log rather than read.
The node SHALL NOT raise a second decision on a re-entry.

#### Scenario: A heartbeat delivers a conclusion whose announcement never arrived

@e2e exclude suspend and resume timing; pinned by RequestDecisionHeartbeatRecoveryTest
- **GIVEN** a run suspended on a decision decidiq has since concluded, whose wake never reached the run
- **WHEN** the heartbeat re-enters the step with no signal in hand
- **THEN** the node reads the decision, finds it decided, and advances with `recovered = true`

#### Scenario: A heartbeat with the decision still open parks again

@e2e exclude suspend and resume timing; pinned by RequestDecisionHeartbeatRecoveryTest
- **GIVEN** a run suspended on a decision decidiq has not concluded
- **WHEN** the heartbeat re-enters the step
- **THEN** the run suspends again on the same ref, no second decision is raised, and the asked-at time is unchanged

#### Scenario: A withdrawn decision fails the step

@e2e exclude failure branch; pinned by DecidiqRequestDecisionNodeTest and RequestDecisionHeartbeatRecoveryTest
- **GIVEN** a run suspended on a decision that was withdrawn
- **WHEN** the step re-enters
- **THEN** the step fails naming the decision

#### Scenario: An unreadable decision buys another heartbeat

@e2e exclude failure branch; pinned by DecidiqRequestDecisionNodeTest and FlowDecisionServiceTest
- **GIVEN** OpenRegister cannot resolve the decision for a moment
- **WHEN** the step re-enters
- **THEN** the run suspends again rather than failing

### Requirement: REQ-FRD-005 The output matches dossiq.requestDecision

The node SHALL write onto every item, under `signalKey` (default
`decisionOutcome`), an object with `decision` and `status` (decidiq's status
word), `decisionRef`, `node` (the step id), `decidedAt`, `signed` and
`recovered`. A wake's payload MAY add fields and SHALL NOT override these.

#### Scenario: The decision decides and the wake decorates

@e2e exclude value passed between flow steps; pinned by DecidiqRequestDecisionNodeTest
- **GIVEN** a decided decision with status `rejected`
- **WHEN** a wake claiming `approved` re-enters the step
- **THEN** the item carries `decision = rejected`

### Requirement: REQ-FRD-006 A concluded decision wakes the run that asked

When decidiq emits `DecisionConcludedEvent` for a decision with `sourceApp`
`decidiq-flow`, decidiq SHALL signal the suspended run whose node slot records
that decision ref, through OpenRegister's guarded `FlowRunSignalService`,
addressed to that node. A run waiting on a different decision SHALL stay
suspended. A failure to wake SHALL be logged and SHALL NOT affect the
concluded decision; the heartbeat recovers the run.

#### Scenario: The concluded decision wakes the waiting run

@e2e exclude cross-app resume; pinned by FlowDecisionConcludedListenerTest
- **GIVEN** a run suspended on decision `d-1`
- **WHEN** decidiq concludes `d-1`
- **THEN** that run is signalled, addressed to the node that asked

#### Scenario: An unrelated conclusion does not wake the run

@e2e exclude cross-app resume; pinned by FlowDecisionConcludedListenerTest
- **GIVEN** a run suspended on decision `d-1`
- **WHEN** decidiq concludes `d-2`, or a decision another app raised
- **THEN** the run is not signalled
