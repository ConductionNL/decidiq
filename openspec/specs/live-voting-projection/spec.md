# live-voting-projection Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting-core-t2. Update Purpose after archive.

## Requirements

### Requirement: REQ-LVP-001 A public fullscreen projection route displays the live VotingRound state
The app SHALL expose a fullscreen route `/apps/decidesk/projection/{votingRoundId}` accessible without Nextcloud login. The route renders `ProjectionView.vue` showing: motion title, voting method, aggregate vote counts, and a preselected option tile when one option leads.

#### Scenario: Projector screen displays live vote totals
- **GIVEN** an open VotingRound linked to the motion "Motie Duurzame Energie"
- **WHEN** the projector URL `/apps/decidesk/projection/{id}` is opened on a device not logged in to Nextcloud
- **THEN** the page renders in fullscreen with the motion title, vote counts (Voor: 15, Tegen: 4, Onthouding: 1), and the elapsed time since `openedAt`; no individual Participant names are shown

#### Scenario: Projection view refreshes every 3 seconds without requiring login
- **GIVEN** the projection page on a standalone screen
- **WHEN** new votes are cast by participants
- **THEN** the displayed counts update within 3 seconds; no authentication prompt appears

---

### Requirement: REQ-LVP-002 The leading option tile is preselected in the projection dialog
The app SHALL visually preselect the currently leading vote option (Voor/Tegen/Onthouding) in `ProjectionView.vue` when one option has strictly more votes than the others. When tied, no tile is preselected.

#### Scenario: "Voor" tile is highlighted when it leads
- **GIVEN** a VotingRound with Voor: 18, Tegen: 5, Onthouding: 2
- **WHEN** the projection view polls for the latest state
- **THEN** the "Voor" tile is rendered with the `--color-primary-element` highlight token and a visual indicator (enlarged border or checkmark icon); "Tegen" and "Onthouding" tiles are rendered in neutral style

#### Scenario: No tile is preselected when tied
- **GIVEN** a VotingRound with Voor: 12, Tegen: 12, Onthouding: 1
- **WHEN** the projection view renders
- **THEN** all three tiles are rendered in neutral style with no preselection; a subtle "Gelijkstand" label appears below the counts

#### Scenario: Preselection does not reveal individual vote identity
- **GIVEN** a projection view with Voor: 18 and preselection active
- **WHEN** a new vote is added changing the tally
- **THEN** only aggregate counts and the new preselection state are updated — no Participant name, UID, or individual vote value is transmitted to or rendered on the projection view

---

### Requirement: REQ-LVP-003 The projection public-state API returns only aggregate data
The app SHALL provide a `GET /api/voting-rounds/{id}/public-state` endpoint annotated `#[PublicPage]` that returns aggregate vote counts and the preselection flag. It SHALL NOT return individual Vote objects, Participant identifiers, or `Vote.value` fields.

#### Scenario: Public state endpoint returns correct payload
- **GIVEN** a GET to `/api/voting-rounds/{id}/public-state`
- **WHEN** the request is unauthenticated
- **THEN** the response is 200 with body `{ "motionTitle": "...", "votingMethod": "for-against-abstain", "isOpen": true, "votesFor": 18, "votesAgainst": 5, "votesAbstain": 2, "preselectedOption": "for", "openedAt": "2025-04-15T14:20:00+02:00" }`

#### Scenario: Public state endpoint returns 404 for unknown round
- **GIVEN** a GET to `/api/voting-rounds/nonexistent-id/public-state`
- **WHEN** the request is made
- **THEN** the response is 404 with `{ "message": "VotingRound not found" }`

---

### Requirement: REQ-LVP-004 The projection link is copyable from the VotingRound panel
The app SHALL display a "Projectielink kopiëren" button in `VotingRoundPanel.vue` for users with role `chair` or `secretary` when a VotingRound is open. Clicking the button copies the full projection URL to the clipboard.

#### Scenario: Chair copies projection URL during voting
- **GIVEN** an open VotingRound and the chair is viewing `VotingRoundPanel.vue`
- **WHEN** the chair clicks "Projectielink kopiëren"
- **THEN** the URL `/apps/decidesk/projection/{votingRoundId}` is written to the system clipboard and a transient "Link gekopieerd" toast notification appears
