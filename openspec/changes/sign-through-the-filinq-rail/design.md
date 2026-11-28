# Design: sign through the filinq rail

## D1. The event, not an HTTP Source

ADR-041 makes typed events the fleet's cross-app command, and ADR-125 makes `DocumentSigningRequestedEvent` the only signing door. A server-to-server HTTP call through an integriq Source carries no user session and needs a Source nobody configures; the event runs in-process under the requesting user. decidiq reaches the class through `FleetAppId` (as for other optional sibling events), so the app has no hard dependency on filinq.

## D2. The outcome comes back as an event, matched on the request id

The listener receives `SigningConcludedEvent`, ignores any other `sourceApp`, and finds the stage by the signing request id stored at dispatch (`isHandled()`/`getSigningRequestId()` after the dispatch returns). It never matches on the minutes id alone, because a minutes record can be sent for signature more than once.

## D3. Fail closed stays the rule

If filinq is absent, or the dispatch returns with `isHandled() === false`, `initializeSigningRequest()` answers `success: false` with the reason, and no stage moves. That matches the current "No signing provider fails closed" scenario.

## D4. Live check before removal

filinq's design notes that nothing should depend on the dark path. That is confirmed on a live instance (task 1) before the Source code is deleted (task 4).

## Risks

- A deployment that configured an `eidas-qes` Source by hand loses that fallback. Task 1 looks for one, and the release notes name the change.
