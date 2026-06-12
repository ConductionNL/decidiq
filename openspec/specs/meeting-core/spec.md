# meeting-core Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-meeting-management-core-t1. Update Purpose after archive.

## Requirements

### Requirement: REQ-MTC-001 — Create a meeting

The system SHALL allow authorized users to create a new meeting by providing a title, meetingType, scheduledDate, meetingMode, and a governance body reference. The meeting SHALL be stored as a CalDAV VEVENT (per ADR-002) with governance metadata in X-DECIDESK-* properties. An OpenRegister wrapper object SHALL be created simultaneously for relational queries.

**Nextcloud OCP interfaces:** `\OCA\DAV\CalDAV\CalDavBackend`, `\OCP\IUserSession`

**Entity properties (ADR-000):**
| Property | CalDAV Field | X-DECIDESK-* |
|----------|-------------|--------------|
| title | SUMMARY | — |
| meetingType | — | X-DECIDESK-MEETING-TYPE |
| scheduledDate | DTSTART | — |
| endDate | DTEND | — |
| location | LOCATION | — |
| meetingMode | — | X-DECIDESK-MEETING-MODE |
| lifecycle | — | X-DECIDESK-LIFECYCLE |
| quorumRequired | — | X-DECIDESK-QUORUM-REQUIRED |
| series | — | X-DECIDESK-SERIES |

#### Scenario: REQ-MTC-001-S1 — Successful meeting creation
- **GIVEN** the user is a member of GovernanceBody "Gemeenteraad Delft"
- **WHEN** the user submits a POST to `/api/meetings` with title "Vergadering Gemeenteraad Delft — april 2026", meetingType "regular", scheduledDate "2026-04-23T19:30:00+02:00", meetingMode "hybrid", and governanceBodyId referencing Gemeenteraad Delft
- **THEN** the system creates a VEVENT in the body's CalDAV calendar with SUMMARY, DTSTART, X-DECIDESK-MEETING-TYPE, X-DECIDESK-MEETING-MODE, and X-DECIDESK-LIFECYCLE set to "draft"
- **AND** the system creates an OpenRegister wrapper object with caldavUid, calendarId, and a relation to the GovernanceBody
- **AND** the API returns HTTP 201 with the meeting object including the generated id

#### Scenario: REQ-MTC-001-S2 — Missing required fields
- **GIVEN** the user is authenticated
- **WHEN** the user submits a POST to `/api/meetings` without a title
- **THEN** the system returns HTTP 400 with a validation error message

#### Scenario: REQ-MTC-001-S3 — Default lifecycle is draft
- **GIVEN** the user creates a meeting without specifying lifecycle
- **WHEN** the meeting is created
- **THEN** the X-DECIDESK-LIFECYCLE property SHALL be set to "draft"

### Requirement: REQ-MTC-002 — Read a meeting

The system SHALL allow users to retrieve a meeting by ID. The response SHALL merge CalDAV VEVENT data (SUMMARY, DTSTART, DTEND, LOCATION, ATTENDEE) with OpenRegister relation data (governance body, agenda items, minutes link) into a single API response.

#### Scenario: REQ-MTC-002-S1 — Successful meeting retrieval
- **GIVEN** a meeting exists with id "abc-123"
- **WHEN** the user sends GET `/api/meetings/abc-123`
- **THEN** the system returns HTTP 200 with a merged response containing all Meeting properties from CalDAV and all relations from OpenRegister

#### Scenario: REQ-MTC-002-S2 — Meeting not found
- **GIVEN** no meeting exists with id "nonexistent"
- **WHEN** the user sends GET `/api/meetings/nonexistent`
- **THEN** the system returns HTTP 404

#### Scenario: REQ-MTC-002-S3 — Expand governance body relation
- **GIVEN** a meeting exists linked to GovernanceBody "Gemeenteraad Delft"
- **WHEN** the user sends GET `/api/meetings/abc-123?_expand=governanceBody`
- **THEN** the response includes the full GovernanceBody object inline

### Requirement: REQ-MTC-003 — Update a meeting

The system SHALL allow authorized users to update meeting properties. Changes to CalDAV-mapped fields (title, scheduledDate, endDate, location) SHALL update the VEVENT. Changes to X-DECIDESK-* fields SHALL update the corresponding properties. The OpenRegister wrapper object SHALL be updated atomically with the VEVENT.

#### Scenario: REQ-MTC-003-S1 — Reschedule a meeting
- **GIVEN** a meeting exists in "scheduled" lifecycle state
- **WHEN** the user sends PUT `/api/meetings/abc-123` with a new scheduledDate "2026-04-30T19:30:00+02:00"
- **THEN** the system updates the VEVENT DTSTART and the wrapper object
- **AND** the audit trail records the before/after change

#### Scenario: REQ-MTC-003-S2 — Update meeting location
- **GIVEN** a meeting exists with location "Raadzaal"
- **WHEN** the user sends PUT `/api/meetings/abc-123` with location "Commissiekamer 1"
- **THEN** the system updates the VEVENT LOCATION property

