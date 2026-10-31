# conflict-of-interest Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-conflict-of-interest-recusal](../../) (this delta)

## Purpose

A member can declare a conflict of interest only through the API, and nothing in the voting path reads it. Moves decidiq matrix rows bod-10 toward built.

## ADDED Requirements

### Requirement: REQ-COIR-001 Declare a conflict of interest from the page

A member SHALL be able to declare a conflict of interest on an agenda item or motion from its page, and the page SHALL list active declarations.

#### Scenario: A member declares a conflict
- GIVEN council member Anna owns land in the plan area of motion M-12
- WHEN she opens the motion page and declares a conflict with that reason
- THEN the motion page lists her declaration

### Requirement: REQ-COIR-002 A recused member cannot vote on the matter

The vote path SHALL refuse a ballot from a member with an active recusal on the motion or its agenda item, and SHALL leave her out of the eligible count.

#### Scenario: Anna cannot vote
- GIVEN Anna has an active recusal on motion M-12
- WHEN she tries to cast a vote in the open round on M-12
- THEN the vote is refused with a message naming her declaration and the round shows one eligible voter fewer
