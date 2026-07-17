# declarative-dialect-integrity Specification

## Purpose
TBD - created by archiving change done-spec-fixes. Update Purpose after archive.
## Requirements
### Requirement: REQ-DIALECT-001 Notification triggers use OpenRegister's canonical vocabulary
Every `x-openregister-notifications` trigger decidesk declares SHALL use a `trigger.type` drawn from
OpenRegister's `NotificationAnnotationValidator::VALID_TRIGGERS`
(`created`, `updated`, `transition`, `scheduled`, `threshold`, `calculatedChange`). A value outside
that set matches no dispatch branch, so the notification is inert — declared, reviewed, and never
firing.

#### Scenario: Every declared trigger is dispatchable
- GIVEN the register declares notifications across its schemas
- WHEN each `trigger.type` is checked against OpenRegister's valid triggers
- THEN every declared trigger type is a member of that set.

#### Scenario: A drifted trigger fails the build
- GIVEN a notification declares `trigger.type: "create"` instead of `"created"`
- WHEN the register test suite runs
- THEN it fails, naming the schema, the notification, and the offending value
- AND the failure states that the notification can never fire.

### Requirement: REQ-DIALECT-002 Relations use the canonical property-level $ref dialect
Decidesk SHALL declare every schema relation as a property-level `$ref` — on the property for a
to-one relation, or under `items` for a to-many relation. The bespoke per-schema
`x-openregister-relations` block was retired 2026-07-08 (ADR-062 rule 7) and SHALL NOT be used: no
engine reads it, so a relation declared there is inert.

#### Scenario: Core relations are materialised as properties
- GIVEN the core schemas (Meeting, Participant, AgendaItem, VotingRound, Vote, Decision,
  DecisionStage, ActionItem, Minutes)
- WHEN each expected relation is resolved
- THEN the relation exists as a property carrying a `$ref`, or as an array whose `items` carries one.

#### Scenario: The retired dialect cannot return
- GIVEN any schema in the register
- WHEN it is inspected for an `x-openregister-relations` block
- THEN no schema declares one
- AND reintroducing one fails the register test suite.

#### Scenario: A declared relation has somewhere to live
- GIVEN a schema declares a relation to another schema
- WHEN the schema's properties are inspected
- THEN a property of that name exists to hold the reference.

### Requirement: REQ-DIALECT-003 A corrected register reaches existing installs
Decidesk SHALL bump `info.version` in `decidesk_register.json` whenever it corrects its register in a
way that must reach existing installs, because OpenRegister's `importFromJson()` early-returns when
the computed version is not newer than the stored version — leaving an unbumped correction inert.

#### Scenario: A dialect correction is not skipped by the import gate
- GIVEN an install whose stored configuration version predates this change
- WHEN decidesk is upgraded
- THEN `info.version` is newer, so the corrected register is imported rather than skipped.

