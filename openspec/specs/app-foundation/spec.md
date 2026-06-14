# app-foundation Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- ia-six-item-nav (active) — updates the MainMenu requirement to ADR-004's six-item IA

## Purpose
TBD - created by archiving change 2026-05-11-p1-crud-operations. Update Purpose after archive.
## Requirements
### Requirement: Register import on install
The app SHALL import the Decidesk OpenRegister register and all entity schemas from `lib/Settings/decidesk_register.json` during installation and upgrades via an `IRepairStep`.

#### Scenario: Fresh install imports register
- **WHEN** the Nextcloud admin installs the Decidesk app for the first time
- **THEN** the repair step runs `ConfigurationService::importFromApp('decidesk')` and all 17 entity schemas are available in OpenRegister

#### Scenario: Upgrade preserves existing data
- **WHEN** the app is upgraded to a new version
- **THEN** the repair step re-imports schemas without deleting existing objects

### Requirement: Seed data loaded on install
The app SHALL load 3–5 Dutch-language example objects per core entity (GovernanceBody, Meeting, Participant, AgendaItem) on first install so the app is usable immediately.

#### Scenario: Seed data available after install
- **WHEN** a fresh install completes
- **THEN** at least 3 GovernanceBody, 4 Meeting, 5 Participant, and 5 AgendaItem objects are present in OpenRegister

#### Scenario: Seed data not duplicated on reinstall
- **WHEN** the repair step runs again on an existing installation
- **THEN** existing seed objects are upserted (not duplicated) based on their deterministic slug

### Requirement: Settings page for administrators
The app SHALL provide an admin settings page that displays the app version, allows register mapping configuration, and provides a re-import button.

#### Scenario: Admin opens settings page
- **WHEN** an admin navigates to the Decidesk settings page
- **THEN** `CnVersionInfoCard` is displayed first, followed by `CnRegisterMapping` and a re-import button

#### Scenario: Re-import register
- **WHEN** the admin clicks the re-import button
- **THEN** the frontend calls `POST /api/settings/load` and the register is re-imported from the JSON file

#### Scenario: Non-admin cannot see settings
- **WHEN** a non-admin user accesses the settings page
- **THEN** the settings page is not accessible and returns HTTP 403

### Requirement: OpenRegister dependency check
The app SHALL detect whether OpenRegister is installed and configured, and display an empty state if it is not.

#### Scenario: OpenRegister missing
- **WHEN** the frontend loads and `openRegisters` is `false` in the settings response
- **THEN** `NcEmptyContent` is shown with a message instructing the admin to install OpenRegister

#### Scenario: OpenRegister present
- **WHEN** the frontend loads and `openRegisters` is `true`
- **THEN** the main app content renders with navigation and routing

### Requirement: App navigation via MainMenu
The app SHALL provide a left-side navigation menu following ADR-004's six-item
information architecture: a Dashboard landing item plus the six canonical working
items — Meetings, Decisions, Action items, Motions, Bodies (the GovernanceBodies
surface), and Beheer (the settings/admin door). The menu SHALL NOT include
Minutes, Workspaces, or Engagement as top-level items; those surfaces are demoted
(Minutes lives as a tab in MeetingDetail, Workspaces under Bodies, Engagement
under Beheer) while their routes remain reachable.

#### Scenario: Navigation renders the six-item IA
- WHEN the app is fully loaded
- THEN the MainMenu shows Dashboard (`/`), Meetings (`/meetings`), Decisions (`/decisions`), Action items (`/action-items`), Motions (`/motions`), and Bodies (`/governance-bodies`)
- AND Beheer (Settings) appears in the settings section
- AND Minutes, Workspaces, and Engagement are NOT shown as top-level menu items

#### Scenario: Active route is highlighted
- WHEN the user is on the Meetings list page
- THEN the Meetings navigation item is styled as active

### Requirement: Dashboard with KPI metrics
The app SHALL display a dashboard page with KPI stats blocks and a meeting lifecycle distribution chart.

#### Scenario: Dashboard loads KPI data
- **WHEN** the user navigates to the Dashboard
- **THEN** `CnStatsBlock` cards display the total count of: GovernanceBody objects, Meeting objects, Participant objects, and upcoming (scheduled) meetings

#### Scenario: Dashboard chart shows meeting lifecycle distribution
- **WHEN** the dashboard data is loaded
- **THEN** a `CnChartWidget` (donut or bar) shows the count of meetings per lifecycle state (draft, scheduled, opened, closed, etc.)

#### Scenario: Dashboard data loads in parallel
- **WHEN** the Dashboard component is created
- **THEN** all entity count requests are issued in parallel via `Promise.all` and the page does not load sequentially

