---
status: done
---

# real-time-ballot-distribution Specification

## Purpose
Notifies eligible participants when a voting round opens and lets the chair track how many ballots have been cast. Opening a round pushes a deep-linked Nextcloud notification to every active member, a live "invited / voted" counter in the VotingRoundPanel and the round list shows distribution progress without revealing vote values or voter identities, and the chair or secretary can send a reminder to participants who have not yet voted.

## Requirements

### Requirement: REQ-RBD-001 Opening a VotingRound automatically notifies all eligible Participants
When `VotingService::openVotingRound()` succeeds, it SHALL call `NotificationService` to push a Nextcloud notification to every Participant with an active Membership in the GovernanceBody (`startDate` set, `endDate` null or in the future). Each notification SHALL include the motion title, the VotingRound title, and a deep-link to the vote-casting screen in path format (`/apps/decidesk/motions/{motionId}`).

#### Scenario: Chair opens a voting round and participants receive notifications
- **GIVEN** a `GovernanceBody` with 30 active Participants and a Motion in `lifecycle: "debating"`
- **WHEN** the chair opens a VotingRound
- **THEN** `NotificationService` sends 30 notifications within the same request; each notification contains the motion title and a deep-link URL; the `VotingRoundPanel` shows "Uitgenodigd: 30 / Gestemd: 0"

#### Scenario: Inactive Participants (leftAt set) do not receive notifications
- **GIVEN** a GovernanceBody with 28 active and 2 inactive Participants (`endDate` is past)
- **WHEN** a VotingRound is opened
- **THEN** only 28 notifications are sent; the distribution counter shows "Uitgenodigd: 28 / Gestemd: 0"

---

### Requirement: REQ-RBD-002 VotingRoundPanel shows a live distribution progress counter
The `VotingRoundPanel` component SHALL display a "Uitgenodigd: X / Gestemd: Y" counter for open VotingRounds, refreshed every 5 seconds by polling `GET /api/voting-rounds/{id}/distribution`. The endpoint returns `{ "invited": N, "voted": M }` counts without exposing vote values or voter identities.

#### Scenario: Chair monitors ballot distribution progress
- **GIVEN** an open `VotingRound` with 30 eligible Participants and 12 cast votes so far
- **WHEN** the chair views `VotingRoundPanel`
- **THEN** the counter reads "Uitgenodigd: 30 / Gestemd: 12" and updates automatically every 5 seconds

#### Scenario: Distribution endpoint does not expose vote values
- **GIVEN** an open `VotingRound` with `isSecret: true`
- **WHEN** any user calls `GET /api/voting-rounds/{id}/distribution`
- **THEN** the response contains only `{ "invited": N, "voted": M }` — no vote values, no participant names

---

### Requirement: REQ-RBD-003 Chair or secretary can send a repeat notification to non-voters
The `VotingRoundPanel` SHALL provide a "Herinnering sturen" button visible to `chair` and `secretary` roles for open VotingRounds. Clicking it calls `POST /api/voting-rounds/{id}/remind` which sends a Nextcloud notification to every eligible Participant who has not yet cast a vote.

#### Scenario: Secretary reminds participants who haven't voted
- **GIVEN** an open `VotingRound` where 18 of 30 Participants have voted
- **WHEN** the secretary clicks "Herinnering sturen"
- **THEN** `NotificationService` sends a reminder notification to the 12 non-voting Participants; a toast "Herinnering gestuurd aan 12 deelnemers" is shown; the "Herinnering sturen" button is disabled for 60 seconds to prevent spam

#### Scenario: Member cannot trigger a reminder
- **GIVEN** an open `VotingRound`
- **WHEN** a user with role `member` calls `POST /api/voting-rounds/{id}/remind`
- **THEN** the backend returns `403 Forbidden`

---

### Requirement: REQ-RBD-004 Distribution counter is visible in VotingRound list view
The VotingRound list in the admin view SHALL include a "Deelname" column showing the distribution ratio (e.g. "18 / 30") for each open VotingRound, so the chair can monitor participation across multiple concurrent rounds.

#### Scenario: Admin views VotingRound list with open rounds
- **GIVEN** two open VotingRounds with 18/30 and 24/30 participation
- **WHEN** the admin opens the VotingRound list page
- **THEN** the "Deelname" column shows "18 / 30" and "24 / 30" respectively; closed rounds show "30 / 30 (gesloten)"
