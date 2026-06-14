# Spec: Voting System

## ADDED Requirements

### Requirement: VotingRound resolves a DecisionStage of method vote

A `VotingRound` SHALL be relatable to the `DecisionStage` it resolves, so that a `method=vote` stage derives its outcome from the round. The `VotingRound` relation that previously targeted the retired `motion` schema (ADR-005) SHALL be retargeted onto `DecisionStage`. Vote sub-variants — anonymous/secret ballot, personal, general, roll-call, show-of-hands — SHALL continue to be expressed via the existing `VotingRound.isSecret` and `VotingRound.votingMethod` fields and SHALL NOT be promoted to `DecisionStage.method` enum values.

#### Scenario: A voting round is linked to the stage it resolves

- **GIVEN** a `method=vote` DecisionStage and a `VotingRound` with `result=adopted`
- **WHEN** the stage references the round via `votingRound`
- **THEN** the round resolves that stage and the stage outcome derives from `VotingRound.result`

#### Scenario: Secret ballot sub-variant is carried by the round

- **GIVEN** a `VotingRound` with `isSecret=true` linked to a `method=vote` stage
- **WHEN** the ballot is configured
- **THEN** the anonymous/secret nature is carried by `VotingRound.isSecret`, not by a distinct stage method value
