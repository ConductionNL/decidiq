# Tasks: Decidesk Event Contract for Delegated Decisions

## 1. Public event classes (the cross-app contract)

- [x] 1.1 Add `lib/Event/DecisionRequestedEvent.php` (`OCA\Decidesk\Event`, extends
  `OCP\EventDispatcher\Event`): constructor-injected immutable getters `getSourceApp`,
  `getSubjectRegister`, `getSubjectSchema`, `getSubjectId`, `getSubjectLabel`, `getDecisionType`,
  `getActorId`, `getPayload(): array`, `getExternalReference`, `getCorrelationId`; writable result
  slot `setDecisionId`/`getDecisionId(): ?string` + `setHandled`/`isHandled(): bool`. SPDX + PHPDoc
  headers; `@spec` tag. (REQ-DDE-001)
- [x] 1.2 Add `lib/Event/DecisionConcludedEvent.php` (`OCA\Decidesk\Event`, extends `Event`):
  immutable subject reference (`sourceApp`/`subjectRegister`/`subjectSchema`/`subjectId`/
  `externalReference`/`correlationId`) + outcome envelope getters (`decisionId`, `decisionType`,
  `status`, `outcome`, `signed`, `signingReference`, `signers`, `decidedAt`); a
  `fromEnvelope()` static factory that constructs the event from a `getOutcomeEnvelope()` array +
  subject reference. SPDX + PHPDoc; `@spec` tag. (REQ-DDE-002)

## 2. Listener: requested -> createDecision

- [x] 2.1 Add `lib/Listener/DecisionRequestedListener.php` (`OCA\Decidesk\Listener`, implements
  `IEventListener`): in `handle()`, guard `instanceof DecisionRequestedEvent`, build the
  `$decisionData` array from the event (provenance block + `decisionType` + `externalReference` +
  request `payload` keys `title`/`text`/`decisionDate`/`outcome`), call
  `DecisionIntegrationService::createDecision($decisionData, $actorId)` **positionally**; on success
  `setDecisionId` + `setHandled(true)`, on failure log and leave unhandled; never throw. SPDX +
  PHPDoc; `@spec` tag. (REQ-DDE-003)
- [x] 2.2 Register `DecisionIntegrationService` as a DI service and `DecisionRequestedListener` as a
  DI service in `lib/AppInfo/Application.php`, and bind the listener via
  `$context->registerEventListener(DecisionRequestedEvent::class, DecisionRequestedListener::class)`.
  (REQ-DDE-003)

## 3. Emit conclusion event from the lifecycle terminal point

- [x] 3.1 Inject `DecisionIntegrationService` + `OCP\EventDispatcher\IEventDispatcher` into
  `DecisionLifecycleService` (constructor + the `registerService` closure in `Application.php`).
- [x] 3.2 In `DecisionLifecycleService::transition()`, after the successful `saveObject`, when the
  post-transition state is `decided`/`enacted`/`withdrawn` AND the decision carries a non-empty
  `sourceApp`, build the envelope via `getOutcomeEnvelope($decisionId)` and dispatch a
  `DecisionConcludedEvent`. Guard the whole emit in try/catch (fail-soft, log on failure, never roll
  back the transition). Do NOT emit for no-provenance decisions. (REQ-DDE-004)

## 4. Verification

- [x] 4.1 `php -l` every new/changed PHP file; `openspec validate decidesk-decision-events --strict`
  passes.
- [x] 4.2 Run the hydra mechanical gates (spdx, forbidden-patterns, stub-scan, spec-coverage on
  changed methods, etc.); report results, fixing any real issue introduced. No new user-facing
  English strings (strict 36-locale l10n parity gate stays green).
- [x] 4.3 Confirm the two event FQCNs + payload fields match the spec contract verbatim so the
  consumer changes can rely on them.
