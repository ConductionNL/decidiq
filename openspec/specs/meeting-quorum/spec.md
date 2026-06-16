# meeting-quorum Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-meeting-management-core-t1. Update Purpose after archive.

## Requirements

### Requirement: REQ-MQR-001 — Quorum calculation methods

The system SHALL support two quorum calculation methods defined by `GovernanceBody.quorumRule`:
- **Fixed count** (`fixed:N`): a minimum of N attendees must be present
- **Percentage** (`percentage:N`): at least N% of active members must be present

The QuorumService SHALL read the quorumRule from the GovernanceBody and calculate whether quorum is met based on the number of attendees with attendanceStatus "present" or "proxy".

#### Scenario: REQ-MQR-001-S1 — Fixed count quorum met
- **GIVEN** GovernanceBody "Gemeenteraad Delft" has quorumRule "fixed:20"
- **AND** 25 attendees have attendanceStatus "present"
- **WHEN** quorum is validated
- **THEN** quorumMet is true

#### Scenario: REQ-MQR-001-S2 — Fixed count quorum not met
- **GIVEN** GovernanceBody "Gemeenteraad Delft" has quorumRule "fixed:20"
- **AND** 15 attendees have attendanceStatus "present" and 2 have "proxy"
- **WHEN** quorum is validated
- **THEN** quorumMet is false (17 < 20)

#### Scenario: REQ-MQR-001-S3 — Percentage quorum met
- **GIVEN** GovernanceBody "ALV De Meeuwen" has quorumRule "percentage:50" and 100 active members
- **AND** 55 attendees have attendanceStatus "present"
- **WHEN** quorum is validated
- **THEN** quorumMet is true (55 >= 50)

#### Scenario: REQ-MQR-001-S4 — Proxy votes counted toward quorum
- **GIVEN** GovernanceBody has quorumRule "fixed:20"
- **AND** 18 attendees are "present" and 3 are "proxy"
- **WHEN** quorum is validated
- **THEN** quorumMet is true (21 >= 20)

### Requirement: REQ-MQR-002 — Quorum validation on meeting transition

The system SHALL validate quorum when a meeting transitions from "scheduled" to "opened" in governance domains where quorum is enforced (legislative, association, corporate). If quorum is not met, the transition SHALL be rejected with an HTTP 409 response.

#### Scenario: REQ-MQR-002-S1 — Transition blocked when quorum not met
- **GIVEN** a legislative meeting is in "scheduled" state with 15 of 20 required present
- **WHEN** the user triggers action "open"
- **THEN** the system returns HTTP 409 with `{ message: "Quorum not met", quorumRequired: 20, attendeeCount: 15, quorumMet: false }`

#### Scenario: REQ-MQR-002-S2 — Transition allowed when quorum met
- **GIVEN** a legislative meeting is in "scheduled" state with 25 of 20 required present
- **WHEN** the user triggers action "open"
- **THEN** the meeting transitions to "opened"

#### Scenario: REQ-MQR-002-S3 — Quorum not enforced for operations domain
- **GIVEN** an operations meeting is in "scheduled" state with 2 of 5 members present
- **WHEN** the user triggers action "open"
- **THEN** the meeting transitions to "opened" regardless of attendance

### Requirement: REQ-MQR-003 — Quorum status in meeting response

The system SHALL include quorum status in the GET `/api/meetings/{id}` response as a computed field:
```json
{
  "quorum": {
    "quorumRequired": 20,
    "attendeeCount": 25,
    "quorumMet": true,
    "quorumRule": "fixed:20"
  }
}
```

#### Scenario: REQ-MQR-003-S1 — Quorum status returned
- **GIVEN** a meeting for a body with quorumRule "fixed:20" has 25 present attendees
- **WHEN** the user sends GET `/api/meetings/abc-123`
- **THEN** the response includes `quorum: { quorumRequired: 20, attendeeCount: 25, quorumMet: true, quorumRule: "fixed:20" }`

#### Scenario: REQ-MQR-003-S2 — Quorum null when no rule defined
- **GIVEN** a meeting for a body with no quorumRule (operations domain)
- **WHEN** the user sends GET `/api/meetings/abc-123`
- **THEN** the response includes `quorum: null`

### Requirement: REQ-MQR-004 — Quorum status indicator in UI

The system SHALL display a quorum status indicator on the meeting detail page. The indicator SHALL show the current attendance count, the required count, and a visual indicator (green if met, red if not met).

#### Scenario: REQ-MQR-004-S1 — Quorum met indicator
- **GIVEN** a meeting has 25 present out of 20 required
- **WHEN** the detail page renders
- **THEN** the quorum indicator shows "25/20" with a green status icon

#### Scenario: REQ-MQR-004-S2 — Quorum not met indicator
- **GIVEN** a meeting has 15 present out of 20 required
- **WHEN** the detail page renders
- **THEN** the quorum indicator shows "15/20" with a red status icon

### Requirement: REQ-MQR-005 — Chair quorum override

The system SHALL allow the meeting chair to override a quorum failure when the governance body's statutes or law permit it. The override SHALL require a reason text and SHALL be recorded in the audit trail.

#### Scenario: REQ-MQR-005-S1 — Override with reason
- **GIVEN** a legislative meeting has 18 of 20 required present and quorum check fails
- **WHEN** the chair triggers "open" with override flag and reason "Tweede vergadering conform Gemeentewet Art. 20 lid 3"
- **THEN** the meeting transitions to "opened"
- **AND** the audit trail records the override with the provided reason
