# voting-result-publication Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting. Update Purpose after archive.

## Requirements

### Requirement: REQ-RES-001 Voting results are displayed immediately after round closes
The app SHALL display the full vote tally (votesFor, votesAgainst, votesAbstain, result, majority threshold) to all users as soon as a VotingRound closes. The result panel SHALL show whether the required majority was reached.

#### Scenario: Results are shown after round closes
- **GIVEN** a VotingRound that has just been closed with `result: "adopted"`
- **WHEN** any user views the VotingRoundPanel or MotionDetail
- **THEN** the panel displays: "Aangenomen — Voor: 23, Tegen: 8, Onthouding: 1" with a majority threshold indicator showing "Vereist: 17 (meerderheid van 32 aanwezige leden)"

#### Scenario: Secret ballot results show totals only
- **GIVEN** a VotingRound with `isSecret: true` that has been closed
- **WHEN** any user views the results
- **THEN** the totals (for/against/abstain) are shown but individual Participant votes are NOT revealed — individual Vote objects remain inaccessible to non-admin users

---

### Requirement: REQ-RES-002 Results are published to the ORI API on demand
The app SHALL allow users with role `chair` or `secretary` to publish VotingRound results to the configured ORI API endpoint. Publication calls `OriPublicationService` which sends a JSON-LD payload following the ORI 1.0 standard.

#### Scenario: Secretary publishes results to ORI
- **GIVEN** a closed VotingRound with `result: "adopted"`
- **WHEN** the secretary clicks "Publiceren naar ORI"
- **THEN** `OriPublicationService.publish()` sends a `POST` request to the configured ORI endpoint with the VotingRound result as JSON-LD, and the VotingRound status badge updates to "Gepubliceerd"

#### Scenario: ORI endpoint is unreachable — retry queued
- **GIVEN** the ORI endpoint returns a network error or `5xx`
- **WHEN** `OriPublicationService.publish()` fails
- **THEN** a background retry job (`IJob`) is queued with exponential backoff AND the secretary sees "Publicatie in behandeling" in the UI

#### Scenario: ORI publication is skipped when endpoint not configured
- **GIVEN** no ORI endpoint URL is configured in app settings
- **WHEN** the secretary opens the VotingRoundPanel
- **THEN** the "Publiceren naar ORI" button is hidden and a notice "ORI-eindpunt niet geconfigureerd" is shown in the settings panel

---

### Requirement: REQ-RES-003 Adopted motion triggers dossier folder creation
The app SHALL automatically create a structured Nextcloud Files folder for a Motion when its result is `adopted`. The folder SHALL be linked to the Motion via `_files` metadata and SHALL be pre-populated with the motion text and vote result.

#### Scenario: Adopted motion gets a dossier folder
- **GIVEN** a VotingRound that closes with `result: "adopted"`
- **WHEN** `VotingService::closeVotingRound()` records the result
- **THEN** `FileService.createFolder()` creates a folder at `motions/{motion-slug}/` linked to the Motion via `_files` AND a `stemresultaat.json` file is written with the VotingRound result summary

#### Scenario: Rejected motion does not get a folder
- **GIVEN** a VotingRound that closes with `result: "rejected"`
- **WHEN** `VotingService::closeVotingRound()` records the result
- **THEN** no folder is created for the Motion

---

### Requirement: REQ-RES-004 Complete audit trail for all motion and vote events
The app SHALL log every state-changing action on a Motion, Amendment, VotingRound, or Vote to the Nextcloud Activity stream via `ActivityService`. The audit trail SHALL include actor, action, timestamp, and before/after lifecycle state.

#### Scenario: Vote cast is logged in Activity stream
- **GIVEN** a Participant casts a vote in a VotingRound
- **WHEN** `VotingService::castVote()` saves the Vote object
- **THEN** an Activity entry is created: `{ actor: "J. van der Berg", action: "vote_cast", object: "VotingRound [title]", timestamp: "...", value: "for" }` — visible in the Motion's Activity tab

#### Scenario: Audit trail is filterable by Motion
- **GIVEN** an auditor opens the CnObjectSidebar Audit Trail tab on a MotionDetail page
- **WHEN** the audit trail tab loads
- **THEN** all Activity entries for the Motion — and related VotingRounds and Votes — are shown in reverse-chronological order AND an "Exporteren" action is available via `CnMassExportDialog`

---

### Requirement: REQ-RES-005 Voting history is searchable across all motions
The app SHALL provide full-text search and filter across all Motions and VotingRounds via the OpenRegister `IndexService`. Users SHALL be able to search by motion title, proposer, lifecycle, vote result, and date range.

#### Scenario: User searches voting history by topic
- **GIVEN** the Motion index page with a `CnFilterBar`
- **WHEN** the user types "duurzame energie" in the search box
- **THEN** all Motions with matching title or text are returned, ordered by relevance, with lifecycle and result badges on each row

#### Scenario: Filter by vote result
- **GIVEN** the Motion index page
- **WHEN** the user selects `result: "adopted"` in the filter sidebar (`CnFacetSidebar`)
- **THEN** only Motions whose most recent VotingRound has `result: "adopted"` are shown in the results
