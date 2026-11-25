# Tasks: sign-through-the-filinq-rail

- [ ] 1. On a live instance with decidiq and filinq, confirm that no Source with slug `docudesk-signing` or `eidas-qes` is configured and nothing calls the dark path; note the result in the PR body
  - spec_ref: `specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-005-signature-method-delegates-document-signature-to-filinq`
- [ ] 2. `initializeSigningRequest()` dispatches filinq's `DocumentSigningRequestedEvent` (via `FleetAppId`) with subject register/schema/id, the PDF file id from `FilinqSigningRequest`, the ordered signers and the signature level; it stores the returned signing request id on the stage; without filinq, or when not handled, it answers `success: false`
  - files: `lib/Service/EIDASSignatureService.php`, `lib/Service/FilinqSigningRequest.php`, `tests/Unit/Service/EIDASSignatureServiceTest.php`
- [ ] 3. A listener for filinq's `SigningConcludedEvent` (only `sourceApp=decidiq`) resolves the stage on `signed` through `resolveSignatureStage()` and links the signed document; `declined`, `expired` and `cancelled` leave it unresolved with the reason
  - files: `lib/Listener/SigningConcludedListener.php`, `lib/AppInfo/Application.php`, `tests/Unit/Listener/SigningConcludedListenerTest.php`
- [ ] 4. Remove the Source hops: `integriqSource()`, `docudeskSource()`, `invokeOpenconnector()`, `composeDocudeskSigningRequest()`, `decodeDocudeskResponse()`, `FilinqSigningRequest::ENDPOINT`, the Source branch in `DomainServiceRegistrar`; `LogEIDASSignatureService` stays as the no-filinq answer
  - files: `lib/Service/EIDASSignatureService.php`, `lib/Service/Registrar/DomainServiceRegistrar.php`, `lib/Service/LogEIDASSignatureService.php`
- [ ] 5. Docs: the signing section names filinq as the signer and decidiq as the one who decides who signs (ADR-125); Dutch and English strings for any changed message
