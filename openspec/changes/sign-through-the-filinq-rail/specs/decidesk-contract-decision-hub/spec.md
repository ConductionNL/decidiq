# decidesk-contract-decision-hub (delta)

## MODIFIED Requirements

### Requirement: REQ-DCDH-005 — Signature method delegates document signature to filinq

For a Decision stage with `method=signature`, the system SHALL delegate the actual document
e-signature to **filinq**, the fleet's signing rail (hydra ADR-125), by dispatching filinq's
`DocumentSigningRequestedEvent` with the stage's subject (register, schema, id), the file id of the
`signedDocument` PDF, the signatories decidiq resolved in signing order, and the signature level. The
system SHALL store the returned signing request id on the signature stage. decidiq keeps deciding who
may sign and when (signatory resolution, approval route, `QesGuard`). When filinq's
`SigningConcludedEvent` reports that request `signed`, the `EIDASSignatureService` stage-resolution seam
(`resolveSignatureStage()`) SHALL resolve the stage (`outcome=adopted` + `decidedAt` + link the signed
document). A `declined`, `expired` or `cancelled` outcome SHALL leave the stage unresolved and record
the reason. The system SHALL NOT reach a signing service through an integriq or openconnector Source.
The signature method SHALL fail **closed** (the stage stays unresolved) when filinq is not installed or
does not handle the request; it SHALL NOT silently mark a document signed. decidiq SHALL NOT implement
its own document e-signature engine.

#### Scenario: A signature stage asks filinq to sign

- GIVEN a `method=signature` stage on a contract Decision with a `signedDocument` and signatories
- WHEN the signature method runs with filinq installed
- THEN decidiq dispatches `DocumentSigningRequestedEvent` naming the stage's subject, the PDF and the
  signatories in order, and stores the returned signing request id on the stage

#### Scenario: Completed filinq signing resolves the stage

- GIVEN filinq dispatches `SigningConcludedEvent` with status `signed` for that signing request id
- WHEN decidiq's listener handles it
- THEN the signature stage is set `outcome=adopted` with `decidedAt`, the signed document is linked,
  and the outcome envelope reports `signed=true` with the signing reference

#### Scenario: A declined signing leaves the stage open

@e2e exclude backend integration contract — covered by PHPUnit on the listener

- GIVEN filinq dispatches `SigningConcludedEvent` with status `declined` for that signing request id
- WHEN decidiq's listener handles it
- THEN the stage stays unresolved and records that the signing was declined

#### Scenario: No signing provider fails closed

@e2e exclude backend integration contract — no-provider failure path is covered by PHPUnit, not a UI flow

- GIVEN filinq is not installed, or does not handle the request
- WHEN a `method=signature` stage is run
- THEN the stage stays unresolved and the document is NOT marked signed