### Requirement: REQ-MTC-004 — Delete a meeting (soft delete)

The system SHALL allow authorized users to delete a meeting. Deletion SHALL set the lifecycle to "cancelled" and mark the VEVENT STATUS as CANCELLED. The OpenRegister wrapper object SHALL be soft-deleted (status field set to deleted). CalDAV data SHALL be preserved for audit purposes.

#### Scenario: REQ-MTC-004-S1 — Soft delete a draft meeting
- **GIVEN** a meeting exists in "draft" lifecycle state
- **WHEN** the user sends DELETE `/api/meetings/abc-123`
- **THEN** the VEVENT STATUS is set to CANCELLED
- **AND** the X-DECIDESK-LIFECYCLE is set to "cancelled"
- **AND** the wrapper object status is set to "deleted"
- **AND** the API returns HTTP 200

#### Scenario: REQ-MTC-004-S2 — Cannot delete a closed meeting
- **GIVEN** a meeting exists in "closed" lifecycle state
- **WHEN** the user sends DELETE `/api/meetings/abc-123`
- **THEN** the system returns HTTP 409 with a message indicating closed meetings cannot be deleted

### Requirement: REQ-MTC-005 — List meetings with filters

The system SHALL provide a paginated list endpoint for meetings. The endpoint SHALL support filtering by governanceBodyId, date range (from/to), lifecycle status, and meetingType. Results SHALL be sorted by scheduledDate descending by default.

**Pagination:** `_page` + `_limit` parameters; response includes `total`, `page`, `pages` (ADR-002-api).

#### Scenario: REQ-MTC-005-S1 — List all meetings
- **GIVEN** 15 meetings exist across multiple governance bodies
- **WHEN** the user sends GET `/api/meetings?_page=1&_limit=10`
- **THEN** the system returns HTTP 200 with the first 10 meetings and pagination metadata `{ total: 15, page: 1, pages: 2 }`

#### Scenario: REQ-MTC-005-S2 — Filter by governance body
- **GIVEN** meetings exist for "Gemeenteraad Delft" and "Waterschap Delfland"
- **WHEN** the user sends GET `/api/meetings?governanceBodyId=gb-delft`
- **THEN** only meetings for Gemeenteraad Delft are returned

#### Scenario: REQ-MTC-005-S3 — Filter by date range
- **GIVEN** meetings exist in April and May 2026
- **WHEN** the user sends GET `/api/meetings?from=2026-04-01&to=2026-04-30`
- **THEN** only April meetings are returned

#### Scenario: REQ-MTC-005-S4 — Filter by lifecycle status
- **GIVEN** meetings exist with lifecycle "draft", "scheduled", and "closed"
- **WHEN** the user sends GET `/api/meetings?lifecycle=scheduled`
- **THEN** only scheduled meetings are returned

### Requirement: REQ-MTC-006 — Meeting audit trail

The system SHALL record all meeting changes in the OpenRegister audit trail. Each audit entry SHALL include the user UID (via `IUserSession`), timestamp, action type, and before/after property snapshots. Lifecycle transitions SHALL include the from-state and to-state.

**Nextcloud OCP interface:** `\OCP\IUserSession` for `$user->getUID()`

#### Scenario: REQ-MTC-006-S1 — Audit trail on meeting update
- **GIVEN** a meeting exists with title "Raadsvergadering"
- **WHEN** the user updates the title to "Raadsvergadering Delft"
- **THEN** the audit trail records: user UID, timestamp, action "update", before `{ title: "Raadsvergadering" }`, after `{ title: "Raadsvergadering Delft" }`

#### Scenario: REQ-MTC-006-S2 — Audit trail on lifecycle transition
- **GIVEN** a meeting is in "draft" state
- **WHEN** the user transitions the meeting to "scheduled"
- **THEN** the audit trail records: action "lifecycle_transition", from "draft", to "scheduled"

### Requirement: REQ-MTC-007 — Meeting API authorization

All mutation endpoints (POST, PUT, DELETE, lifecycle actions) SHALL verify the user is authorized via `IGroupManager::isAdmin()` or membership in the meeting's governance body. Read endpoints SHALL be accessible to all authenticated users. Unauthenticated access SHALL return HTTP 401.

**Nextcloud OCP interface:** `\OCP\IGroupManager`

#### Scenario: REQ-MTC-007-S1 — Unauthenticated access denied
- **GIVEN** no authentication token is provided
- **WHEN** a request is sent to GET `/api/meetings`
- **THEN** the system returns HTTP 401

#### Scenario: REQ-MTC-007-S2 — Non-admin mutation denied
- **GIVEN** the user is authenticated but not an admin and not a member of the meeting's governance body
- **WHEN** the user sends PUT `/api/meetings/abc-123`
- **THEN** the system returns HTTP 403
