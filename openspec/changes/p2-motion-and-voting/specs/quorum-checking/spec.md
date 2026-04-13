## ADDED Requirements

### REQ-QRM-001: Verify quorum before opening a voting round
The app SHALL prevent a VotingRound from being opened if the present participant count does not meet the GovernanceBody quorum rule.

#### Scenario: Quorum is met — voting round opens
- **GIVEN** a Motion is in lifecycle `voting`
- **AND** the number of present Participants meets or exceeds `GovernanceBody.quorumRequired`
- **WHEN** the chair user clicks "Open Voting Round"
- **THEN** the VotingRound is opened and `quorumMet` is set to `true`

#### Scenario: Quorum is not met — open is blocked
- **GIVEN** a Motion is in lifecycle `voting`
- **AND** the number of present Participants is below `GovernanceBody.quorumRequired`
- **WHEN** the chair user clicks "Open Voting Round"
- **THEN** the action is blocked and an inline warning is shown: "Quorum niet bereikt: [X] van [Y] leden aanwezig. Minimaal [Y] vereist."

#### Scenario: Quorum check uses present participants from linked meeting
- **GIVEN** the VotingRound is being opened
- **WHEN** the quorum check runs
- **THEN** the present participant count is derived from the Participants linked to the parent Meeting who are marked as present (status: present in the attendance register)

### REQ-QRM-002: Quorum status indicator on motion detail
The app SHALL display a quorum status indicator on the Motion detail page when the motion is in `voting` lifecycle.

#### Scenario: Quorum met indicator shown
- **GIVEN** the Motion detail page is open in `voting` lifecycle
- **WHEN** the present participant count meets or exceeds `GovernanceBody.quorumRequired`
- **THEN** a green `CnStatusBadge` labelled "Quorum bereikt" is shown next to the lifecycle status

#### Scenario: Quorum not met indicator shown
- **GIVEN** the Motion detail page is open in `voting` lifecycle
- **WHEN** the present participant count is below `GovernanceBody.quorumRequired`
- **THEN** a red `CnStatusBadge` labelled "Quorum niet bereikt" is shown and the "Open Voting Round" button is disabled

### REQ-QRM-003: GovernanceBody quorum rule configuration
The app SHALL read the quorum rule from the GovernanceBody object linked to the parent Meeting.

#### Scenario: Quorum rule from GovernanceBody
- **GIVEN** a GovernanceBody has `quorumRequired: 23` (absolute number)
- **WHEN** the quorum check runs for a VotingRound linked to a Meeting of this body
- **THEN** the check compares the present-participant count against the value `23`

#### Scenario: No quorum rule configured
- **GIVEN** a GovernanceBody has `quorumRequired: null` or zero
- **WHEN** the quorum check runs
- **THEN** quorum is assumed to be met and the VotingRound can be opened without restriction; `quorumMet` is set to `true`
