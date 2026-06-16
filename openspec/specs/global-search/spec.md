# global-search Specification

## Purpose
TBD - created by archiving change 2026-05-11-p1-dashboard-and-navigation. Update Purpose after archive.

## Requirements

### Requirement: REQ-SRC-001 Global search bar in navigation header
The app SHALL provide a search input accessible from the navigation area that submits a full-text query to OpenRegister's `IndexService` across the following schemas: Meeting, Motion, Decision, AgendaItem, Participant.

#### Scenario: User enters a search query
- **WHEN** a user types at least 3 characters in the global search bar and presses Enter or waits 400 ms
- **THEN** a search request is sent to OpenRegister with `_search=<query>` across all configured schemas

#### Scenario: Short query is ignored
- **WHEN** a user enters fewer than 3 characters
- **THEN** no search request is sent and the results dropdown remains closed

---

### Requirement: REQ-SRC-002 Search results displayed in floating dropdown
The system SHALL display up to 10 search results in a floating dropdown below the search input. Each result SHALL show: entity type icon, title, and lifecycle/status badge. Clicking a result SHALL navigate to the entity's detail route.

#### Scenario: Results returned
- **WHEN** the search returns one or more matches
- **THEN** the dropdown shows up to 10 results with title, entity type, and status badge

#### Scenario: No results found
- **WHEN** the search returns zero matches
- **THEN** the dropdown shows a "Geen resultaten gevonden" message

#### Scenario: User clicks a search result
- **WHEN** a user clicks a result row for a Meeting with id `abc-123`
- **THEN** the router navigates to `/meetings/abc-123`

---

### Requirement: REQ-SRC-003 Search across all council information
The search capability SHALL support discovery across all governance data — Meetings, Motions, Decisions, AgendaItems, and Participants — in a single query, satisfying the highest-demand feature (demand: 814).

#### Scenario: Cross-entity results returned
- **WHEN** a query matches objects from multiple entity types (e.g. both a Meeting and a Motion)
- **THEN** results from all matching types are shown, grouped or labelled by type

---

### Requirement: REQ-SRC-004 Search is keyboard accessible
The search input and results dropdown SHALL be fully operable via keyboard: Tab to focus the input, type to search, arrow keys to navigate results, Enter to select, Escape to close the dropdown.

#### Scenario: Keyboard navigation through results
- **WHEN** the results dropdown is open and the user presses the down arrow key
- **THEN** focus moves to the first result item

#### Scenario: Escape closes dropdown
- **WHEN** the results dropdown is open and the user presses Escape
- **THEN** the dropdown closes and focus returns to the search input
