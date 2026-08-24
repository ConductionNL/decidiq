---
kind: code
---

# Proposal: Decidesk Event Contract for Delegated Decisions

## Problem

Decidesk is the fleet **decision hub**: consumer apps (procest, softwarecatalog, shillinq) must
delegate governance decisions and contract sign-off to it. The previously-shipped delegation
(`decidesk-contract-decision-hub`, archived) reached decidesk over HTTP, and the consumer side was
wired through a **non-existent OpenRegister method** `IntegrationService::getLeaf`. That call always
threw, the consumers failed closed, and **the delegation never actually reached decidesk**. The HTTP
create-decision / outcome-poll surface still exists on decidesk and is correct, but there is no
in-process, autoloaded contract that consumers can dispatch against when decidesk is co-installed —
the common fleet deployment.

The fleet's chosen mechanism for in-process cross-app delegation is **Nextcloud's `IEventDispatcher`**
(event-dispatch): the producer dispatches a typed event, and any installed app that registered a
listener for it handles it synchronously. This is the same seam decidesk already uses to consume
OpenRegister's `ObjectCreatingEvent` / `ObjectCreatedEvent`. It is autoloaded when decidesk is
installed, requires no HTTP, no SSRF surface, and no broken `getLeaf` lookup.

## Proposed Change

Build **decidesk's side of the event contract** — two public event classes plus a listener — on top of
the existing `DecisionIntegrationService` (`createDecision()` + `getOutcomeEnvelope()`) and
`DecisionLifecycleService`. No new decision engine, no new state machine, no schema change.

### 1. Two public event classes (the cross-app CONTRACT)

Autoloaded under `OCA\Decidesk\Event\` whenever decidesk is installed; consumers reference these FQCNs
directly (they are the contract — see the spec for the verbatim payload shape):

- **`OCA\Decidesk\Event\DecisionRequestedEvent`** (extends `OCP\EventDispatcher\Event`) — a consumer
  dispatches this to ask decidesk to raise a governance Decision for one of its objects. Immutable
  constructor-injected getters: `getSourceApp()`, `getSubjectRegister()`, `getSubjectSchema()`,
  `getSubjectId()`, `getSubjectLabel()`, `getDecisionType()`, `getActorId()`, `getPayload(): array`,
  `getExternalReference()`, `getCorrelationId()`. After decidesk handles it, the producer can read the
  resolved `getDecisionId()` back off the same event instance (synchronous dispatch).
- **`OCA\Decidesk\Event\DecisionConcludedEvent`** (extends `Event`) — decidesk dispatches this when a
  provenance-carrying Decision reaches a terminal outcome. It carries the subject/provenance reference
  plus the outcome envelope (`status`, `outcome`, `signed`, `signingReference`, `signers`,
  `decisionId`, `decidedAt`). Consumers listen for it to post their own downstream side effects
  (shillinq GL post, procest ZGW advance) — decidesk owns the decision only (REQ-DCDH-007 carried
  forward).

### 2. Listener: `DecisionRequestedListener`

`OCA\Decidesk\Listener\DecisionRequestedListener` (implements `IEventListener`) maps a
`DecisionRequestedEvent` to `DecisionIntegrationService::createDecision($decisionData, $actorId)`
(positional args), building `$decisionData` from the event's subject reference + provenance +
payload + `externalReference`. `createDecision` already persists the provenance fields
(`sourceApp`/`subjectRegister`/`subjectSchema`/`subjectId`/`subjectLabel`/`outcomeCallbackUrl`/
`externalReference`) and is idempotent on the provenance tuple, so a re-dispatch correlates to the
same Decision. The resolved `decisionId` is written back onto the event for the synchronous producer.
Registered in `lib/AppInfo/Application.php` via
`$context->registerEventListener(DecisionRequestedEvent::class, DecisionRequestedListener::class)`.

### 3. Emit `DecisionConcludedEvent` from the lifecycle conclusion point

In `DecisionLifecycleService`, when a Decision that **carries provenance** (`sourceApp` is set)
transitions to a terminal outcome state (`decided`, `enacted`, or `withdrawn`), build the envelope via
`DecisionIntegrationService::getOutcomeEnvelope()` and dispatch a `DecisionConcludedEvent` through the
injected `IEventDispatcher`. Internal decisions with **no provenance** do NOT emit (no consumer is
waiting). Dispatch is fail-soft: an emission failure logs but never rolls back the persisted
transition.

## Impact

- **Added**: `lib/Event/DecisionRequestedEvent.php`, `lib/Event/DecisionConcludedEvent.php`,
  `lib/Listener/DecisionRequestedListener.php`, the change's spec/tasks.
- **Modified**: `lib/AppInfo/Application.php` (register listener + DI for `DecisionRequestedListener`,
  inject `DecisionIntegrationService` + `IEventDispatcher` into `DecisionLifecycleService`),
  `lib/Service/DecisionLifecycleService.php` (emit on terminal provenance transition).
- **No** schema change, **no** new user-facing strings (decidesk's strict 36-locale l10n parity gate
  stays green), **no** new HTTP route, **no** SSRF surface.
- **Behavioural delta**: when decidesk is co-installed, a consumer's `DecisionRequestedEvent` dispatch
  raises a Decision in-process; a concluded provenance-carrying Decision dispatches
  `DecisionConcludedEvent` that consumers consume. Pure addition — existing HTTP create/outcome
  surface and internal decision flow are unchanged.

## Dependencies

Builds on the archived `decidesk-contract-decision-hub` (provenance fields + `DecisionIntegrationService`
+ `getOutcomeEnvelope`, already shipped). Counterpart of the consumer-side changes
`procest-delegate-contract-decision` / `softwarecatalog-delegate-decision` / `shillinq-delegate-signing`,
which dispatch `DecisionRequestedEvent` and listen for `DecisionConcludedEvent`. ADR-022
(apps-consume-or-abstractions), ADR-031 (schema-declarative-business-logic — status stays derived, no
new state machine). Uses Nextcloud `OCP\EventDispatcher\IEventDispatcher` (event-dispatch), the fleet
mechanism replacing the broken `IntegrationService::getLeaf` HTTP path.
