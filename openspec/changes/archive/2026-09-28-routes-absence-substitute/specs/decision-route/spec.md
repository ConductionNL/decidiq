# decision-route Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [routes-absence-substitute](../../) (this delta)

## Purpose

A review can bring in a named substitute part way through a step, but there is no absence period that hands approvals over while someone is away. Moves decidiq matrix rows rou-16 toward built.

## ADDED Requirements

### Requirement: REQ-RAS-001 A substitute approves while someone is away

During a user's absence period (the delegate, Absent from and Absent until of their Decidiq settings), an approval step that becomes live for that user SHALL name their delegate as the step's substitute, who SHALL be able to sign it on their behalf; the absent user SHALL still be able to sign it, and whoever signs first closes the step.

#### Scenario: Pieter approves for Anna
- GIVEN Anna set herself away from 1 to 14 August with Pieter as substitute
- WHEN a proposal reaches Anna's step on 5 August
- THEN the step names Pieter as substitute, Pieter can sign it on behalf of Anna, and both are notified
@e2e exclude the absence is read on the day a step goes live, which a browser run cannot move the clock to; proven by tests/Unit/Service/ApprovalAbsenceSubstituteTest.php testAStepForAnAbsentPersonNamesTheirDelegate

### Requirement: REQ-RAS-002 The server lets a step's substitute act

The approval stage guard SHALL accept an action from the person named as the stage's substitute, as it accepts one from the assigned person, and SHALL refuse anyone else.

#### Scenario: The substitute signs
- GIVEN a live step assigned to Anna with Pieter as substitute
- WHEN Pieter approves it
- THEN the approval is recorded and the step closes, and an approval by Jan, who is neither, is refused
@e2e exclude a server guard on who may act, asserted without a browser; proven by tests/Unit/Service/ApprovalAbsenceSubstituteTest.php testTheStagesSubstituteMayActAndNobodyElse
