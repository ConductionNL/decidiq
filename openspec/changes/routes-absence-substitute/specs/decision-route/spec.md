# decision-route Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [routes-absence-substitute](../../) (this delta)

## Purpose

A review can bring in a named substitute part way through a step, but there is no absence period that hands approvals over while someone is away. Moves decidiq matrix rows rou-16 toward built.

## ADDED Requirements

### Requirement: REQ-RAS-001 A substitute approves while someone is away

During a user's absence period, new approval steps for that user SHALL go to their substitute, marked as on their behalf.

#### Scenario: Pieter approves for Anna
- GIVEN Anna set herself away from 1 to 14 August with Pieter as substitute
- WHEN a proposal reaches Anna's step on 5 August
- THEN Pieter is asked to approve on behalf of Anna and Anna is told
