# Spec: Decision Route

## ADDED Requirements

### Requirement: DecisionStage mechanism relations and resolved method

The `DecisionStage.method` placeholder introduced by `decision-route-and-stages` SHALL be made real by the `decision-methods` capability. `DecisionStage` SHALL gain three optional typed relations — `votingRound` (→ VotingRound), `registeredBy` (→ Person), `signedDocument` (→ DigitalDocument) — and the `method` enum value `sign` SHALL be renamed to `signature`. A stage with `method=manual` or `method=advice` SHALL remain valid with no mechanism relation, preserving the C4 "stage with no mechanism is valid" property. The full method semantics (outcome derivation per method, the required-relation integrity rule, signature resolution) are owned by the `decision-methods` capability; this delta only records that the route's stages now carry the mechanism relations.

#### Scenario: A C4 route stage gains a resolution mechanism

- **GIVEN** a route whose decisive stage had `method=vote` as a placeholder under C4
- **WHEN** the `decision-methods` capability is applied
- **THEN** that stage carries a `votingRound` relation to the round that resolves it, and its `outcome` derives from the round

#### Scenario: The sign method value is renamed to signature

- **GIVEN** a C4 DecisionStage seed that used `method=sign`
- **WHEN** the `decision-methods` capability is applied
- **THEN** that stage uses `method=signature` and the `sign` value no longer appears in the enum
