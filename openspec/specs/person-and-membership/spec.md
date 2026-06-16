---
status: in-progress
status-note: In progress 2026-06-14 via popolo-decision-makers (Person/Membership/Post Popolo schemas implemented in the register per ADR-001).
openspec-changes:
  - popolo-decision-makers
---

# person-and-membership Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-meeting-management-core-t1. Update Purpose after archive.
## Requirements
### Requirement: REQ-PMB-001 — Meeting attendance tracking via Membership

The Membership relation SHALL support meeting attendance tracking. When a member is added as a meeting attendee, the system SHALL use the Membership record to determine their role, votingWeight, and party. Attendance status (present, absent, proxy, excused) SHALL be tracked on the CalDAV ATTENDEE PARTSTAT parameter, not as a persistent Membership property.

**Rationale:** Attendance is per-meeting, not per-membership. Storing it on the VEVENT ATTENDEE keeps attendance data with the meeting context and avoids polluting the membership record.

#### Scenario: REQ-PMB-001-S1 — Membership data used for attendee
- **GIVEN** Person "J. van den Berg" has a Membership in "Gemeenteraad Delft" with role "member", votingWeight 1, party "VVD"
- **WHEN** a meeting is created for Gemeenteraad Delft
- **THEN** J. van den Berg is added as a VEVENT ATTENDEE with CN from Person.name, ROLE from Membership.role

#### Scenario: REQ-PMB-001-S2 — Multiple memberships across bodies
- **GIVEN** Person "M. Jansen" has memberships in both "Gemeenteraad Delft" and "Commissie Ruimte"
- **WHEN** a meeting is created for "Commissie Ruimte"
- **THEN** M. Jansen is added as an attendee using the "Commissie Ruimte" membership record (not the Gemeenteraad one)

### Requirement: REQ-PMB-002 — Active membership query for meeting attendees

The system SHALL query only active memberships when auto-populating meeting attendees. A membership is active when `endDate` is null or in the future relative to the meeting's scheduledDate.

#### Scenario: REQ-PMB-002-S1 — Expired membership excluded
- **GIVEN** Person "K. Bakker" has a membership with endDate "2026-03-01" (expired)
- **WHEN** a meeting scheduled for 2026-04-23 is created
- **THEN** K. Bakker is NOT included in the auto-populated attendee list

#### Scenario: REQ-PMB-002-S2 — Future-starting membership included
- **GIVEN** Person "L. de Vries" has a membership with startDate "2026-04-01" and endDate null
- **WHEN** a meeting scheduled for 2026-04-23 is created
- **THEN** L. de Vries IS included in the auto-populated attendee list

### Requirement: REQ-PMB-003 — Membership-based attendee count

The system SHALL calculate the total active member count from Membership records for use in quorum calculations. The member count is the number of active memberships for the governance body at the time of the meeting.

#### Scenario: REQ-PMB-003-S1 — Member count for quorum
- **GIVEN** GovernanceBody "Gemeenteraad Delft" has 39 active memberships and quorumRule "fixed:20"
- **WHEN** quorum is calculated for a meeting
- **THEN** the total member count is 39 and the quorum threshold is 20

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

