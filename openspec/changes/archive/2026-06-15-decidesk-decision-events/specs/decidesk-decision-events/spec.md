# decidesk-decision-events Specification

**Status:** proposed
**Scope:** decidesk
**Tier:** integration / cross-app
**Depends on:** decidesk `decidesk-contract-decision-hub` (archived — provenance fields +
`DecisionIntegrationService::createDecision`/`getOutcomeEnvelope`), decidesk `decision-management`
(`DecisionLifecycleService`); Nextcloud `OCP\EventDispatcher\IEventDispatcher` (event-dispatch);
hydra ADR-022 (apps-consume-or-abstractions), ADR-031 (schema-declarative-business-logic).
Counterpart of `procest-delegate-contract-decision`, `softwarecatalog-delegate-decision`, and
`shillinq-delegate-signing`, which dispatch / listen on the event classes defined here.

## Purpose

Define the in-process **event contract** through which fleet consumer apps delegate governance
decisions to decidesk when both apps are installed, replacing the broken
`IntegrationService::getLeaf` HTTP delegation path. Consumers dispatch a `DecisionRequestedEvent` to
raise a Decision and listen for a `DecisionConcludedEvent` to consume the outcome. decidesk builds its
side of the contract entirely on top of the existing `DecisionIntegrationService` and
`DecisionLifecycleService` — no new decision engine, no new state machine, no schema change.

## Cross-app contract — verbatim FQCNs and payloads

Consumers MUST reference these exact fully-qualified class names (autoloaded whenever decidesk is
installed):

- **`OCA\Decidesk\Event\DecisionRequestedEvent`** — request fields (immutable getters):
  `sourceApp` (string), `subjectRegister` (string), `subjectSchema` (string), `subjectId` (string),
  `subjectLabel` (string), `decisionType` (string), `actorId` (string), `payload` (array),
  `externalReference` (string), `correlationId` (string). Result slot (set by decidesk during
  synchronous dispatch): `decisionId` (?string), `handled` (bool).
- **`OCA\Decidesk\Event\DecisionConcludedEvent`** — immutable fields: subject reference
  (`sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`, `externalReference`,
  `correlationId`) plus the outcome envelope (`decisionId`, `decisionType`, `status`, `outcome`,
  `signed`, `signingReference`, `signers`, `decidedAt`).

## ADDED Requirements

### Requirement: REQ-DDE-001 — Public DecisionRequestedEvent contract class

The system SHALL provide an autoloaded public event class `OCA\Decidesk\Event\DecisionRequestedEvent`
extending `OCP\EventDispatcher\Event`, available whenever decidesk is installed, that a consumer app
dispatches to ask decidesk to raise a governance Decision for one of its objects. The event SHALL
expose immutable getters for `sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`,
`subjectLabel`, `decisionType`, `actorId`, `payload` (array), `externalReference`, and
`correlationId`, all supplied at construction. Because Nextcloud typed dispatch is synchronous, the
event SHALL carry a single writable result slot — `setDecisionId(string)` / `getDecisionId(): ?string`
and `setHandled(bool)` / `isHandled(): bool` — that decidesk's listener writes so the dispatching
producer can read the resolved `decisionId` back off the same instance. The request getters SHALL NOT
be mutable.

#### Scenario: A consumer dispatches a request and reads back the decision id

- GIVEN procest holds a ZGW case requiring a contract decision
- WHEN procest constructs a `DecisionRequestedEvent` with `sourceApp=procest`, the case subject
  reference, `decisionType=contract`, an `actorId`, and an `externalReference`, and dispatches it via
  `IEventDispatcher`
- THEN after dispatch the event's `getDecisionId()` returns the id of the Decision decidesk created
  and `isHandled()` is true

#### Scenario: Request getters are immutable

- GIVEN a constructed `DecisionRequestedEvent`
- WHEN its request fields are read through the getters
- THEN the values equal those supplied at construction and the class exposes no setter for any
  request field (only the `decisionId` / `handled` result slot is writable)

---

### Requirement: REQ-DDE-002 — Public DecisionConcludedEvent contract class

The system SHALL provide an autoloaded public event class `OCA\Decidesk\Event\DecisionConcludedEvent`
extending `OCP\EventDispatcher\Event` that decidesk dispatches when a delegated Decision reaches a
terminal outcome. The event SHALL carry, all immutable, the subject/provenance reference (`sourceApp`,
`subjectRegister`, `subjectSchema`, `subjectId`, `externalReference`, `correlationId`) and the outcome
envelope (`decisionId`, `decisionType`, `status`, `outcome`, `signed`, `signingReference`, `signers`,
`decidedAt`), where `status` is the value derived by `DecisionIntegrationService::getOutcomeEnvelope()`
(no new state machine, ADR-031). Consumers SHALL listen for this event to perform their own downstream
side effects.

#### Scenario: Concluded event exposes the outcome envelope to consumers

- GIVEN decidesk dispatches a `DecisionConcludedEvent` for a concluded delegated Decision
- WHEN a consumer's listener reads the event
- THEN it can read the subject reference and the full outcome envelope (`status`, `outcome`, `signed`,
  `signingReference`, `signers`, `decisionId`, `decidedAt`) without any further query to decidesk

---

### Requirement: REQ-DDE-003 — Listener maps a requested event to createDecision

The system SHALL register a listener `OCA\Decidesk\Listener\DecisionRequestedListener` (implementing
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

- GIVEN decidesk is installed and a consumer dispatches a `DecisionRequestedEvent` with a complete
  subject reference and provenance
- WHEN the listener handles it
- THEN `DecisionIntegrationService::createDecision` persists a Decision with the provenance fields set
  and the event's `decisionId` result slot holds the created id

#### Scenario: Re-dispatch for the same subject is idempotent

- GIVEN a Decision already exists for a consumer's provenance tuple
- WHEN the consumer dispatches a `DecisionRequestedEvent` for the same tuple again
- THEN the listener returns the existing `decisionId` and no duplicate Decision is created

#### Scenario: Service failure does not throw into the dispatcher

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

- GIVEN a Decision raised by a consumer (with `sourceApp` set) is transitioned to `decided`
- WHEN the lifecycle transition persists successfully
- THEN decidesk builds the outcome envelope via `getOutcomeEnvelope()` and dispatches a
  `DecisionConcludedEvent` carrying that envelope and the subject reference

#### Scenario: An internal decision does not emit

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
