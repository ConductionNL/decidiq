# resolution-minutes Specification

## ADDED Requirements

### Requirement: Minutes signing resolves a signature-method stage

When a `DecisionStage` has `method=signature`, the eIDAS signing of its `signedDocument` SHALL reuse the existing minutes signing flow — signatories are read from `Minutes.signedBy` and the QES workflow is driven by `EIDASSignatureService`. On signing completion, the service SHALL resolve the related signature stage (link `signedDocument`, set `outcome=adopted` + `decidedAt`). No separate Signature schema SHALL be introduced; the signed artefact remains a `DigitalDocument` and the signatories remain `Minutes.signedBy`, consistent with ADR-006's retirement of parallel board-* entities.

#### Scenario: Signed minutes resolve the ratifying signature stage

- **GIVEN** a `method=signature` DecisionStage whose `signedDocument` is the meeting minutes and whose signatories are listed in `Minutes.signedBy`
- **WHEN** the chair and secretary complete eIDAS signing
- **THEN** `EIDASSignatureService` resolves the stage to `outcome=adopted` with `decidedAt` stamped, reusing the minutes signing flow rather than a new signature entity
