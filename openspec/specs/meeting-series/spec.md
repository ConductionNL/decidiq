# meeting-series Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-meeting-management-core-t1. Update Purpose after archive.

## Requirements

### Requirement: REQ-MSR-001 — Create a recurring meeting series

The system SHALL allow users to create a meeting with a recurrence pattern. When a series is created, the system SHALL generate individual meeting instances as separate VEVENTs, each sharing the same X-DECIDESK-SERIES identifier. The series pattern SHALL be stored as a JSON object on the first (template) meeting.

**Series pattern format:**
```json
{
  "frequency": "weekly|monthly|daily",
  "interval": 1,
  "until": "2026-12-31",
  "exceptions": ["2026-07-23"]
}
```

#### Scenario: REQ-MSR-001-S1 — Monthly series generation
- **GIVEN** the user creates a meeting with title "Gemeenteraad Delft", scheduledDate "2026-04-23T19:30:00+02:00", and seriesPattern `{ frequency: "monthly", interval: 1, until: "2026-12-31" }`
- **WHEN** the meeting is saved
- **THEN** the system creates 9 individual meeting VEVENTs (April through December)
- **AND** each VEVENT has the same X-DECIDESK-SERIES identifier
- **AND** each instance has its own unique CalDAV UID and OpenRegister wrapper

#### Scenario: REQ-MSR-001-S2 — Series with exceptions
- **GIVEN** the user creates a weekly series with exception date "2026-07-23" (summer recess)
- **WHEN** the series is generated
- **THEN** no meeting instance is created for 2026-07-23

#### Scenario: REQ-MSR-001-S3 — Instance limit
- **GIVEN** the user creates a series with until date more than 1 year from now
- **WHEN** the series is generated
- **THEN** the system generates at most 52 instances and logs a warning

### Requirement: REQ-MSR-002 — Edit a single series instance

The system SHALL allow users to edit an individual meeting instance without affecting other instances in the series. Editing a single instance SHALL NOT modify the series template or other instances.

#### Scenario: REQ-MSR-002-S1 — Reschedule one instance
- **GIVEN** a monthly series has instances on the 23rd of each month
- **WHEN** the user moves the May instance to May 28
- **THEN** only the May instance is updated; all other instances remain on the 23rd

### Requirement: REQ-MSR-003 — Edit the series template

The system SHALL allow users to update the series template, which regenerates future instances while preserving instances that are already "opened" or "closed". Past instances and instances with modified lifecycle states SHALL NOT be regenerated.

#### Scenario: REQ-MSR-003-S1 — Change series frequency
- **GIVEN** a monthly series has 9 instances, 2 already "closed"
- **WHEN** the user changes the template from monthly to bi-weekly
- **THEN** the 2 closed instances are preserved
- **AND** remaining draft/scheduled instances are replaced with bi-weekly instances

### Requirement: REQ-MSR-004 — List meetings grouped by series

The system SHALL support grouping meetings by series identifier in the list view. When grouping is active, the list SHALL show one row per series with a count of instances, expandable to show individual meetings.

#### Scenario: REQ-MSR-004-S1 — Series grouping in list
- **GIVEN** 9 meetings belong to series "gemeenteraad-delft-2026" and 6 to "ab-delfland-2026"
- **WHEN** the user requests GET `/api/meetings?groupBy=series`
- **THEN** the response groups meetings by series with instance counts

### Requirement: REQ-MSR-005 — Delete a meeting series

The system SHALL allow users to delete an entire series or a single instance. Deleting a series SHALL cancel all future draft/scheduled instances. Instances in "opened", "closed", or "adjourned" state SHALL NOT be cancelled.

#### Scenario: REQ-MSR-005-S1 — Delete entire series
- **GIVEN** a series has 9 instances: 3 closed, 2 scheduled, 4 draft
- **WHEN** the user deletes the entire series
- **THEN** the 6 draft/scheduled instances are set to lifecycle "cancelled"
- **AND** the 3 closed instances are preserved
