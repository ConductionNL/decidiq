# member-voting-behaviour-tracking Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting-core-t2. Update Purpose after archive.

## Requirements

### Requirement: REQ-MVB-001 Vote behaviour statistics are aggregated per Participant across closed VotingRounds
The app SHALL compute per-Participant voting statistics across all closed VotingRounds for a GovernanceBody, including: total rounds eligible, rounds participated, participation rate (%), votes for/against/abstain counts, and proxy votes cast or received.

#### Scenario: Raadslid views their own voting history
- **GIVEN** a Participant who was eligible for 40 VotingRounds and participated in 38
- **WHEN** the user opens `MemberVotingHistoryView.vue` for their own profile
- **THEN** the view shows: Deelname 38/40 (95%), Voor 24, Tegen 10, Onthouding 4, Volmacht gegeven 2, Volmacht ontvangen 1

#### Scenario: Chair views another member's voting history
- **GIVEN** a user with role `chair` viewing the voting history of Participant "F. el-Amrani"
- **WHEN** the chair opens the history view
- **THEN** the full statistics are displayed; no data is hidden from the chair role

---

### Requirement: REQ-MVB-002 Voting history is accessible via a dedicated route and API endpoint
The app SHALL expose voting behaviour data at `GET /api/voting-behaviour/{participantId}` and render it at the frontend route `/members/:id/voting-history`.

#### Scenario: API returns aggregated stats for a participant
- **GIVEN** a GET request to `/api/voting-behaviour/{participantId}?governanceBodyId={bodyId}`
- **WHEN** the request is authenticated and the participantId exists
- **THEN** the response is 200 with body `{ "participantId": "...", "governanceBodyId": "...", "totalRounds": 40, "participated": 38, "participationRate": 0.95, "votesFor": 24, "votesAgainst": 10, "votesAbstain": 4, "proxiesGiven": 2, "proxiesReceived": 1 }`

#### Scenario: 403 returned when non-admin requests another member's stats
- **GIVEN** a user with role `member` who is NOT the subject participant
- **WHEN** the user calls `GET /api/voting-behaviour/{otherParticipantId}`
- **THEN** the API returns `403 Forbidden` with body `{ "message": "Access denied" }`

---

### Requirement: REQ-MVB-003 Voting behaviour is visualised with a chart and detail table
The app SHALL render `MemberVotingHistoryView.vue` with a donut chart (Voor/Tegen/Onthouding distribution) via `CnChartWidget` and a table of individual VotingRound entries (motion title, date, vote cast, proxy flag).

#### Scenario: User sees donut chart of vote distribution
- **GIVEN** a Participant with votesFor 24, votesAgainst 10, votesAbstain 4
- **WHEN** the user views their voting history page
- **THEN** a donut chart shows three segments labelled "Voor (24)", "Tegen (10)", "Onthouding (4)" with percentage annotations; chart is rendered via `CnChartWidget` (ApexCharts)

#### Scenario: User sees tabular voting history with pagination
- **GIVEN** a Participant with entries in 40 VotingRounds
- **WHEN** the user scrolls the history table
- **THEN** entries are paginated (25 per page) via `CnPagination`; each row shows motion title (link to MotionDetail), VotingRound date, vote cast, and a "Volmacht" badge if the vote was a proxy

---

### Requirement: REQ-MVB-004 Voting behaviour stats are exportable
The app SHALL allow a user to export their voting history as CSV or JSON via `CnMassExportDialog`.

#### Scenario: User exports their voting history as CSV
- **GIVEN** a Participant viewing their voting history
- **WHEN** the user clicks "Exporteren" and selects CSV
- **THEN** `ExportService` generates a CSV with columns: round_date, motion_title, motion_type, vote_value, is_proxy, delegator_name; the file is downloaded immediately
