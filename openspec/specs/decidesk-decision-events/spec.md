# decidesk-decision-events Specification

## Purpose
The in-process event contract through which another installed fleet app asks decidiq for a decision and learns how it ended. A typed request event makes decidiq create the decision, a conclusion event reports the terminal outcome back to the requester, and a state-read event lets the requester ask where a decision stands, answered through the same authorization guard as every other read and never with elevated rights (ADR-041).

## Requirements

### Requirement: REQ-DDE-001 — Public DecisionRequestedEvent contract class

The system SHALL provide an autoloaded public event class `OCA\Decidiq\Event\DecisionRequestedEvent`
extending `OCP\EventDispatcher\Event`, available whenever decidiq is installed, that a consumer app
dispatches to ask decidiq to raise a governance Decision for one of its objects. The event SHALL
expose immutable getters for `sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`,
`subjectLabel`, `decisionType`, `actorId`, `payload` (array), `externalReference`, and
`correlationId`, all supplied at construction. Because Nextcloud typed dispatch is synchronous, the
event SHALL carry a single writable result slot — `setDecisionId(string)` / `getDecisionId(): ?string`
and `setHandled(bool)` / `isHandled(): bool` — that decidiq's listener writes so the dispatching
producer can read the resolved `decisionId` back off the same instance. The request getters SHALL NOT
be mutable.

#### Scenario: A consumer dispatches a request and reads back the decision id

@e2e exclude backend cross-app event contract — synchronous DecisionRequestedEvent dispatch + decisionId read-back is verified by PHPUnit; no decidiq UI flow exercises it
- GIVEN procest holds a ZGW case requiring a contract decision
- WHEN procest constructs a `DecisionRequestedEvent` with `sourceApp=procest`, the case subject
  reference, `decisionType=contract`, an `actorId`, and an `externalReference`, and dispatches it via
  `IEventDispatcher`
- THEN after dispatch the event's `getDecisionId()` returns the id of the Decision decidiq created
  and `isHandled()` is true

#### Scenario: Request getters are immutable

@e2e exclude pure value-object invariant — immutability of the event getters is verified by PHPUnit; not a UI flow
- GIVEN a constructed `DecisionRequestedEvent`
- WHEN its request fields are read through the getters
- THEN the values equal those supplied at construction and the class exposes no setter for any
  request field (only the `decisionId` / `handled` result slot is writable)

---

### Requirement: REQ-DDE-002 — Public DecisionConcludedEvent contract class

The system SHALL provide an autoloaded public event class `OCA\Decidiq\Event\DecisionConcludedEvent`
extending `OCP\EventDispatcher\Event` that decidiq dispatches when a delegated Decision reaches a
terminal outcome. The event SHALL carry, all immutable, the subject/provenance reference (`sourceApp`,
`subjectRegister`, `subjectSchema`, `subjectId`, `externalReference`, `correlationId`) and the outcome
envelope (`decisionId`, `decisionType`, `status`, `outcome`, `signed`, `signingReference`, `signers`,
`decidedAt`), where `status` is the value derived by `DecisionIntegrationService::getOutcomeEnvelope()`
(no new state machine, ADR-031). Consumers SHALL listen for this event to perform their own downstream
side effects.

#### Scenario: Concluded event exposes the outcome envelope to consumers

@e2e exclude backend event-payload contract — the DecisionConcludedEvent outcome-envelope getters are verified by PHPUnit; consumers read them in-process, no UI flow
- GIVEN decidiq dispatches a `DecisionConcludedEvent` for a concluded delegated Decision
- WHEN a consumer's listener reads the event
- THEN it can read the subject reference and the full outcome envelope (`status`, `outcome`, `signed`,
  `signingReference`, `signers`, `decisionId`, `decidedAt`) without any further query to decidiq

---

### Requirement: REQ-DDE-003 — Listener maps a requested event to createDecision

The system SHALL register a listener `OCA\Decidiq\Listener\DecisionRequestedListener` (implementing
`OCP\EventDispatcher\IEventListener`) bound to `DecisionRequestedEvent` via
`registerEventListener(DecisionRequestedEvent::class, DecisionRequestedListener::class)` in
`lib/AppInfo/Application.php`. On handling, the listener SHALL build the decision-data array from the
event (subject reference, provenance, `decisionType`, `externalReference`, and the request `payload`)
and SHALL call `DecisionIntegrationService::createDecision($decisionData, $actorId)` with **positional**
arguments — reusing the existing idempotent, provenance-persisting create logic (ADR-022, no parallel
CRUD). On a successful result the listener SHALL write the returned `decisionId` and a `handled=true`
flag back onto the event; on a non-success result it SHALL log and leave the event unhandled, and no
exception SHALL escape into the dispatcher.

