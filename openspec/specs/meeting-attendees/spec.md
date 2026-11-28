---
status: done
---

# meeting-attendees Specification

## Purpose
Manages meeting attendees as CalDAV ATTENDEE entries on the meeting's VEVENT, auto-populated from the governance body's active Membership records (excluding expired memberships) when a meeting is created. Authorized users can add and remove attendees, track attendance status (present, absent, proxy, excused) via the PARTSTAT parameter, and attendee roles (chair, vice-chair, secretary, member, observer, guest) map to the CalDAV ROLE parameter. Each attendee's voting weight from their Membership is exposed in the meeting detail for quorum and voting calculations.

## Requirements

### Requirement: REQ-MAT-001 — Auto-populate attendees from governance body

The system SHALL auto-populate meeting attendees from the governance body's active Membership records when a meeting is created. Active memberships are those where `endDate` is null or in the future. Each membership SHALL be represented as a CalDAV ATTENDEE on the VEVENT.

**Nextcloud OCP interface:** `\OCA\DAV\CalDAV\CalDavBackend` (ATTENDEE property management)

#### Scenario: REQ-MAT-001-S1 — Attendees populated on creation
- **GIVEN** GovernanceBody "Gemeenteraad Delft" has 39 active members
- **WHEN** the user creates a meeting for Gemeenteraad Delft
- **THEN** the VEVENT contains 39 ATTENDEE entries mapped from Membership records
- **AND** each ATTENDEE includes CN (display name from Person) and ROLE (from Membership.role)

#### Scenario: REQ-MAT-001-S2 — Expired memberships excluded
- **GIVEN** GovernanceBody has 10 memberships, 2 with endDate in the past
- **WHEN** a meeting is created for the body
- **THEN** only 8 ATTENDEE entries are added

### Requirement: REQ-MAT-002 — Add attendee to meeting

The system SHALL allow authorized users to add an attendee to an existing meeting. The attendee SHALL be specified by Person reference and role. A CalDAV ATTENDEE entry SHALL be added to the VEVENT.

#### Scenario: REQ-MAT-002-S1 — Add a guest attendee
- **GIVEN** a meeting exists for Gemeenteraad Delft
- **WHEN** the user adds a person with role "guest" and name "Ir. P. de Vries"
- **THEN** the VEVENT gains an ATTENDEE entry with ROLE=NON-PARTICIPANT and CN="Ir. P. de Vries"

#### Scenario: REQ-MAT-002-S2 — Add an observer
- **GIVEN** a meeting exists
- **WHEN** the user adds a person with role "observer"
- **THEN** the VEVENT gains an ATTENDEE entry with ROLE=NON-PARTICIPANT and PARTSTAT=TENTATIVE

### Requirement: REQ-MAT-003 — Remove attendee from meeting

The system SHALL allow authorized users to remove an attendee from a meeting. The corresponding CalDAV ATTENDEE entry SHALL be removed from the VEVENT.

#### Scenario: REQ-MAT-003-S1 — Remove an attendee
- **GIVEN** a meeting has 39 attendees
- **WHEN** the user removes attendee "J. van den Berg"
- **THEN** the VEVENT ATTENDEE list contains 38 entries
- **AND** the audit trail records the removal

### Requirement: REQ-MAT-004 — Track attendance status

The system SHALL allow tracking of attendance status for each meeting attendee. Valid status values: `present`, `absent`, `proxy`, `excused`. The status SHALL be stored as the CalDAV PARTSTAT parameter on the ATTENDEE property.

**CalDAV PARTSTAT mapping:**
| attendanceStatus | PARTSTAT |
|-----------------|----------|
| present | ACCEPTED |
| absent | DECLINED |
| proxy | DELEGATED |
| excused | TENTATIVE |

#### Scenario: REQ-MAT-004-S1 — Mark attendee as present
- **GIVEN** a meeting is in "opened" state with attendee "M. Jansen"
- **WHEN** the chair marks M. Jansen as present
- **THEN** the ATTENDEE PARTSTAT is updated to ACCEPTED

#### Scenario: REQ-MAT-004-S2 — Mark attendee with proxy
- **GIVEN** attendee "K. Bakker" cannot attend but has delegated their vote
- **WHEN** the chair marks K. Bakker as proxy
- **THEN** the ATTENDEE PARTSTAT is updated to DELEGATED

### Requirement: REQ-MAT-005 — Attendee roles

The system SHALL support the following attendee roles mapped from Membership: `chair`, `vice-chair`, `secretary`, `member`, `observer`, `guest`. Roles from the Membership record SHALL be used to set the CalDAV ATTENDEE ROLE parameter.

#### Scenario: REQ-MAT-005-S1 — Chair role mapping
- **GIVEN** a Membership record has role "chair"
- **WHEN** the member is added as a meeting attendee
- **THEN** the CalDAV ATTENDEE has ROLE=CHAIR

#### Scenario: REQ-MAT-005-S2 — Observer role mapping
- **GIVEN** a person is added with role "observer"
- **WHEN** the attendee is created
- **THEN** the CalDAV ATTENDEE has ROLE=NON-PARTICIPANT

