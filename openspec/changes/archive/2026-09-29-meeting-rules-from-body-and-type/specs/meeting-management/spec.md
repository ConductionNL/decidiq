# meeting-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [meeting-rules-from-body-and-type](../../) (this delta)

## Purpose

A body's rules (quorum, majority, voting method) and a meeting type's defaults can be set, but meetings and votes do not follow them. Moves decidiq matrix rows bod-14, pla-11 toward built.

## ADDED Requirements

### Requirement: REQ-MRB-001 A new meeting takes its type and body defaults

When a meeting is created, fields left empty SHALL be filled from its meeting type and then from its governance body.

#### Scenario: The clerk plans a committee meeting
- GIVEN meeting type Commissie sets 90 minutes and quorum 5
- WHEN the clerk creates a meeting of type Commissie without entering either
- THEN the meeting page shows 90 minutes and quorum 5

### Requirement: REQ-MRB-002 Votes follow the body rules

Opening a voting round SHALL use the governance body's voting rule and quorum rule, not fixed values.

#### Scenario: A two-thirds vote
- GIVEN the supervisory board requires a two-thirds majority
- WHEN the chair opens a voting round on a motion of that board
- THEN the round shows the two-thirds rule and decides by it
