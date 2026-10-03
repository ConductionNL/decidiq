---
status: done
status-note: In progress 2026-06-14 via popolo-decision-makers (Person/Membership/Post Popolo schemas implemented in the register per ADR-001).
openspec-changes:
  - popolo-decision-makers
---

# person-and-membership Specification

## Purpose
Defines the Popolo decision-maker model as separate Person, Membership, and Post schemas, where Person holds identity data only and Membership links a person to a governance body with role, party, voting weight, and an active validity window. The capability uses active memberships to auto-populate and count meeting attendees for quorum, supports persons holding multiple memberships across bodies, models vacant formal positions via Post, and ships realistic seed data across council, corporate-board, and association organisation types.

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

### Requirement: REQ-MPR-001 Every person has a profile page

The system SHALL show a profile page at `/people/:id` for every person. It SHALL show the photo, name, biography and email of the person; their memberships, current ones first, each with body, role, party and portfolio; and their declared outside positions. Earlier memberships SHALL be listed separately from current ones.

#### Scenario: A clerk opens a council member's profile

- GIVEN Marie Janssen is a member of Gemeenteraad Amsterdam for D66 and of the D66 group, with a photo and a biography
- AND she declared an unpaid board seat at a housing foundation as a public outside position
- WHEN `griffier1` opens `/people/{id}` for Marie Janssen
- THEN the page shows her photo, name and biography
- AND lists both memberships with body, role and party
- AND lists the housing foundation board seat as unpaid

#### Scenario: A person without a photo

- GIVEN a person with no `image`
- WHEN their profile opens
- THEN the page shows their initials in place of a photo and no broken image

### Requirement: REQ-MPR-002 A membership carries the member's portfolio

A membership SHALL be able to carry a portfolio: a list of subjects the member holds in that body. The profile page SHALL show the portfolio beside the membership it belongs to.

#### Scenario: An alderman's portfolio

- GIVEN Femke Halsema's membership of the executive board has portfolio "Public order and safety" and "Communication"
- WHEN a member opens her profile
- THEN the executive board membership shows both subjects
- AND her council membership shows no portfolio

### Requirement: REQ-MPR-003 Member lists link to the profile

The members widget of a body SHALL link each member's name to their profile page. The participant page SHALL offer "Open profile" when its participant resolves to a person.

#### Scenario: From the council to a member

- GIVEN the Gemeenteraad Amsterdam page with its "Members" widget
- WHEN `griffier1` clicks "Marie Janssen"
- THEN the browser opens `/people/{id}` for Marie Janssen

### Requirement: REQ-MPR-004 The profile shows the member's voting record

The profile page SHALL show the member's votes in closed rounds that were not secret, newest first, each with date, decision, the member's choice, the result, and the member's party at the time. It SHALL NOT show votes of secret rounds or anonymised votes. The record SHALL be read through `GET /api/people/{personId}/voting-record`, which SHALL find the person's participants without creating any person or participant.

#### Scenario: A member reads a colleague's record

- GIVEN Marie Janssen voted for motion "Groen dak op het stadhuis" on 10 April 2025 in an open round that was adopted
- AND voted in a secret round on an appointment the same evening
- WHEN `member1` opens her profile
- THEN the voting record lists "Groen dak op het stadhuis", 10 April 2025, for, adopted, D66
- AND does not list the secret round

#### Scenario: A person without participants

- GIVEN a person whose Nextcloud user id and email match no participant
- WHEN their profile opens
- THEN the voting record says there are no recorded votes
- AND no person or participant was created by the request
