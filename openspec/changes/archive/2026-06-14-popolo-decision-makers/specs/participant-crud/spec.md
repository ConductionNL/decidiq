# participant-crud Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- popolo-decision-makers

## Purpose

Tracks the deprecation of the flat `Participant` schema in favour of the Popolo
`Person` + `Membership` model (ADR-001 §2). `Participant` is retained as a backward-
compatibility shim because the quorum aggregation, vote-casting resolver, and several
specs still reference it; new decision-maker data MUST use Person + Membership.

## ADDED Requirements

### Requirement: REQ-PCR-010 — Participant schema deprecated in favour of Person + Membership
The system MUST annotate the `Participant` schema in
`lib/Settings/decidesk_register.json` as deprecated (in its `description`), and MUST NOT
create new decision-maker seed data as `Participant` objects. New person/relationship
data MUST be modelled as `Person` + `Membership` per the `person-and-membership` spec.
The `Participant` schema MUST remain active (not deleted) so existing references —
the `Meeting` quorum aggregation (`totalParticipantCount`/`presentParticipantCount`),
`VotingService::resolveParticipantUuid()`, and dependent specs — continue to function.

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

## Non-Functional Requirements

- **Performance:** Retaining the Participant shim adds no read-path overhead — no new objects are created against it by this change.
- **Accessibility:** N/A — schema/seed change only; existing Participant UI is unchanged.
- **Internationalization:** Dutch and English MUST be supported for the deprecation note shown to maintainers/users.

## Acceptance Criteria

- [ ] `Participant` schema `description` is annotated deprecated
- [ ] No new `Participant` seeds are added by this change
- [ ] `Participant` schema remains active and queryable (not deleted)
- [ ] Full removal of `Participant` is tracked as a deferred follow-up

## Notes

- ADR-001 §2 (Person + Membership separation; Participant merged these incorrectly).
- Full `Participant` deletion is deferred — it ripples into the quorum aggregation,
  `voting-system`/`meeting-attendees`/`participant-crud` specs, and
  `VotingService::resolveParticipantUuid()`. Tracked as a deferred question.

## MODIFIED Requirements

### Requirement: Create a participant
The app SHALL allow users to create a new decision maker as a `Person` + `Membership`
pair via the Popolo model (per the `person-and-membership` spec). Creating a new flat
`Participant` object is deprecated: the `Participant` schema is retained only as a
backward-compatibility shim and SHALL NOT be the default target for new decision-maker
data. Existing `Participant` records remain readable and editable.
<!-- Previous behavior: the app created a new Participant object via a schema-driven form
     dialog with displayName/role/party/email/joinedAt/leftAt/votingWeight, persisted via
     ObjectService.saveObject(). That flat model is deprecated in favour of Person + Membership. -->

#### Scenario: New decision maker created as Person + Membership
- **GIVEN** the user adds a new decision maker
- **WHEN** the record is created
- **THEN** a `Person` (identity) and a `Membership` (role/party/votingWeight/body link) are persisted via `ObjectService.saveObject()`, not a flat Participant

#### Scenario: Participant creation path is deprecated
- **WHEN** new decision-maker data is seeded or created by this change
- **THEN** it is NOT created as a `Participant` object (the shim is retained for existing records only)

#### Scenario: Validation prevents save without required fields
- **WHEN** the user submits the Person/Membership form with `name` (Person) or `role` (Membership) empty
- **THEN** the form displays a validation error and the objects are not saved