### Requirement: REQ-MAT-006 — Attendee voting weight

The system SHALL expose the voting weight from the Membership record for each attendee. The weight SHALL be available in the meeting detail API response for quorum and voting calculations.

#### Scenario: REQ-MAT-006-S1 — Weighted voting exposed
- **GIVEN** member "A. de Groot" has votingWeight 2 in their Membership
- **WHEN** the meeting detail is retrieved
- **THEN** the attendee entry for A. de Groot includes votingWeight 2

### Requirement: REQ-MAPM-001 Attendance is recorded per meeting

The meeting's Participants widget SHALL record for each participant whether they were present, absent, excused or represented, per meeting, without changing other meetings' records.

#### Scenario: The clerk records apologies
- GIVEN council member Anna sent apologies for the meeting of 14 October
- WHEN the clerk marks her as excused on that meeting
- THEN the meeting shows Anna as excused and her attendance on 7 October is unchanged

### Requirement: REQ-MSW-001 The chair or secretary swaps a member for a substitute during a meeting

The live meeting page SHALL show a "Seats" panel listing the meeting's seats with seat number, name and party. For the chair and the secretary of the meeting each voting member SHALL have a "Swap with substitute" action. Starting a swap SHALL create a mandate substitution for that meeting that copies the outgoing member's seat number, party, role and voting weight, and records the time, the reason and who recorded it. The panel SHALL then show the substitute in the seat with "substitute for" the member's name.

#### Scenario: A committee member leaves and her substitute takes the seat

- GIVEN meeting "Auditcommissie 4 maart" where Mr Bos (VVD) holds seat 3 and Mrs De Wit (VVD) attends as an observer
- AND `griffier1` is secretary of the meeting
- WHEN `griffier1` opens `/meetings/{id}/live`, clicks "Swap with substitute" on seat 3, picks Mrs De Wit, enters "Mr Bos left for another appointment" and confirms
- THEN a mandate substitution exists for the meeting with outgoing Mr Bos, incoming Mrs De Wit, seat 3, party VVD and `recordedBy: griffier1`
- AND the Seats panel shows Mrs De Wit in seat 3, "substitute for Mr Bos"

#### Scenario: A member without a presiding role

- GIVEN `member1` is a member in the same meeting
- WHEN `member1` opens the live meeting page
- THEN the Seats panel shows no swap actions
- AND a direct POST to `/api/meetings/{id}/substitutions` by `member1` answers 403

### Requirement: REQ-MSW-002 While a substitution is active, the substitute votes for the seat

While a substitution is active in a meeting, the system SHALL refuse votes from the outgoing member in that meeting's rounds and SHALL accept votes from the substitute. The quorum check SHALL count the seat once, through the substitute. A voting group preset that lists the outgoing member SHALL resolve to the substitute for rounds opened in that meeting.

#### Scenario: The substitute votes, the member who left cannot

- GIVEN the active substitution from REQ-MSW-001
- WHEN the chair opens a round on a motion in the meeting
- THEN Mrs De Wit sees the vote buttons and her vote is stored
- AND a vote posted by Mr Bos in that round is refused with a message that his seat is held by his substitute

#### Scenario: The quorum does not drop when a seat is filled

- GIVEN a meeting with quorum 3 and three members present, one of whom is substituted out
- WHEN the chair opens a round
- THEN the quorum check passes, because the substituted seat is counted through its substitute

#### Scenario: A voting group follows the swap

- GIVEN a voting group preset "Commissie audit" that lists Mr Bos
- WHEN the chair opens a round with that preset during the substitution
- THEN Mrs De Wit is an eligible voter of the round and Mr Bos is not

### Requirement: REQ-MSW-003 A swap is refused when it would change a vote in progress or break the seat plan

The system SHALL refuse to start or end a substitution while a voting round of the meeting is open. It SHALL refuse to start one when the outgoing participant is not a `member` or is already substituted out, when the incoming participant is not a participant of the meeting's body, has a voting role, or is already an active substitute, or when both are the same participant.

#### Scenario: Not during a vote

- GIVEN an open voting round in the meeting
- WHEN `griffier1` tries to swap Mrs Kaya for a substitute
- THEN the server answers 409 and says a vote is in progress
- AND no substitution is created

#### Scenario: The chair is not swapped

- GIVEN the chair of the meeting
- WHEN `griffier1` tries to swap the chair for a substitute
- THEN the server answers 400 and says only members can be substituted

### Requirement: REQ-MSW-004 Ending a substitution returns the seat to the member

The chair or secretary SHALL be able to end an active substitution. Ending it SHALL set its end time and SHALL restore the member's right to vote in the meeting. The ended substitution SHALL stay on record and SHALL be listed on the meeting.

#### Scenario: Mr Bos returns

- GIVEN the active substitution of seat 3
- WHEN `griffier1` clicks "End substitution" on seat 3 at 21:40
- THEN the substitution has `endedAt` 21:40
- AND in the next round Mr Bos can vote and Mrs De Wit cannot
- AND the meeting still lists the substitution from 20:15 to 21:40
