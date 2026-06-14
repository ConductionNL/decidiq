# vote-casting Specification

## ADDED Requirements

### Requirement: Votes cast in a stage-linked round feed the stage outcome

When a `VotingRound` resolves a `DecisionStage` of `method=vote`, the `Vote` objects cast in that round SHALL tally into `VotingRound.result` exactly as for any round, and that result SHALL be the single source from which the stage `outcome` is derived. Casting, tallying, and ballot configuration are unchanged by the `decision-methods` capability; only the round's linkage to a stage is added.

#### Scenario: Casting votes resolves the linked stage

- **GIVEN** a `method=vote` DecisionStage linked to an open VotingRound
- **WHEN** participants cast their votes and the round closes with `result=adopted`
- **THEN** the stage outcome derives to `adopted` from the round with no separate per-stage tally
