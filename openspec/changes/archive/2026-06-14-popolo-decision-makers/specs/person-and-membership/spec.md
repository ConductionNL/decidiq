# person-and-membership Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- popolo-decision-makers

## Purpose

Implements the Popolo person/org-relationship model (ADR-001): `Person` for identity,
`Membership` for the person↔organisation relationship, and `Post` for formal positions.
Separates identity from organisational relationship per ADR-001 §2 so one person can hold
memberships in multiple governance bodies without duplicating identity, replacing the flat
`Participant` model. Schemas are declared in `lib/Settings/decidesk_register.json` per
ADR-031 (config-first).

## ADDED Requirements

### Requirement: REQ-PMB-010 — Person schema (Popolo identity)
The system MUST define a `Person` schema in `lib/Settings/decidesk_register.json`
(`schemaType: foaf:Person`) holding only identity data, mirroring the ADR-000 Person field
set: `name` (required), `familyName`, `givenName`, `gender`, `birthDate` (date), `image`,
`biography`, and a convenience `email`. The Person schema MUST NOT carry role, party,
votingWeight, or governance-body relationship fields — those belong to Membership.

#### Scenario: Person holds identity only
- GIVEN the decidesk register is imported
- WHEN the `Person` schema is inspected
- THEN it exposes `name`/`familyName`/`givenName`/`gender`/`birthDate`/`image`/`biography`/`email`
- AND it does NOT define `role`, `party`, or `votingWeight`

#### Scenario: One person, multiple bodies
- GIVEN a Person "Marie Janssen" exists
- WHEN she is a member of both "Gemeenteraad Amsterdam" and "Ledenraad VNG"
- THEN she has a single `Person` record linked by two separate `Membership` records (no duplicated identity)

### Requirement: REQ-PMB-011 — Membership schema (org:Membership relationship)
The system MUST define a `Membership` schema (`schemaType: org:Membership`) representing the
relationship between a Person and a GovernanceBody, mirroring the ADR-000 Membership field
set: `role` (required enum: chair, vice-chair, secretary, treasurer, member, observer, guest),
`label`, `startDate`/`endDate` (date-time), `votingWeight` (number, default 1), and `party`
(Popolo `on_behalf_of`). Membership MUST declare `x-openregister-relations` to `Person`,
`GovernanceBody`, and `Post`, each `many-to-one`.

#### Scenario: Membership links person to body with role
- GIVEN a Person and a GovernanceBody exist
- WHEN a Membership is created with role "chair" linking them
- THEN the Membership resolves its `Person` and `GovernanceBody` relations
- AND `role`, `party`, `votingWeight`, `startDate`, `endDate` are carried on the Membership

#### Scenario: Membership active-window validity
- GIVEN a Membership with `endDate` null
- WHEN its validity is evaluated for a meeting date
- THEN the Membership is treated as active (active when `endDate` is null or in the future)

### Requirement: REQ-PMB-012 — Post schema (org:Post formal position)
The system MUST define a `Post` schema (`schemaType: org:Post`) representing a formal position
that exists independently of who fills it, mirroring the ADR-000 Post field set: `label`
(required), `role` (enum: chair, vice-chair, secretary, treasurer, member),
`startDate`/`endDate`. Post MUST declare an `x-openregister-relations` to `GovernanceBody`
(`many-to-one`).

#### Scenario: Post exists independently of occupant
- GIVEN a Post "Voorzitter gemeenteraad" linked to "Gemeenteraad Amsterdam"
- WHEN no Membership currently references the Post
- THEN the Post still exists (vacancy), and a Membership MAY reference it to fill it

### Requirement: REQ-PMB-013 — Popolo seed data for persons, memberships, and posts
The system MUST ship realistic `x-openregister-seeds` for `Person`, `Membership`, and `Post`
covering general organisations (a municipal council, a corporate/supervisory board, and an
association), including chair/secretary/treasurer Posts, reusing existing demo person names
(e.g. femke-halsema, marie-janssen, jan-de-vries) where sensible.

#### Scenario: Seeded demo across org types
- GIVEN the register is imported on a clean instance
- WHEN persons and memberships are listed
- THEN council members, corporate board members, and association members appear as Person + Membership pairs
- AND at least chair, secretary, and member roles are represented

## Non-Functional Requirements

- **Performance:** Relation resolution for a Membership (Person + GovernanceBody + Post) MUST complete within the OpenRegister default object-read budget; no N+1 service code is introduced (declarative relations only).
- **Accessibility:** N/A — this change is schema/seed/ORI only; no UI is added.
- **Internationalization:** Dutch and English MUST be supported for any user-facing strings; seed labels use Dutch governance vocabulary with English-keyed enums.

## Acceptance Criteria

- [ ] `Person`, `Membership`, `Post` schemas exist in `decidesk_register.json` with ADR-000 field sets
- [ ] Membership declares relations to Person, GovernanceBody, Post (all many-to-one)
- [ ] Post declares a relation to GovernanceBody
- [ ] Seeds cover council, corporate board, and association as Person + Membership pairs
- [ ] Identity (Person) carries no role/party/votingWeight

## Notes

- ADR-001 §2 (Person + Membership separation), §3 (Post for formal positions).
- ADR-031 (declarative `x-openregister-*`, no new Service classes).
- The flat `Participant` schema is retained as a deprecated shim — see `participant-crud`.
