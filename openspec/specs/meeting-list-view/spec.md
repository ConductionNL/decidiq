# meeting-list-view Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-meeting-management-core-t1. Update Purpose after archive.

## Requirements

### Requirement: REQ-MLV-001 — Meeting list page

The system SHALL provide a meeting list page at route `/meetings` using `CnIndexPage` with the `useListView` composable. The page SHALL display meetings in both table and card views, togglable via `CnActionsBar`.

#### Scenario: REQ-MLV-001-S1 — Table view displays meeting columns
- **GIVEN** the user navigates to `/meetings`
- **WHEN** the page loads in table view
- **THEN** the table displays columns: title, scheduledDate, governance body name, meetingMode, attendee count, lifecycle status (with color-coded `CnStatusBadge`)
- **AND** rows are sorted by scheduledDate descending

#### Scenario: REQ-MLV-001-S2 — Card view displays meeting cards
- **GIVEN** the user switches to card view via CnActionsBar toggle
- **WHEN** the card view renders
- **THEN** each MeetingCard displays: title, date, governance body name, attendee count, lifecycle status badge

### Requirement: REQ-MLV-002 — Filter meetings by governance body

The system SHALL provide a governance body dropdown filter on the meeting list page. Selecting a body SHALL filter the list to show only meetings for that body.

#### Scenario: REQ-MLV-002-S1 — Filter by body
- **GIVEN** 15 meetings exist: 10 for "Gemeenteraad Delft", 5 for "Waterschap Delfland"
- **WHEN** the user selects "Gemeenteraad Delft" from the governance body dropdown
- **THEN** only the 10 Gemeenteraad Delft meetings are displayed

### Requirement: REQ-MLV-003 — Filter meetings by date range

The system SHALL provide date range filters (from/to date pickers) on the meeting list page.

#### Scenario: REQ-MLV-003-S1 — Filter by date range
- **GIVEN** meetings exist in April, May, and June 2026
- **WHEN** the user sets from "2026-04-01" and to "2026-04-30"
- **THEN** only April meetings are displayed

### Requirement: REQ-MLV-004 — Filter meetings by lifecycle status

The system SHALL provide a multi-select lifecycle status filter with options: draft, scheduled, opened, paused, adjourned, closed, cancelled.

#### Scenario: REQ-MLV-004-S1 — Multi-status filter
- **GIVEN** meetings exist in draft, scheduled, and closed states
- **WHEN** the user selects "draft" and "scheduled"
- **THEN** only draft and scheduled meetings are displayed

### Requirement: REQ-MLV-005 — Search meetings by title

The system SHALL provide a debounced title search field via the `searchPlugin` on the meetingStore. The search SHALL filter the meeting list as the user types (debounce 300ms).

#### Scenario: REQ-MLV-005-S1 — Title search
- **GIVEN** meetings "Gemeenteraad Delft" and "ALV De Meeuwen" exist
- **WHEN** the user types "Gemeenteraad" in the search field
- **THEN** only "Gemeenteraad Delft" meetings are displayed

### Requirement: REQ-MLV-006 — Pagination

The system SHALL paginate the meeting list using `CnPagination` with configurable page size. Default page size SHALL be 20.

#### Scenario: REQ-MLV-006-S1 — Paginated results
- **GIVEN** 45 meetings exist
- **WHEN** the page loads with default page size 20
- **THEN** page 1 shows 20 meetings
- **AND** pagination control shows 3 pages (20, 20, 5)

### Requirement: REQ-MLV-007 — Add meeting button

The system SHALL provide an "Add meeting" button in `CnActionsBar` that navigates to `/meetings/new` for the new meeting form.

#### Scenario: REQ-MLV-007-S1 — Navigate to new meeting form
- **GIVEN** the user is on the meeting list page
- **WHEN** the user clicks the "Add meeting" button
- **THEN** the router navigates to `/meetings/new`

### Requirement: REQ-MLV-008 — Row click navigates to detail

The system SHALL navigate to the meeting detail page when a user clicks a meeting row in the table or a meeting card in the grid view.

#### Scenario: REQ-MLV-008-S1 — Navigate to meeting detail
- **GIVEN** the meeting list displays meeting "Vergadering Gemeenteraad Delft" with id "abc-123"
- **WHEN** the user clicks the row
- **THEN** the router navigates to `/meetings/abc-123`
