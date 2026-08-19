# participant-crud Delta — model-debt-cleanup-schema

## MODIFIED Requirements

### Requirement: REQ-PCR-010 — Participant schema deprecated in favour of Person + Membership

The system MUST annotate the `Participant` schema in
`lib/Settings/decidesk_register.json` as deprecated (in its `description`), and MUST NOT
create new decision-maker seed data as `Participant` objects. New person/relationship
data MUST be modelled as `Person` + `Membership` per the `person-and-membership` spec.
The `Participant` schema MUST remain active (not deleted) so existing references —
the `Meeting` quorum aggregation (`totalParticipantCount`/`presentParticipantCount`),
`VotingService::resolveParticipantUuid()`, `Vote.participant`, `EngagementRecord.participant`,
and dependent specs — continue to function. As of this change, `ConflictOfInterest.boardMember`
and `ProxyAuthorization.grantor`/`holder` no longer `$ref: Participant` (retargeted to
`Membership`/`Person` respectively — REQ-SDM-023/024); the schema's own description MUST
name the narrowed, exact set of remaining `$ref: Participant` consumers rather than describe
the shim's reach in general terms, so a future reader does not have to re-derive the current
consumer set by grepping the register.

#### Scenario: Participant marked deprecated
- GIVEN the decidesk register is imported
- WHEN the `Participant` schema description is inspected
- THEN it states the schema is deprecated and that Person + Membership is the canonical model

#### Scenario: New data uses Person + Membership
- GIVEN a new decision maker is seeded by this change
- WHEN the seeds are imported
- THEN the person is created as a `Person` + `Membership` pair, not a new `Participant`

#### Scenario: Participant shim still resolvable
- GIVEN existing code reads Participant records for quorum and vote-casting
- WHEN those reads execute after this change
- THEN the `Participant` schema is still present and queryable (the shim is retained)

#### Scenario: Participant's description names its exact remaining consumers

- **GIVEN** the decidesk register is imported after this change
- **WHEN** the `Participant` schema description is inspected
- **THEN** it names exactly `Vote.participant`, `EngagementRecord.participant`, the `Meeting` quorum
  aggregation, and `VotingService::resolveParticipantUuid()` as the remaining shim consumers
- **AND** it no longer implies `ConflictOfInterest` or `ProxyAuthorization` still reference it
