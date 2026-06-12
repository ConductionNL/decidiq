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
