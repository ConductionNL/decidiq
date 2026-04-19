# Delta Spec: governance-bodies

**Change:** p2-meeting-management-core-t1
**Capability:** governance-bodies — GovernanceBody gains workflowTemplate property for meeting lifecycle rules
**Schema.org type:** `org:Organization` (Popolo: Organization)

## ADDED Requirements

### Requirement: REQ-GBD-001 — Governance body workflowTemplate property

The GovernanceBody entity SHALL include a `workflowTemplate` property (string, optional) that stores the governance domain preset key. Valid values: `legislative`, `association`, `corporate`, `operations`, `citizen`. This property determines which meeting lifecycle transitions are allowed for meetings of this body (see meeting-workflow spec).

**Entity property (ADR-000):**
| Property | Type | Required | Description |
|----------|------|----------|-------------|
| workflowTemplate | string | No | Domain preset key: legislative, association, corporate, operations, citizen |

#### Scenario: REQ-GBD-001-S1 — Set workflowTemplate on body creation
- **GIVEN** the user creates a GovernanceBody with domain "legislative"
- **WHEN** the body is saved
- **THEN** workflowTemplate defaults to "legislative" based on the domain value

#### Scenario: REQ-GBD-001-S2 — Custom workflowTemplate override
- **GIVEN** a GovernanceBody exists with domain "legislative" and workflowTemplate "legislative"
- **WHEN** the admin updates workflowTemplate to "association"
- **THEN** future meetings for this body follow the association workflow preset

### Requirement: REQ-GBD-002 — Governance body meetings section

The GovernanceBody detail page SHALL include a "Scheduled Meetings" `CnDetailCard` section displaying upcoming and recent meetings for the body. The section SHALL use a `CnDataTable` with columns: title, scheduledDate, lifecycle status. Meetings SHALL be fetched via reverse lookup (`fetchUsed`) from the Meeting wrapper's relation to GovernanceBody.

#### Scenario: REQ-GBD-002-S1 — Meetings listed on body detail page
- **GIVEN** GovernanceBody "Gemeenteraad Delft" has 3 upcoming and 2 recent meetings
- **WHEN** the user views the GovernanceBody detail page
- **THEN** the "Scheduled Meetings" section displays 5 meetings sorted by scheduledDate

#### Scenario: REQ-GBD-002-S2 — Navigate to meeting from body
- **GIVEN** the meetings section displays meeting "Vergadering april 2026"
- **WHEN** the user clicks the meeting row
- **THEN** the router navigates to `/meetings/{meetingId}`

### Requirement: REQ-GBD-003 — Meeting creation from governance body

The system SHALL allow creating a meeting directly from the GovernanceBody detail page. The governance body SHALL be pre-filled in the meeting creation form when navigating from the body detail page.

#### Scenario: REQ-GBD-003-S1 — Pre-filled governance body
- **GIVEN** the user is on the detail page for "Gemeenteraad Delft"
- **WHEN** the user clicks "Add meeting" in the Scheduled Meetings section header
- **THEN** the router navigates to `/meetings/new?governanceBodyId={bodyId}`
- **AND** the meeting form has the governance body field pre-filled with "Gemeenteraad Delft"