#### Scenario: Requested event creates a provenance-carrying decision

@e2e exclude backend listener contract — DecisionRequestedListener -> createDecision provenance persistence is verified by PHPUnit; not a decidiq UI flow
- GIVEN decidiq is installed and a consumer dispatches a `DecisionRequestedEvent` with a complete
  subject reference and provenance
- WHEN the listener handles it
- THEN `DecisionIntegrationService::createDecision` persists a Decision with the provenance fields set
  and the event's `decisionId` result slot holds the created id

#### Scenario: Re-dispatch for the same subject is idempotent

@e2e exclude backend idempotency contract — re-dispatch returning the existing decisionId is verified by PHPUnit; not a UI flow
- GIVEN a Decision already exists for a consumer's provenance tuple
- WHEN the consumer dispatches a `DecisionRequestedEvent` for the same tuple again
- THEN the listener returns the existing `decisionId` and no duplicate Decision is created

#### Scenario: Service failure does not throw into the dispatcher

@e2e exclude backend fail-soft contract — listener leaving the event unhandled without throwing is verified by PHPUnit; not a UI flow
- GIVEN `createDecision` returns an unsuccessful result (e.g. unrecognised `decisionType`)
- WHEN the listener handles the event
- THEN the event is left unhandled (`isHandled()` false, `getDecisionId()` null) and no exception
  propagates out of the listener

---

### Requirement: REQ-DDE-004 — Emit DecisionConcludedEvent on a delegated terminal transition

The system SHALL dispatch a `DecisionConcludedEvent` from `DecisionLifecycleService` when a Decision
that carries provenance (`sourceApp` set and non-empty) transitions to a terminal outcome lifecycle —
`decided`, `enacted`, or `withdrawn`. The envelope SHALL be built by calling
`DecisionIntegrationService::getOutcomeEnvelope()` for the transitioned decision (reusing the derived
status + resolved signing info — no duplication), and dispatched via the injected
`OCP\EventDispatcher\IEventDispatcher`. The system SHALL NOT emit the event for internal decisions
that carry no provenance. The dispatch SHALL be fail-soft: a dispatch failure SHALL be logged and
SHALL NOT roll back the already-persisted lifecycle transition.

#### Scenario: A concluded delegated decision emits the event

@e2e exclude backend lifecycle-emission contract — DecisionConcludedEvent dispatch on a provenance-carrying terminal transition is verified by PHPUnit; not a UI flow
- GIVEN a Decision raised by a consumer (with `sourceApp` set) is transitioned to `decided`
- WHEN the lifecycle transition persists successfully
- THEN decidiq builds the outcome envelope via `getOutcomeEnvelope()` and dispatches a
  `DecisionConcludedEvent` carrying that envelope and the subject reference

#### Scenario: An internal decision does not emit

@e2e exclude backend no-provenance guard — suppressing emission for sourceApp-less decisions is verified by PHPUnit; not a UI flow
- GIVEN a Decision with no `sourceApp` (an internal board/council decision) is transitioned to
  `enacted`
- WHEN the transition persists
- THEN no `DecisionConcludedEvent` is dispatched

#### Scenario: Emission failure does not roll back the transition

@e2e exclude backend integration contract — fail-soft emission path is covered by PHPUnit, not a UI flow

- GIVEN a delegated Decision is transitioned to a terminal state and the event dispatch raises
- WHEN the failure occurs after the lifecycle write has persisted
- THEN the transition remains persisted, the failure is logged, and the caller still receives a
  successful transition result

### Requirement: REQ-DDE-005 — Public DecisionStateRequestedEvent contract class

The system SHALL provide an autoloaded public event class
`OCA\Decidiq\Event\DecisionStateRequestedEvent` extending `OCP\EventDispatcher\Event`, available
whenever decidiq is installed, that a consumer app dispatches to ask decidiq what became of a
Decision it already raised. The event SHALL expose immutable getters for `sourceApp`, `decisionId`
and `actorId`, all supplied at construction. Because Nextcloud typed dispatch is synchronous, the
event SHALL carry result slots — `setHandled(bool)` / `isHandled(): bool`, `setPermitted(bool)` /
`isPermitted(): bool`, `setFound(bool)` / `isFound(): bool` and `setEnvelope(array)` /
`getEnvelope(): ?array`, plus a derived `getStatus(): ?string` reading the envelope's status — that
decidiq's listener writes so the dispatching producer can read the answer back off the same
instance. The request getters SHALL NOT be mutable.

The event SHALL NOT be a second delivery mechanism for a conclusion: `DecisionConcludedEvent`
(REQ-DDE-002 / REQ-DDE-004) remains how a concluded Decision reaches a consumer, and this event
exists for the case where that announcement was missed.

#### Scenario: A consumer reads back the decision it raised

