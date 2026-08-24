# Design: Decidesk Event Contract for Delegated Decisions

## Context

`decidesk-contract-decision-hub` (archived 2026-06-14) shipped the provenance fields on the `Decision`
schema and the `DecisionIntegrationService` (`createDecision()`, `getOutcomeEnvelope()`,
`registerOutcomeCallback()`) plus the HTTP create/outcome/subscription routes. The consumer side was
meant to call decidesk through OpenRegister's integration registry, but the consumer changes resolved
the leaf via a method that does not exist on any OR service —
`OCA\OpenRegister\Service\IntegrationService::getLeaf`. Every delegation attempt threw and failed
closed, so decidesk was never reached.

This change adds the **in-process event contract** that the fleet standardised on for cross-app
delegation when both apps are installed: Nextcloud's `IEventDispatcher`. It does not touch the HTTP
surface (kept for out-of-process / remote consumers) and does not rebuild any decision logic.

## Goals

- Two stable, autoloaded public event FQCNs that consumers can dispatch / listen on with zero HTTP.
- Reuse `DecisionIntegrationService::createDecision()` (positional args) and `getOutcomeEnvelope()` —
  no parallel create/outcome code.
- Emit conclusion events ONLY for provenance-carrying (delegated) Decisions; internal decisions stay
  silent.
- Fail-soft: a dispatch failure never breaks the OR write path or rolls back a persisted transition.
- No new user-facing strings (strict 36-locale l10n parity gate).

## Decisions

### D1 — Events are immutable value objects with a single mutable result slot

`DecisionRequestedEvent` is constructed with all request data (immutable getters). Because NC
`IEventDispatcher::dispatchTyped()` is **synchronous**, the listener writes the resolved `decisionId`
(and a `handled` flag) back onto the same instance via `setDecisionId()`; the producer reads it
immediately after dispatch. This is the standard NC pattern for request/response over the event bus
(mirrors how `ObjectCreatingEvent` lets a listener call `setErrors()`/`stopPropagation()`). All
*request* fields stay read-only — only the result slot is writable.

`DecisionConcludedEvent` is fully immutable: decidesk constructs it from the outcome envelope and the
subject reference; consumers only read.

### D2 — Listener delegates to `createDecision`, never re-implements CRUD (ADR-022)

`DecisionRequestedListener::handle()` builds the `$decisionData` array the existing service expects
(`decisionType`, `title`, `text`, `decisionDate`, `outcome`, plus the provenance block from the event)
and calls `createDecision($decisionData, $actorId)` **positionally**. The service already validates the
`decisionType` enum, enforces idempotency on the provenance tuple, persists provenance, and appends the
audit entry. The listener adds no persistence of its own. On a non-success service result the listener
logs and leaves the event unhandled (no exception escapes into the dispatcher).

### D3 — Conclusion emission lives at the single lifecycle terminal point

`DecisionLifecycleService::transition()` is the one place a Decision changes lifecycle. After a
successful `saveObject`, when the **post-transition** state is terminal for outcome purposes
(`decided`, `enacted`, `withdrawn`) AND the decision carries `sourceApp` (provenance), the service:

1. calls `DecisionIntegrationService::getOutcomeEnvelope($decisionId)` to build the canonical envelope
   (status derived, signing info resolved — no duplication of that logic here), and
2. dispatches a `DecisionConcludedEvent` carrying the envelope + subject reference via the injected
   `IEventDispatcher`.

`withdrawn` is not a state in `DecisionTransitionGuard::TRANSITIONS` (the guard graph ends at
`archived`), but it IS a recognised `lifecycle` value that `getOutcomeEnvelope()` maps to
`status=withdrawn`; the emission set therefore lists all three terminal-outcome lifecycles so a
withdrawal raised through any path that reaches `transition()` still notifies consumers. Emission is
wrapped in try/catch and logged on failure — the transition is already persisted and must not roll back
(matches `generateResolutionRecord`'s fail-loud-not-rollback contract in the same method).

### D4 — Provenance gate prevents internal-decision noise

The vast majority of decidesk Decisions are internal (board motions, council resolutions) with no
`sourceApp`. Emitting `DecisionConcludedEvent` for those would fire a no-op event on every internal
conclusion. The `sourceApp !== null/''` guard scopes emission to delegated decisions only, which is
also the REQ-DCDH-007 boundary (decidesk owns the decision, the consumer owns the side effect).

### D5 — DI wiring

- `DecisionRequestedListener` registered as a DI service (container + logger +
  `DecisionIntegrationService`) and bound to `DecisionRequestedEvent` via `registerEventListener`.
- `DecisionLifecycleService` gains two constructor params: `DecisionIntegrationService` (already a DI
  service) and `OCP\EventDispatcher\IEventDispatcher` (NC core). Both appended to the existing
  `registerService` closure. `DecisionIntegrationService` itself is registered as a DI service in this
  change (it was previously resolved lazily via the container in the HTTP controller path; an explicit
  registration makes it injectable).

## Alternatives considered

- **Keep HTTP-only and fix `getLeaf` on the consumer side** — rejected: the registry-HTTP path adds an
  SSRF surface, network failure modes, and a remote dependency for the common co-installed case; the
  event bus is the fleet-standard in-process seam and is already used by decidesk.
- **Asynchronous (queued) events** — rejected: the producer needs the `decisionId` back synchronously
  to link its own object; NC typed dispatch is synchronous and sufficient.
- **A new `DecisionEventService`** — rejected: that would be a redundant wrapper; the listener and the
  one emit call live where the data already is.

## Risks

- Consumers must register their listener for `DecisionConcludedEvent` to receive conclusions — out of
  scope here (owned by the consumer changes); decidesk fires the event regardless.
- If `getOutcomeEnvelope` returns null (deleted/unreadable decision), the emit is skipped (guarded);
  acceptable — there is nothing to report.
