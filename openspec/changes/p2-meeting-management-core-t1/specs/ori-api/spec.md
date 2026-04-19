# Delta Spec: ori-api

**Change:** p2-meeting-management-core-t1
**Capability:** ori-api — ORI API output includes Meeting entity with governance body metadata
**Schema.org type:** `meeting:Meeting` → ORI `Event` / `Meeting`

**ORI standard reference:** Open Raadsinformatie (ORI), Popolo-based, maintained by VNG Realisatie and Open State Foundation.

## ADDED Requirements

### Requirement: REQ-ORI-001 — ORI Meeting endpoint

The system SHALL expose meetings via the ORI-compatible API endpoint `GET /api/ori/v1/events`. The endpoint SHALL serialize Meeting data from CalDAV VEVENTs into ORI Event/Meeting format with Popolo field names.

**ORI field mapping (ADR-003):**
| DecideDesk Property | ORI/Popolo Field | Source |
|-------------------|-----------------|--------|
| title | name | VEVENT SUMMARY |
| scheduledDate | start_date | VEVENT DTSTART |
| endDate | end_date | VEVENT DTEND |
| location | location | VEVENT LOCATION |
| meetingType | classification | X-DECIDESK-MEETING-TYPE |
| lifecycle | status | X-DECIDESK-LIFECYCLE |
| GovernanceBody.name | organization.name | Relation via wrapper |
| GovernanceBody.id | organization.id | Relation via wrapper |
| attendee count | attendee_count | VEVENT ATTENDEE count |
| Meeting.id | identifier | OpenRegister wrapper UUID |
| Meeting.uri | @id | OpenRegister URI |

#### Scenario: REQ-ORI-001-S1 — ORI event list
- **GIVEN** 5 meetings exist for "Gemeenteraad Delft"
- **WHEN** GET `/api/ori/v1/events` is called
- **THEN** the response contains 5 objects in ORI Event format with Popolo field names

#### Scenario: REQ-ORI-001-S2 — ORI event with governance body
- **GIVEN** a meeting "Vergadering Gemeenteraad Delft" exists linked to body "Gemeenteraad Delft"
- **WHEN** GET `/api/ori/v1/events` is called
- **THEN** the event includes `organization: { "@id": "...", "name": "Gemeenteraad Delft", "classification": "council" }`

### Requirement: REQ-ORI-002 — ORI event filtering

The ORI event endpoint SHALL support filtering by date range and governance body (organization). Pagination SHALL follow ORI standard format with `page` and `size` parameters.

#### Scenario: REQ-ORI-002-S1 — Filter ORI events by date
- **GIVEN** meetings exist in April and May 2026
- **WHEN** GET `/api/ori/v1/events?start_date=2026-04-01&end_date=2026-04-30` is called
- **THEN** only April meetings are returned

#### Scenario: REQ-ORI-002-S2 — Filter ORI events by organization
- **GIVEN** meetings exist for multiple governance bodies
- **WHEN** GET `/api/ori/v1/events?organization_id={bodyId}` is called
- **THEN** only meetings for the specified body are returned

#### Scenario: REQ-ORI-002-S3 — ORI pagination
- **GIVEN** 30 meetings exist
- **WHEN** GET `/api/ori/v1/events?page=1&size=10` is called
- **THEN** the response includes 10 events and pagination metadata

### Requirement: REQ-ORI-003 — ORI event format compliance

The ORI event output SHALL comply with the ORI specification format, supporting both JSON and XML content types via Accept header negotiation. The JSON format SHALL use JSON-LD context referencing Popolo vocabulary.

**Akoma Ntoso vocabulary:** Meeting metadata elements (title, date, body reference) SHALL use Akoma Ntoso vocabulary where it extends beyond Popolo (e.g., `akomaNtoso:debateSection` for agenda items in the ORI feed).

#### Scenario: REQ-ORI-003-S1 — JSON-LD format
- **GIVEN** a client sends Accept: application/ld+json
- **WHEN** GET `/api/ori/v1/events` is called
- **THEN** the response includes `@context` referencing Popolo JSON-LD context

#### Scenario: REQ-ORI-003-S2 — XML format
- **GIVEN** a client sends Accept: application/xml
- **WHEN** GET `/api/ori/v1/events` is called
- **THEN** the response is valid ORI XML with Popolo namespaces

### Requirement: REQ-ORI-004 — ORI related entities

The ORI event output SHALL include references to related entities: agenda items (as `agendaItems` array with position and title), related decisions (as `relatedDecision` reference), and related minutes (as `relatedReport` reference). References use ORI URI format.

#### Scenario: REQ-ORI-004-S1 — Event with agenda items
- **GIVEN** a meeting has 5 agenda items
- **WHEN** the meeting is serialized as an ORI event
- **THEN** the event includes `agendaItems` array with 5 entries, each containing `position`, `name`, and `@id`

#### Scenario: REQ-ORI-004-S2 — Event with no minutes
- **GIVEN** a meeting has no associated Minutes record
- **WHEN** the meeting is serialized as an ORI event
- **THEN** the `relatedReport` field is null

### Requirement: REQ-ORI-005 — ORI endpoint public access

The ORI event endpoint SHALL be publicly accessible (no authentication required) to support open data consumers. The endpoint SHALL be annotated with `#[PublicPage]` and `#[NoCSRFRequired]`. A CORS OPTIONS route SHALL be registered.

**Nextcloud OCP interface:** `#[PublicPage]`, `#[NoCSRFRequired]` annotations

#### Scenario: REQ-ORI-005-S1 — Public access without authentication
- **GIVEN** no authentication token is provided
- **WHEN** GET `/api/ori/v1/events` is called
- **THEN** the system returns HTTP 200 with event data

#### Scenario: REQ-ORI-005-S2 — CORS preflight
- **GIVEN** an external client sends OPTIONS `/api/ori/v1/events`
- **WHEN** the request is processed
- **THEN** the system returns appropriate CORS headers allowing cross-origin access