@e2e exclude backend cross-app event contract — synchronous DecisionStateRequestedEvent dispatch + envelope read-back is verified by PHPUnit; no decidiq UI flow exercises it
- GIVEN a consumer app holds the `decisionId` decidiq returned when it raised a Decision
- WHEN it constructs a `DecisionStateRequestedEvent` with that id and the identity that raised it,
  and dispatches it via `IEventDispatcher`
- THEN after dispatch `isHandled()` is true, `isPermitted()` is true, `isFound()` is true and
  `getEnvelope()` holds the outcome envelope

#### Scenario: Three different negative answers are distinguishable

@e2e exclude backend value-object contract — the handled/permitted/found slot combinations are verified by PHPUnit; not a UI flow
- GIVEN a consumer dispatches a state read
- WHEN decidiq could not resolve the Decision at all, when the Decision does not exist, and when the
  caller may not read it
- THEN the three answers are respectively `handled=false`; `handled=true, permitted=true,
  found=false`; and `handled=true, permitted=false`, so a consumer can tell "ask me again" from
  "stop waiting" from "you may not see this"

---

### Requirement: REQ-DDE-006 — Listener answers a state read from the existing envelope and the existing guard

The system SHALL register a listener `OCA\Decidiq\Listener\DecisionStateRequestedListener`
(implementing `OCP\EventDispatcher\IEventListener`) bound to `DecisionStateRequestedEvent` in
`CrossAppEventRegistrar::COMMANDS`. On handling, the listener SHALL derive the reported state by
calling `DecisionIntegrationService::getOutcomeEnvelope()` — it SHALL NOT derive a status of its own
— and SHALL authorize the read through `DecisionIntegrationAuthorizationGuard` (REQ-DCDH-101),
SHALL NOT restate that rule. No exception SHALL escape into the dispatcher.

The listener SHALL leave the event UNHANDLED when the read could not be resolved, and SHALL mark it
handled in every case where it produced an answer — including a refusal and a miss. A Decision that
does not exist SHALL be reported as `found=false` with `permitted=true`, mirroring the endpoint's
choice to answer 404 rather than turn a 403 into an existence oracle.

#### Scenario: The reported status is the announced status

@e2e exclude backend derivation-reuse contract — verified by PHPUnit over the real service; not a UI flow
- GIVEN a delegated Decision has concluded with `lifecycle=decided` and `outcome=adopted`
- WHEN a consumer reads its state back through the seam
- THEN the reported status is `approved` — the same value `DecisionConcludedEvent` carried — and the
  envelope is the same array `getOutcomeEnvelope()` builds

#### Scenario: A withdrawn decision is reported as withdrawn, not as rejected

@e2e exclude backend derivation contract — verified by PHPUnit; not a UI flow
- GIVEN a delegated Decision was withdrawn
- WHEN a consumer reads its state back
- THEN the reported status is `withdrawn`, so the consumer can refuse to proceed rather than treat
  it as a decision against the thing

#### Scenario: An unreachable store is not reported as a refusal

@e2e exclude backend failure-mode contract — verified by PHPUnit; not a UI flow
- GIVEN OpenRegister cannot be resolved when a state read arrives
- WHEN the listener handles it
- THEN the event is left unhandled, so the consumer waits and retries rather than failing its run on
  an authorization error it never had

---

### Requirement: REQ-DDE-007 — A state read is scoped to a named actor and never elevated

The system SHALL authorize a `DecisionStateRequestedEvent` AS the uid the event names. An event
carrying an empty `actorId`, or an empty `decisionId`, SHALL be refused — marked handled with
`permitted=false` — and SHALL NOT be treated as a system, anonymous or administrator caller. There
SHALL be no administrator bypass on this path.

`DecisionIntegrationAuthorizationGuard` SHALL expose `resolveOutcomeReadAccess()` reporting
`allowed`, `denied` or `unresolved`, and `isAuthorizedToReadOutcome()` SHALL delegate to it so the
HTTP path and the event path apply one rule. The boolean's collapse of `unresolved` onto `false`
(fail closed) SHALL be unchanged.

#### Scenario: A nameless caller is refused rather than elevated

@e2e exclude backend authorization contract — verified by PHPUnit; not a UI flow
- GIVEN a `DecisionStateRequestedEvent` is dispatched with an empty `actorId`
- WHEN the listener handles it
- THEN the event is marked handled with `permitted=false` and carries no envelope

#### Scenario: A caller who did not raise the decision learns nothing about it

@e2e exclude backend authorization contract — verified by PHPUnit; not a UI flow
- GIVEN an internal (unpublished) Decision owned by another identity
- WHEN a different actor reads its state through the seam
- THEN `permitted` is false, `found` is false, and neither the envelope nor the status is reported
