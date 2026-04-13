## ADDED Requirements

### REQ-VCT-001: Cast a vote in an open round
The app SHALL allow each Participant to cast exactly one Vote in an open VotingRound.

#### Scenario: Participant casts a for/against/abstain vote
- **GIVEN** a VotingRound is open (openedAt set, closedAt null) with `votingMethod: for-against-abstain`
- **WHEN** a Participant selects their vote value and clicks "Cast Vote"
- **THEN** a new Vote object is created with the chosen value, the Participant relation, the VotingRound relation, and `castAt` set to the current timestamp

#### Scenario: Participant cannot vote twice
- **GIVEN** a Participant has already cast a Vote in the VotingRound
- **WHEN** the Participant opens the voting interface again
- **THEN** the vote controls are disabled and the previously cast vote is shown as confirmed

#### Scenario: Participant cannot vote in a closed round
- **GIVEN** a VotingRound has `closedAt` set
- **WHEN** any user opens the voting interface
- **THEN** the vote controls are absent; only the result tally is displayed

### REQ-VCT-002: Ranked-choice vote casting
The app SHALL allow Participants to cast ranked preference votes when the VotingRound uses `votingMethod: ranked-choice`.

#### Scenario: Participant ranks options
- **GIVEN** a VotingRound is open with `votingMethod: ranked-choice`
- **WHEN** the Participant views the ballot
- **THEN** a drag-and-drop ranking interface is displayed with each candidate option

#### Scenario: Ranked vote is saved
- **GIVEN** the Participant has set their ranking
- **WHEN** the Participant clicks "Cast Vote"
- **THEN** a Vote object is created with `value` containing the ranked order (comma-separated or JSON array) and `castAt` set

#### Scenario: WCAG AA accessibility for ranking interface
- **GIVEN** the ranked-choice ballot is displayed
- **WHEN** the Participant uses keyboard navigation only
- **THEN** options can be reordered via keyboard (arrow keys + Enter) without requiring mouse drag

### REQ-VCT-003: Weighted vote casting
The app SHALL apply participant voting weights when the VotingRound uses `votingMethod: weighted`.

#### Scenario: Vote weight applied from Participant record
- **GIVEN** a VotingRound is open with `votingMethod: weighted`
- **WHEN** a Participant casts a vote
- **THEN** the Vote object is created with `weight` equal to `Participant.votingWeight`

#### Scenario: Weighted tally calculation
- **GIVEN** Votes with varying weights are cast in a weighted VotingRound
- **WHEN** the tally is computed
- **THEN** `votesFor`, `votesAgainst`, and `votesAbstain` reflect the sum of weights, not the count of votes

### REQ-VCT-004: Vote confirmation receipt
The app SHALL confirm to the Participant that their vote has been recorded.

#### Scenario: Confirmation shown after casting
- **GIVEN** the Participant has successfully cast a Vote
- **WHEN** the save completes
- **THEN** a success notification is shown via Nextcloud `NotificationService` with the vote value and VotingRound title

#### Scenario: Audit trail entry created
- **GIVEN** a Vote object is saved
- **WHEN** the save completes
- **THEN** an Activity entry is created automatically by AuditTrailService with actor, action (vote-cast), and timestamp

### REQ-VCT-005: Vote tally auto-refresh during open round
The app SHALL refresh the running vote tally automatically while a VotingRound is open.

#### Scenario: Tally refreshes every 5 seconds
- **GIVEN** the VotingRound detail view is open and the round is not yet closed
- **WHEN** 5 seconds elapse
- **THEN** the Vote list is re-fetched from OpenRegister and the tally counters (votesFor, votesAgainst, votesAbstain) are recalculated from the loaded Vote objects
