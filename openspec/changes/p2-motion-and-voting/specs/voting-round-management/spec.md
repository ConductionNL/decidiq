## ADDED Requirements

### REQ-VRM-001: Open a voting round
The app SHALL allow authorised users (chair, secretary) to open a VotingRound on a Motion or Amendment, subject to quorum being met.

#### Scenario: Chair opens a voting round
- **GIVEN** a Motion is in lifecycle `voting`
- **WHEN** the chair user clicks "Open Voting Round" on the motion detail page
- **THEN** a `CnFormDialog` opens to configure VotingRound properties (votingMethod, isSecret)

#### Scenario: VotingRound is created and opened
- **GIVEN** the VotingRound form is filled in and quorum is met
- **WHEN** the user clicks save
- **THEN** a new VotingRound object is created with `openedAt` set to the current timestamp, linked to the Motion via OpenRegister relation

#### Scenario: Voting method selection
- **GIVEN** the VotingRound creation form is open
- **WHEN** the user selects a voting method
- **THEN** the available options are: `for-against-abstain`, `ranked-choice`, `weighted`, `show-of-hands`

### REQ-VRM-002: Close a voting round
The app SHALL allow authorised users to close an open VotingRound and compute the final result.

#### Scenario: Chair closes a voting round
- **GIVEN** a VotingRound has `openedAt` set and `closedAt` is null
- **WHEN** the chair user clicks "Close Voting Round"
- **THEN** `closedAt` is set to the current timestamp and `result` is calculated (adopted/rejected/tied/invalid)

#### Scenario: Result is calculated on close
- **GIVEN** the VotingRound is being closed
- **WHEN** the close action executes
- **THEN** `result` is set to `adopted` if `votesFor > votesAgainst`, `rejected` if `votesFor < votesAgainst`, `tied` if equal, `invalid` if quorum was not met

#### Scenario: Motion lifecycle updated on VotingRound close
- **GIVEN** a VotingRound linked to a Motion is closed with `result: adopted`
- **WHEN** the close action completes
- **THEN** the linked Motion lifecycle is updated to `adopted`

#### Scenario: Motion rejected on close
- **GIVEN** a VotingRound linked to a Motion is closed with `result: rejected`
- **WHEN** the close action completes
- **THEN** the linked Motion lifecycle is updated to `rejected`

### REQ-VRM-003: View voting round detail
The app SHALL display a detail view for a VotingRound showing method, tally, and all cast votes.

#### Scenario: User views voting round detail
- **GIVEN** the user clicks a VotingRound from the Motion detail page
- **WHEN** the VotingRound detail view renders
- **THEN** the view shows votingMethod, isSecret, openedAt, closedAt, quorumMet, result, votesFor, votesAgainst, votesAbstain

#### Scenario: Running tally is shown for open rounds
- **GIVEN** the VotingRound is open (closedAt is null)
- **WHEN** the detail view is displayed
- **THEN** a live tally panel shows the current count of for/against/abstain votes, refreshed every 5 seconds

#### Scenario: Per-participant votes shown for non-secret ballot
- **GIVEN** the VotingRound has `isSecret: false` and `closedAt` is set
- **WHEN** the result section is displayed
- **THEN** a table lists each Participant's name and their vote value

#### Scenario: Secret ballot hides individual votes
- **GIVEN** the VotingRound has `isSecret: true`
- **WHEN** the result section is displayed
- **THEN** only aggregate totals (votesFor, votesAgainst, votesAbstain) are shown; no per-participant breakdown

### REQ-VRM-004: Voting round tally widget
The app SHALL display a tally chart on the VotingRound detail page for quick result visualisation.

#### Scenario: Donut chart shows vote distribution
- **GIVEN** a VotingRound has votes cast
- **WHEN** the detail page renders
- **THEN** a `CnChartWidget` (donut) displays the proportion of for/against/abstain votes

#### Scenario: Majority threshold indicator
- **GIVEN** the VotingRound detail is displayed
- **WHEN** the votes are loaded
- **THEN** a majority threshold indicator shows whether the "for" votes exceed 50% (simple majority) or the GovernanceBody-configured threshold
