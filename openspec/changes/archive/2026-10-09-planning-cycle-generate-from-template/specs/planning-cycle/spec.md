# planning-cycle Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [planning-cycle-generate-from-template](../../) (this delta)

## Purpose

A yearly planning and control cycle and its steps can be recorded by hand, but a cycle made from a template stays empty. Moves decidiq matrix rows pla-12 toward built.

## ADDED Requirements

### Requirement: REQ-PCG-001 A cycle made from a template gets its steps

Creating a planning cycle with a template SHALL create its steps in order with dates in the cycle year, once.

#### Scenario: cycle 2027 from the municipal template
- GIVEN the template holds kadernota, begroting and jaarrekening
- WHEN the controller creates cycle 2027 with that template
- THEN the cycle page lists the three steps in that order with 2027 deadlines

#### Scenario: an overdue step is marked
- GIVEN the begroting step deadline has passed and it is not done
- WHEN the controller opens the cycle page
- THEN the step shows Overdue
