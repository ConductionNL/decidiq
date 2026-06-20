---
status: done
---

# app-dashboard Specification

## Purpose
Provides the app's landing dashboard, shown at the root route when OpenRegister is available. It presents four governance KPI cards (upcoming meetings, pending motions, open action items, recent decisions), a meeting status distribution chart, and quick-access navigation tiles to the primary entity types, loading all data in parallel with a loading state.

## Requirements

### Requirement: REQ-DSH-001 Dashboard page is the default route
The system SHALL render the Dashboard page at the root route (`/`) when the app is opened and OpenRegister is available.

#### Scenario: User opens the app with OpenRegister configured
- **WHEN** a user navigates to the Decidesk app in Nextcloud
- **THEN** the Dashboard page is displayed at `/`

#### Scenario: User opens the app without OpenRegister installed
- **WHEN** a user navigates to the Decidesk app and `openRegisters` is `false` in settings
- **THEN** an `NcEmptyContent` is shown with a message directing the user to install OpenRegister

---

### Requirement: REQ-DSH-002 KPI cards show four governance metrics
The Dashboard SHALL display four `CnStatsBlock` KPI cards: (1) upcoming meetings (lifecycle = scheduled), (2) pending motions (lifecycle = submitted or debating), (3) open action items (taskStatus = open or in-progress), (4) recent decisions (outcome = adopted, last 30 days).

#### Scenario: Counts are populated
- **WHEN** the dashboard loads and OpenRegister contains Meeting, Motion, ActionItem, and Decision objects
- **THEN** each KPI card shows the correct filtered count

#### Scenario: No objects exist yet
- **WHEN** no objects exist in OpenRegister for a given entity type
- **THEN** the corresponding KPI card displays `0` without error

---

### Requirement: REQ-DSH-003 Meeting status distribution chart
The Dashboard SHALL display a `CnChartWidget` (donut or bar) showing the distribution of Meeting objects by lifecycle state: draft, scheduled, opened, adjourned, closed.

#### Scenario: Chart renders with meeting data
- **WHEN** Meeting objects with varying lifecycle values exist
- **THEN** the chart shows each lifecycle state with its count as a labelled segment

#### Scenario: Chart renders with no meetings
- **WHEN** no Meeting objects exist
- **THEN** the chart shows an empty-state message instead of an empty chart

---

### Requirement: REQ-DSH-004 Quick-access navigation tiles
The Dashboard SHALL display `CnTileWidget` tiles for the primary entity types: Meetings, Motions, Decisions, Participants, and Governance Bodies. Each tile SHALL link to the corresponding list view.

#### Scenario: Tile navigation
- **WHEN** a user clicks a tile (e.g. "Vergaderingen")
- **THEN** the router navigates to the corresponding list route (e.g. `/meetings`)

---

### Requirement: REQ-DSH-005 Dashboard data loads in parallel
The Dashboard SHALL fetch all KPI data collections simultaneously using `Promise.all` and SHALL display a loading skeleton until all fetches complete.

#### Scenario: Parallel loading
- **WHEN** the Dashboard component is mounted
- **THEN** all entity stores are queried in parallel, not sequentially

#### Scenario: Loading state shown
- **WHEN** any fetch is in progress
- **THEN** KPI cards display a loading skeleton (`NcLoadingIcon` or skeleton placeholder)

---

### Requirement: REQ-DSH-006 Meeting titles are abbreviated on the dashboard
Long meeting titles SHALL be truncated with an ellipsis (`…`) at 60 characters on dashboard cards and tiles to prevent layout overflow. The full title SHALL be accessible via a tooltip or on the detail page.

#### Scenario: Long title displayed
- **WHEN** a Meeting title exceeds 60 characters
- **THEN** the dashboard shows the first 57 characters followed by `…`

#### Scenario: Full title accessible
- **WHEN** a user hovers or focuses a truncated title
- **THEN** a tooltip displays the full title text
