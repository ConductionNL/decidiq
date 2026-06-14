# governance-bodies Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- popolo-decision-makers

## Purpose

Extends the governance-bodies capability with the Popolo `ContactDetail` entity and the
Person + Membership decision-maker model (ADR-001/ADR-006). A GovernanceBody is the
Popolo `org:Organization`; people relate to it via `Membership`, hold formal `Post`s in
it, and are reachable via typed `ContactDetail`s. Declared in
`lib/Settings/decidesk_register.json` per ADR-031.

## ADDED Requirements

### Requirement: REQ-GBD-010 — ContactDetail schema (popolo:ContactDetail)
The system MUST define a `ContactDetail` schema (`schemaType: popolo:ContactDetail`)
providing typed, multi-value contacts for a Person or GovernanceBody, mirroring the
ADR-000 ContactDetail field set: `type` (required enum: email, phone, fax, cell, address,
url), `value` (required), `label`, `note`, `validFrom`/`validUntil` (date-time).
ContactDetail MUST declare `x-openregister-relations` to `Person` and `GovernanceBody`
(each `many-to-one`).

#### Scenario: Typed multi-value contact for a person
- GIVEN a Person exists
- WHEN two ContactDetails of type "email" and "phone" are linked to the Person
- THEN both resolve their `Person` relation and carry distinct `type`/`value`

#### Scenario: Contact linked to a governance body
- GIVEN a GovernanceBody exists
- WHEN a ContactDetail of type "address" is linked to the GovernanceBody
- THEN it resolves its `GovernanceBody` relation

### Requirement: REQ-GBD-011 — Person + Membership as the governance-body decision-maker model
The system MUST relate people to a GovernanceBody through the Popolo `Membership` entity
(Person ↔ GovernanceBody), and MUST provide seeded ContactDetail demo data linked to
seeded Persons and GovernanceBodies. The flat `Participant` schema MUST NOT be used as
the canonical decision-maker model for new data (it is retained as a deprecated shim —
see `participant-crud`).

#### Scenario: Body membership via Membership, not Participant
- GIVEN a GovernanceBody "Gemeenteraad Amsterdam"
- WHEN its decision makers are seeded
- THEN they are represented as `Person` + `Membership` records (not new `Participant` records)

#### Scenario: Seeded contact details
- GIVEN the register is imported on a clean instance
- WHEN ContactDetails are listed
- THEN at least one email, one phone, and one address ContactDetail exist, linked to a Person or GovernanceBody

## Non-Functional Requirements

- **Performance:** ContactDetail relation resolution MUST stay within the OpenRegister default object-read budget; no service-layer aggregation is introduced.
- **Accessibility:** N/A — schema/seed/ORI change only.
- **Internationalization:** Dutch and English MUST be supported for user-facing strings; ContactDetail enum values are English-keyed.

## Acceptance Criteria

- [ ] `ContactDetail` schema exists in `decidesk_register.json` with ADR-000 field set
- [ ] ContactDetail declares relations to Person and GovernanceBody (many-to-one)
- [ ] Seeded ContactDetails cover email, phone, and address types
- [ ] Governance-body decision makers seeded as Person + Membership, not Participant

## Notes

- ADR-001 (Popolo classes), ADR-006 (one schema per concept).
- ADR-031 (declarative relations, no new Service classes).
