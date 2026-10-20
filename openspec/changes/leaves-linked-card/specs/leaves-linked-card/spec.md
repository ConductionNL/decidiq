# leaves-linked-card Delta

**Status**: proposed
**Scope**: decidiq

## ADDED Requirements

### Requirement: The decision leaves report a summary to the linked card

Given `onSummary`, the decisions and approval-chain leaves SHALL draw nothing and SHALL report `{ count, noun, rows,
actions }` once read, and nothing while loading or after a failed read.

#### Scenario: a case with two decisions

- **GIVEN** a case with two linked decisions
- **THEN** the decisions leaf reports count 2 and a row per decision linking to it in decidiq
