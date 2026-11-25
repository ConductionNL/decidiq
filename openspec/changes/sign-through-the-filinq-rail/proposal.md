# Sign through the filinq rail

## Why

Ruben decided on 2026-11-25 (hydra ADR-125, filinq `one-signing-rail-for-the-fleet` D1, option c): the domain app decides who may sign and when, and filinq performs the signature through its rail. decidiq's `EIDASSignatureService::initializeSigningRequest()` now reaches for two integriq Sources: `docudesk-signing` (preferred) and `eidas-qes` (fallback). Neither Source exists and integriq ships no e-sign adapter, so the path is dark. The filinq branch fails closed, and the fallback ends in an audit-log stub (`LogEIDASSignatureService`). Meanwhile filinq has a working cross-app door, `DocumentSigningRequestedEvent` in and `SigningConcludedEvent` out, which shillinq already uses.

## What changes

- `initializeSigningRequest()` dispatches filinq's `DocumentSigningRequestedEvent` (through `FleetAppId`, so decidiq still boots without filinq). The event carries the subject register, schema and id, the PDF's file id, the ordered signers decidiq resolved and the signature level. It replaces both Source hops.
- A new listener for filinq's `SigningConcludedEvent`, filtered on `sourceApp=decidiq`, calls the existing `resolveSignatureStage()` with the signing request id and links the signed document. `declined`, `expired` and `cancelled` leave the stage unresolved and record why.
- Removed: the `docudesk-signing` and `eidas-qes` Source lookups (`integriqSource()`, `docudeskSource()`, `invokeOpenconnector()`, `composeDocudeskSigningRequest()`, `decodeDocudeskResponse()`), `FilinqSigningRequest::ENDPOINT` and the Source-dependent branch in `DomainServiceRegistrar`.
- Without filinq, a signature stage fails closed as today: it stays unresolved and nothing is marked signed.
- Who may sign stays in decidiq: signatory resolution, approval routes and `QesGuard` are unchanged.

## Not in this change

- Signature levels and providers. filinq configures them, once, for every app.
- Minutes signing order UI (REQ-SES-001). Only the transport behind it changes.

## Pair

filinq `one-signing-rail-for-the-fleet` task 7.3; dossiq `sign-through-the-filinq-rail` (task 7.2).
