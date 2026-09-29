# motion-and-voting Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [voting-results-by-faction-and-member](../../) (this delta)

## Purpose

The votes widgets show round totals only: the voter column reads a field ballots never carry, and there is no breakdown per faction. Moves decidiq matrix rows vot-03 toward built.

## ADDED Requirements

### Requirement: REQ-VRF-001 Results per faction and per member

An open vote's result SHALL be shown per member by name and per faction; a secret vote SHALL show totals only.

#### Scenario: The council sees how factions voted
- GIVEN an open vote on motion M-12 closed with 20 for and 9 against
- WHEN member Pieter opens the motion page
- THEN he sees each member's vote by name and a line per faction with its counts
