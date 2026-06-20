---
status: done
---

# decision-methods Specification

## Purpose
Defines how each decision stage is resolved through a method enum (manual, vote, signature, chair-register, advice) with the typed mechanism relation matching its method. Vote-method outcomes are derived declaratively from the linked voting round, chair-register/advice/manual outcomes are set directly by the actor, and signature stages are resolved via eIDAS signing of the referenced document — available to any decision regardless of organisation mode.
## Requirements
### Requirement: Decision method enum and mechanism relations

Each `DecisionStage` SHALL declare HOW it is resolved via a `method` enum with the coarse values `manual`, `vote`, `signature`, `chair-register`, and `advice` (default `manual`). The placeholder value `sign` from `decision-route-and-stages` SHALL be renamed to `signature`. The system SHALL provide three optional typed relations on `DecisionStage`: `votingRound` (→ VotingRound, many-to-one) for `method=vote`, `registeredBy` (→ Person, many-to-one) for `method=chair-register`, and `signedDocument` (→ DigitalDocument, many-to-one) for `method=signature`. Vote sub-variants (open/secret/personal/general/roll-call/show-of-hands) SHALL be expressed via the existing `VotingRound.isSecret` and `VotingRound.votingMethod` fields and SHALL NOT be added as new `method` enum values.

#### Scenario: Method enum offers the five coarse methods

- **GIVEN** the DecisionStage schema
- **WHEN** the `method` enum is inspected
- **THEN** it contains exactly `manual`, `vote`, `signature`, `chair-register`, `advice` with default `manual`, and does NOT contain `sign`

#### Scenario: Anonymous vote is expressed via VotingRound, not a new method value

- **GIVEN** a stage with `method=vote` linked to a `VotingRound` whose `isSecret=true`
- **WHEN** the stage is inspected
- **THEN** the ballot is treated as a secret/anonymous vote without any `method` value other than `vote`

### Requirement: Required mechanism relation matches the method

A `DecisionStage` SHALL carry the mechanism relation required by its `method`: `method=vote` requires `votingRound`, `method=chair-register` requires `registeredBy`, `method=signature` requires `signedDocument`; `method=advice` and `method=manual` require no mechanism relation. This integrity rule SHALL be recorded as a declarative validation note on the schema.

#### Scenario: A vote stage without a voting round is incomplete

- **GIVEN** a DecisionStage with `method=vote` and no `votingRound` relation
- **WHEN** the stage's completeness is evaluated against the validation note
- **THEN** it is flagged as missing its required mechanism relation

#### Scenario: An advice stage needs no mechanism relation

- **GIVEN** a DecisionStage with `method=advice` and no mechanism relations
- **WHEN** the stage's completeness is evaluated
- **THEN** it is valid, because advice requires no mechanism object

### Requirement: Vote-method outcome is derived declaratively from the VotingRound

For a `DecisionStage` with `method=vote`, the stage `outcome` SHALL be derived declaratively (`x-openregister-calculations`) from the linked `VotingRound.result`: `adopted`→`adopted`, `rejected`→`rejected`, `tied`→`rejected` (unless a tie-break resolves it), `invalid`→no outcome. No Service class SHALL compute the vote outcome; the `VotingRound` is the single source of truth.

#### Scenario: Adopted voting round yields an adopted stage outcome

- **GIVEN** a `method=vote` stage linked to a VotingRound with `result=adopted` (28 for, 3 against, 2 abstain)
- **WHEN** the stage is loaded
- **THEN** the stage `outcome` derives to `adopted` from the round

#### Scenario: Rejected voting round yields a rejected stage outcome

- **GIVEN** a `method=vote` stage linked to a VotingRound with `result=rejected`
- **WHEN** the stage is loaded
- **THEN** the stage `outcome` derives to `rejected`

### Requirement: Chair-register, advice, and manual outcomes are set directly

For `method=chair-register`, `method=advice`, and `method=manual`, the stage `outcome` SHALL be set directly by the actor rather than derived. A `method=chair-register` stage SHALL record who recorded it via `registeredBy` and SHALL set `outcome` + `decidedAt`. A `method=advice` stage SHALL set `outcome` to a non-binding value (`advised` or `deferred`). A `method=manual` stage SHALL set `outcome` directly with no mechanism.

#### Scenario: Chair registers a consensus outcome directly

- **GIVEN** a DecisionStage with `method=chair-register`
- **WHEN** the chair (a Person) records `outcome=adopted` with `registeredBy` set to that chair and `decidedAt` stamped
- **THEN** the stage is `decided` with that outcome and the chair recorded, with no ballot

#### Scenario: Advisory body produces a non-binding advice

- **GIVEN** a DecisionStage with `method=advice` (a raadscommissie)
- **WHEN** the body records `outcome=advised`
- **THEN** the stage outcome is the non-binding advisory value with no ballot and no derivation

### Requirement: Signature method resolves a stage via eIDAS signing

The system SHALL support `method=signature` as a decision method available to ANY decision regardless of mode (ADR-006). A `method=signature` stage SHALL reference the signed artefact via `signedDocument` (→ DigitalDocument) and read its signatories from the related `Minutes.signedBy`. When eIDAS signing of the document completes, `EIDASSignatureService` SHALL resolve the related signature stage by linking the `signedDocument` and setting the stage `outcome=adopted` + `decidedAt`. No standalone Signature schema SHALL be introduced.

#### Scenario: Completed signing resolves the signature stage

- **GIVEN** a `method=signature` DecisionStage on a corporate RvC ratifying stage with a `signedDocument`
- **WHEN** the chair and secretary complete eIDAS signing via `EIDASSignatureService`
- **THEN** the service links the signed document to the stage and sets the stage `outcome=adopted` with `decidedAt` stamped

#### Scenario: Signature is available regardless of mode

- **GIVEN** any Decision in any organisation mode (`gov`/`corp`/`assoc`/`ops`/`citizen`)
- **WHEN** a stage is created with `method=signature`
- **THEN** the signature method is available without requiring a corporate-only entity (no board-* schema)

