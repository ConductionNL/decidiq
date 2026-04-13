## ADDED Requirements

### REQ-PRX-001: Delegate voting rights to another participant
The app SHALL allow a Participant to delegate their voting rights to another Participant for a specific VotingRound.

#### Scenario: Participant delegates proxy
- **GIVEN** a VotingRound is open
- **AND** the Participant has not yet cast their own vote
- **WHEN** the Participant selects "Stemmen via volmacht" and picks a delegate Participant
- **THEN** a proxy delegation record is stored as a Note on the VotingRound via `ObjectService` with the delegating Participant, the delegate Participant, and the timestamp

#### Scenario: Delegate can cast proxy vote
- **GIVEN** a proxy delegation exists for Participant A delegating to Participant B
- **WHEN** Participant B opens the voting interface
- **THEN** the interface shows both Participant B's own vote and an additional "Namens [A]" vote control

#### Scenario: Proxy vote is flagged
- **GIVEN** Participant B casts a vote on behalf of Participant A
- **WHEN** the Vote object is created
- **THEN** `Vote.isProxy` is set to `true` and the Participant relation points to Participant B (the proxy holder)

### REQ-PRX-002: One-time proxy delegation per voting round
The app SHALL ensure that each Participant can only delegate their vote once per VotingRound.

#### Scenario: Double delegation is blocked
- **GIVEN** Participant A has already delegated their vote to Participant B in a VotingRound
- **WHEN** Participant A attempts to delegate again in the same VotingRound
- **THEN** the action is blocked with an error: "U heeft uw stemrecht al gedelegeerd in deze stemronde."

#### Scenario: Participant who delegated cannot also cast own vote
- **GIVEN** Participant A has delegated their vote to Participant B
- **WHEN** Participant A opens the voting interface
- **THEN** the vote controls for Participant A are disabled and a notice is shown: "Uw stemrecht is gedelegeerd aan [B]."

### REQ-PRX-003: Proxy delegation audit trail
The app SHALL log all proxy delegations in the audit trail.

#### Scenario: Delegation logged in activity stream
- **GIVEN** a proxy delegation is created
- **WHEN** the delegation note is saved
- **THEN** an Activity entry is created with actor (Participant A), action (proxy-delegated), delegate (Participant B), and timestamp

#### Scenario: Proxy votes visible in non-secret ballot results
- **GIVEN** a VotingRound has `isSecret: false` and has been closed
- **WHEN** the result section is displayed
- **THEN** proxy votes are marked with a "volmacht" label in the per-participant vote table

### REQ-PRX-004: Proxy voting in bulk (institutional investor use case)
The app SHALL allow an authorised user (e.g., secretary) to register proxy votes for multiple absent Participants before or during a VotingRound.

#### Scenario: Secretary registers bulk proxy votes
- **GIVEN** a VotingRound is open
- **WHEN** the secretary user selects multiple absent Participants and assigns their proxy votes (for/against/abstain)
- **THEN** Vote objects are created for each selected Participant with `isProxy: true` and the secretary as the proxy holder
