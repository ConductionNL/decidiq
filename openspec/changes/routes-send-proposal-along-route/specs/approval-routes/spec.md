# approval-routes Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [routes-send-proposal-along-route](../../) (this delta)

## Purpose

The approval route engine works and approvers can act, but no decidiq screen sends a proposal along a route: it takes the API or another app. Moves decidiq matrix rows rou-01 toward built.

## ADDED Requirements

### Requirement: REQ-RSPR-001 A proposal is sent along a route from its page

The owner SHALL be able to start an active approval route on a decision from its page and see its progress there.

#### Scenario: sending a proposal
- GIVEN proposal Parkeerbeleid by Anna and an active route Wethouder then Secretaris
- WHEN Anna presses Send for approval and picks that route
- THEN the page shows the route with Wethouder as the open step

#### Scenario: not on the agenda before approval
- GIVEN the route of Parkeerbeleid has an open step
- WHEN the griffier adds it to a meeting agenda
- THEN it is refused with a message naming the open step
