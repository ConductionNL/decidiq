# Delta Spec: meeting-attendees

**Change:** p2-meeting-management-core-t1
**Capability:** meeting-attendees — Participant assignment via Membership relation; attendance and observer tracking
**Schema.org type:** `org:Membership` (Popolo: Membership)

## ADDED Requirements

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
